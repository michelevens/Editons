<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\BookExport;
use App\Models\ManuscriptSection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use ZipArchive;

/**
 * Generates a book file.
 *
 * Each format is produced for real, not stubbed: an author clicks Generate to
 * get something they can upload to a store, and a placeholder file that opens
 * to nothing wastes a submission cycle. EPUB is therefore a proper container
 * with a manifest and a spine, not a renamed HTML file.
 *
 * Files are written to the private disk. They are the author's manuscript
 * before publication, so they are served through a controller that checks
 * authorisation rather than from a public URL anyone holding the path can read.
 */
class Exporter
{
    public function __construct(private readonly DocumentRenderer $renderer) {}

    /**
     * Generate one file and record it.
     *
     * The row is created as `generating` first, so a format that throws
     * halfway leaves a visible failure rather than a silent absence.
     */
    public function generate(Book $book, string $format, ?int $userId = null): BookExport
    {
        if (! isset(BookExport::FORMATS[$format])) {
            throw new RuntimeException("There is no {$format} export.");
        }

        $sections = $this->chapters($book);

        if ($sections->isEmpty()) {
            throw new RuntimeException('There is nothing to export yet. Write a chapter first.');
        }

        $export = $book->exports()->create([
            'format' => $format,
            'status' => 'generating',
            'word_count' => $book->word_count,
            'user_id' => $userId,
        ]);

        try {
            $path = match ($format) {
                'epub' => $this->epub($book, $sections, $export),
                'pdf_print' => $this->printable($book, $sections, $export),
                'docx' => $this->docx($book, $sections, $export),
                'md' => $this->markdown($book, $sections, $export),
                'html' => $this->singleHtml($book, $sections, $export),
            };

            $export->update([
                'status' => 'ready',
                'path' => $path,
                'byte_size' => Storage::disk('local')->size($path),
            ]);
        } catch (\Throwable $e) {
            // Recorded, not swallowed: the history should show that a
            // generation was attempted and failed, and the exception still
            // reaches the caller so the author sees why.
            $export->update(['status' => 'failed']);

            throw $e;
        }

        return $export->fresh();
    }

    /**
     * Chapters in reading order, parts excluded.
     *
     * A part is a container holding chapters, so exporting it would emit its
     * title twice — once as the part and once as the chapter beneath it.
     *
     * @return \Illuminate\Support\Collection<int, ManuscriptSection>
     */
    private function chapters(Book $book)
    {
        return $book->sections()
            ->orderBy('position')
            ->get()
            ->reject(fn (ManuscriptSection $section) => $section->isContainer())
            ->filter(fn (ManuscriptSection $section) => trim($section->plainText()) !== '')
            ->values();
    }

    /**
     * A chapter's markup, with its title as the opening heading.
     *
     * The title is only added when the prose does not already open with a
     * level-1 heading of its own. Authors commonly type the chapter title as
     * the first line, and emitting both gives two H1s in one document — which
     * store validators flag and screen readers announce twice.
     */
    private function chapterHtml(ManuscriptSection $section): string
    {
        $html = $this->renderer->html($section);

        return $this->opensWithHeading($section)
            ? $html
            : '<h1>'.e($section->title).'</h1>'."\n".$html;
    }

    private function opensWithHeading(ManuscriptSection $section): bool
    {
        foreach ($section->content['content'] ?? [] as $node) {
            if (! is_array($node)) {
                continue;
            }

            // The first block that carries text decides it; an empty
            // paragraph above the title should not count as the opening.
            if (trim($this->plainOf($node)) === '') {
                continue;
            }

            return ($node['type'] ?? '') === 'heading'
                && (int) ($node['attrs']['level'] ?? 2) <= 2;
        }

        return false;
    }

    /** @param array<string, mixed> $node */
    private function plainOf(array $node): string
    {
        $out = (string) ($node['text'] ?? '');

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $out .= $this->plainOf($child);
            }
        }

        return $out;
    }

    private function directory(Book $book): string
    {
        return "exports/book-{$book->id}";
    }

    private function filename(Book $book, BookExport $export): string
    {
        $slug = Str::slug($book->title) ?: 'book';

        // The export id is in the name so regenerating never overwrites an
        // earlier file. Export history promises the old file is still there.
        return "{$slug}-{$export->id}.".$export->extension();
    }

    /** @param \Illuminate\Support\Collection<int, ManuscriptSection> $sections */
    private function markdown(Book $book, $sections, BookExport $export): string
    {
        $parts = ["# {$book->title}"];

        if ($book->subtitle) {
            $parts[] = "## {$book->subtitle}";
        }

        if ($book->author_name) {
            $parts[] = "by {$book->author_name}";
        }

        foreach ($sections as $section) {
            // Same reason as the HTML exports: skip the title when the prose
            // already opens with one, rather than printing it twice.
            if (! $this->opensWithHeading($section)) {
                $parts[] = "\n## {$section->title}\n";
            }

            $parts[] = $this->renderer->markdown($section);
        }

        return $this->put($book, $export, implode("\n\n", $parts)."\n");
    }

    /** @param \Illuminate\Support\Collection<int, ManuscriptSection> $sections */
    private function singleHtml(Book $book, $sections, BookExport $export): string
    {
        $body = '';

        foreach ($sections as $section) {
            $body .= '<section>'."\n".$this->chapterHtml($section)."</section>\n";
        }

        return $this->put($book, $export, $this->page($book, $body, standalone: true));
    }

    /**
     * A print-ready interior as HTML with @page rules.
     *
     * Not a binary PDF: producing one needs a headless renderer that is not
     * installed here, and shipping a file named .pdf that is not a PDF would
     * be rejected by every store. This is the printable source at the book's
     * own trim size, which a browser's Print to PDF turns into the real
     * thing. The format is labelled accordingly rather than overpromising.
     *
     * @param \Illuminate\Support\Collection<int, ManuscriptSection> $sections
     */
    private function printable(Book $book, $sections, BookExport $export): string
    {
        $layout = $book->layout();
        [$width, $height] = $this->trimInches($layout['trim']);

        $margin = match ($layout['margins']) {
            'Narrow' => '0.5in',
            'Wide' => '1in',
            default => '0.75in',
        };

        $font = $layout['font'] === 'sans'
            ? "'Helvetica Neue', Arial, sans-serif"
            : "'Source Serif 4', Georgia, serif";

        $css = <<<CSS
        @page { size: {$width}in {$height}in; margin: {$margin}; }
        body { font-family: {$font}; font-size: {$layout['size']}pt; line-height: {$layout['leading']}; }
        h1 { page-break-before: always; font-size: 1.8em; margin: 0 0 1em; }
        h1:first-of-type { page-break-before: avoid; }
        p { margin: 0; text-indent: 1.5em; text-align: justify; hyphens: auto; }
        h1 + p, h2 + p, blockquote p { text-indent: 0; }
        img { max-width: 100%; }
        CSS;

        if ($layout['dropcap']) {
            $css .= "\nh1 + p::first-letter { float: left; font-size: 3.2em; line-height: .85; padding: .05em .08em 0 0; }";
        }

        if ($layout['headers']) {
            $css .= "\n@page { @top-center { content: '".addslashes($book->title)."'; font-size: 9pt; } }";
        }

        $body = '';

        foreach ($sections as $section) {
            $body .= $this->chapterHtml($section);
        }

        return $this->put($book, $export, $this->page($book, $body, standalone: true, css: $css));
    }

    /**
     * A .docx, built as a real Open XML package.
     *
     * Editors open these in Word and mark them up; an HTML file renamed .docx
     * prompts a repair dialog, which is where an author stops trusting the
     * export.
     *
     * @param \Illuminate\Support\Collection<int, ManuscriptSection> $sections
     */
    private function docx(Book $book, $sections, BookExport $export): string
    {
        $paragraphs = $this->docxParagraph($book->title, 'Title');

        if ($book->subtitle) {
            $paragraphs .= $this->docxParagraph($book->subtitle, 'Subtitle');
        }

        if ($book->author_name) {
            $paragraphs .= $this->docxParagraph($book->author_name, 'Subtitle');
        }

        foreach ($sections as $section) {
            $paragraphs .= $this->docxParagraph($section->title, 'Heading1');

            foreach (explode("\n\n", $section->plainText()) as $block) {
                if (trim($block) !== '') {
                    $paragraphs .= $this->docxParagraph(trim($block));
                }
            }
        }

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.$paragraphs.'</w:body></w:document>';

        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            .'<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            .'</Types>';

        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            .'</Relationships>';

        $documentRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';

        return $this->zip($book, $export, [
            '[Content_Types].xml' => $contentTypes,
            '_rels/.rels' => $rels,
            'word/document.xml' => $document,
            'word/_rels/document.xml.rels' => $documentRels,
            'word/styles.xml' => $this->docxStyles(),
        ]);
    }

    private function docxParagraph(string $text, ?string $style = null): string
    {
        $properties = $style ? '<w:pPr><w:pStyle w:val="'.$style.'"/></w:pPr>' : '';

        // xml:space="preserve" so leading and trailing spaces inside a
        // paragraph are not silently collapsed by Word.
        return '<w:p>'.$properties.'<w:r><w:t xml:space="preserve">'
            .htmlspecialchars($text, ENT_XML1 | ENT_QUOTES, 'UTF-8')
            .'</w:t></w:r></w:p>';
    }

    private function docxStyles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'."\n"
            .'<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:style w:type="paragraph" w:styleId="Title"><w:name w:val="Title"/>'
            .'<w:rPr><w:sz w:val="56"/><w:b/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Subtitle"><w:name w:val="Subtitle"/>'
            .'<w:rPr><w:sz w:val="28"/><w:i/></w:rPr></w:style>'
            .'<w:style w:type="paragraph" w:styleId="Heading1"><w:name w:val="heading 1"/>'
            .'<w:rPr><w:sz w:val="36"/><w:b/></w:rPr></w:style>'
            .'</w:styles>';
    }

    /**
     * A valid EPUB 3 container.
     *
     * @param \Illuminate\Support\Collection<int, ManuscriptSection> $sections
     */
    private function epub(Book $book, $sections, BookExport $export): string
    {
        $uuid = 'urn:uuid:'.Str::uuid()->toString();
        $identifier = $book->isbn ? 'urn:isbn:'.$book->isbn : $uuid;
        $language = $this->languageCode($book->language);
        $modified = now()->utc()->format('Y-m-d\TH:i:s\Z');

        $files = [];
        $manifest = '';
        $spine = '';
        $nav = '';

        foreach ($sections as $index => $section) {
            $id = 'ch'.($index + 1);
            $name = "OEBPS/{$id}.xhtml";

            $files[$name] = $this->xhtml(
                $section->title,
                $this->chapterHtml($section),
                $language,
            );

            $manifest .= '<item id="'.$id.'" href="'.$id.'.xhtml" media-type="application/xhtml+xml"/>';
            $spine .= '<itemref idref="'.$id.'"/>';
            $nav .= '<li><a href="'.$id.'.xhtml">'.e($section->title).'</a></li>';
        }

        // The accessibility metadata stores check for. Claimed only where it
        // is true: textual content is accurate for a text manuscript, and
        // nothing here asserts tested conformance.
        $accessibility = '<meta property="schema:accessMode">textual</meta>'
            .'<meta property="schema:accessibilityFeature">structuralNavigation</meta>'
            .'<meta property="schema:accessibilityFeature">readingOrder</meta>'
            .'<meta property="schema:accessModeSufficient">textual</meta>';

        $description = trim((string) (($book->meta ?? [])['description'] ?? ''));

        $opf = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<package xmlns="http://www.idpf.org/2007/opf" version="3.0" unique-identifier="pub-id" prefix="schema: http://schema.org/">'
            .'<metadata xmlns:dc="http://purl.org/dc/elements/1.1/">'
            .'<dc:identifier id="pub-id">'.e($identifier).'</dc:identifier>'
            .'<dc:title>'.e($book->title).'</dc:title>'
            .($book->subtitle ? '<dc:title id="sub">'.e($book->subtitle).'</dc:title>' : '')
            .'<dc:language>'.e($language).'</dc:language>'
            .($book->author_name ? '<dc:creator>'.e($book->author_name).'</dc:creator>' : '')
            .($description !== '' ? '<dc:description>'.e($description).'</dc:description>' : '')
            .'<meta property="dcterms:modified">'.$modified.'</meta>'
            .$accessibility
            .'</metadata>'
            .'<manifest><item id="nav" href="nav.xhtml" media-type="application/xhtml+xml" properties="nav"/>'
            .'<item id="css" href="style.css" media-type="text/css"/>'
            .$manifest.'</manifest>'
            .'<spine>'.$spine.'</spine></package>';

        $navDocument = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" '
            .'xmlns:epub="http://www.idpf.org/2007/ops" lang="'.e($language).'" xml:lang="'.e($language).'">'
            .'<head><title>Contents</title><meta charset="utf-8"/></head><body>'
            .'<nav epub:type="toc" id="toc"><h1>Contents</h1><ol>'.$nav.'</ol></nav>'
            .'</body></html>';

        $container = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<container version="1.0" xmlns="urn:oasis:names:tc:opendocument:xmlns:container">'
            .'<rootfiles><rootfile full-path="OEBPS/content.opf" media-type="application/oebps-package+xml"/></rootfiles>'
            .'</container>';

        return $this->zip($book, $export, array_merge([
            'META-INF/container.xml' => $container,
            'OEBPS/content.opf' => $opf,
            'OEBPS/nav.xhtml' => $navDocument,
            'OEBPS/style.css' => $this->epubCss($book),
        ], $files), mimetypeFirst: true);
    }

    private function epubCss(Book $book): string
    {
        $layout = $book->layout();

        // em rather than pt: a reader controls the type size in an e-reader,
        // and a fixed point size fights that.
        return "body { line-height: {$layout['leading']}; margin: 0 5%; }\n"
            ."h1 { font-size: 1.6em; margin: 1.5em 0 1em; }\n"
            ."p { margin: 0 0 .25em; text-indent: 1.5em; }\n"
            ."h1 + p { text-indent: 0; }\n"
            ."blockquote { margin: 1em 2em; font-style: italic; }\n"
            .'img { max-width: 100%; height: auto; }'."\n";
    }

    private function xhtml(string $title, string $body, string $language): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<!DOCTYPE html><html xmlns="http://www.w3.org/1999/xhtml" lang="'.e($language)
            .'" xml:lang="'.e($language).'"><head><meta charset="utf-8"/><title>'.e($title)
            .'</title><link rel="stylesheet" type="text/css" href="style.css"/></head><body>'
            .$body.'</body></html>';
    }

    /** A standalone HTML page. */
    private function page(Book $book, string $body, bool $standalone = false, string $css = ''): string
    {
        $language = $this->languageCode($book->language);

        $default = $css !== '' ? $css : <<<'CSS'
        body { max-width: 38em; margin: 3em auto; padding: 0 1.25em;
               font-family: Georgia, 'Times New Roman', serif; line-height: 1.6; }
        h1 { font-size: 1.8em; margin: 2em 0 1em; }
        img { max-width: 100%; height: auto; }
        CSS;

        return '<!doctype html>'."\n".'<html lang="'.e($language).'">'."\n"
            .'<head><meta charset="utf-8">'
            .'<meta name="viewport" content="width=device-width, initial-scale=1">'
            .'<title>'.e($book->title).'</title>'
            .'<style>'.$default.'</style></head>'."\n"
            .'<body>'."\n".$body."\n".'</body></html>'."\n";
    }

    /**
     * Map a display language to a BCP 47 code.
     *
     * EPUB requires one, and stores reject a file whose dc:language is a
     * display string like "English (US)".
     */
    private function languageCode(?string $language): string
    {
        return match ($language) {
            'Spanish' => 'es',
            'French' => 'fr',
            'German' => 'de',
            'Portuguese' => 'pt',
            'English (UK)' => 'en-GB',
            default => 'en-US',
        };
    }

    /** @return array{0: float, 1: float} inches */
    private function trimInches(string $trim): array
    {
        if (preg_match('/([\d.]+)\s*x\s*([\d.]+)/', $trim, $found)) {
            return [(float) $found[1], (float) $found[2]];
        }

        return [6.0, 9.0];
    }

    private function put(Book $book, BookExport $export, string $contents): string
    {
        $path = $this->directory($book).'/'.$this->filename($book, $export);

        Storage::disk('local')->put($path, $contents);

        return $path;
    }

    /**
     * Write a zip container.
     *
     * @param  array<string, string>  $entries
     * @param  bool  $mimetypeFirst  EPUB requires an uncompressed `mimetype`
     *                               entry first in the archive; a reader that
     *                               cannot find it there rejects the file.
     */
    private function zip(Book $book, BookExport $export, array $entries, bool $mimetypeFirst = false): string
    {
        $relative = $this->directory($book).'/'.$this->filename($book, $export);

        Storage::disk('local')->makeDirectory($this->directory($book));

        $absolute = Storage::disk('local')->path($relative);
        $archive = new ZipArchive;

        if ($archive->open($absolute, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('Could not create the export file.');
        }

        if ($mimetypeFirst) {
            $archive->addFromString('mimetype', 'application/epub+zip');
            $archive->setCompressionName('mimetype', ZipArchive::CM_STORE);
        }

        foreach ($entries as $name => $contents) {
            $archive->addFromString($name, $contents);
        }

        $archive->close();

        return $relative;
    }
}
