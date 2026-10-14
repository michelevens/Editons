<?php

namespace App\Publishing;

use App\Models\ManuscriptSection;

/**
 * Turns ProseMirror JSON into the markup an export format needs.
 *
 * ProseMirror JSON is the canonical content (ADR-0002), so every export
 * starts here rather than from the editor's rendered DOM. The editor is not
 * running when a file is generated, and an export that depended on it could
 * only ever be made by a browser.
 */
class DocumentRenderer
{
    /** Marks, as the HTML tag that carries them. */
    private const MARK_TAGS = [
        'bold' => 'strong',
        'strong' => 'strong',
        'italic' => 'em',
        'em' => 'em',
        'code' => 'code',
        'strike' => 's',
        'underline' => 'u',
    ];

    public function html(ManuscriptSection $section): string
    {
        return $this->nodesToHtml($section->content['content'] ?? []);
    }

    public function markdown(ManuscriptSection $section): string
    {
        return trim($this->nodesToMarkdown($section->content['content'] ?? []));
    }

    /** @param array<int, mixed> $nodes */
    private function nodesToHtml(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $children = $node['content'] ?? [];

            $out .= match ($node['type'] ?? '') {
                'paragraph' => '<p>'.$this->inlineHtml($children)."</p>\n",
                'heading' => $this->headingHtml($node),
                'bulletList' => "<ul>\n".$this->nodesToHtml($children)."</ul>\n",
                'orderedList' => "<ol>\n".$this->nodesToHtml($children)."</ol>\n",
                'listItem' => '<li>'.trim($this->listItemHtml($children))."</li>\n",
                'blockquote' => "<blockquote>\n".$this->nodesToHtml($children)."</blockquote>\n",
                'codeBlock' => '<pre><code>'.e($this->plain($children))."</code></pre>\n",
                'horizontalRule' => "<hr />\n",
                'image' => $this->imageHtml($node),
                default => $this->inlineHtml($children),
            };
        }

        return $out;
    }

    /**
     * A list item's contents without the wrapping paragraph.
     *
     * ProseMirror nests a paragraph inside every listItem. Emitting it gives
     * <li><p>text</p></li>, which renders with vertical gaps between bullets
     * in most e-readers.
     *
     * @param  array<int, mixed>  $children
     */
    private function listItemHtml(array $children): string
    {
        $out = '';

        foreach ($children as $child) {
            if (! is_array($child)) {
                continue;
            }

            // A nested list stays a block; a paragraph is unwrapped.
            $out .= ($child['type'] ?? '') === 'paragraph'
                ? $this->inlineHtml($child['content'] ?? [])
                : $this->nodesToHtml([$child]);
        }

        return $out;
    }

    /** @param array<string, mixed> $node */
    private function headingHtml(array $node): string
    {
        // Clamped to h1-h6: a level outside that range is not valid HTML, and
        // an EPUB containing an <h7> fails store validation.
        $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 2)));

        return "<h{$level}>".$this->inlineHtml($node['content'] ?? [])."</h{$level}>\n";
    }

    /** @param array<string, mixed> $node */
    private function imageHtml(array $node): string
    {
        $src = (string) ($node['attrs']['src'] ?? '');
        $alt = (string) ($node['attrs']['alt'] ?? '');

        // alt is always emitted, empty if unset. A missing attribute fails
        // accessibility validation; an empty one declares the image
        // decorative, which is at least a claim a checker can accept.
        return '<img src="'.e($src).'" alt="'.e($alt)."\" />\n";
    }

    /** @param array<int, mixed> $nodes */
    private function inlineHtml(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';

            if ($type === 'hardBreak') {
                $out .= '<br />';

                continue;
            }

            if ($type !== 'text') {
                $out .= $this->inlineHtml($node['content'] ?? []);

                continue;
            }

            $text = e($node['text'] ?? '');

            foreach ($node['marks'] ?? [] as $mark) {
                $markType = $mark['type'] ?? '';

                if ($markType === 'link') {
                    $href = (string) ($mark['attrs']['href'] ?? '');
                    // rel on every outbound link: these files are opened in
                    // store apps and browsers we do not control.
                    $text = '<a href="'.e($href).'" rel="noopener">'.$text.'</a>';

                    continue;
                }

                if ($tag = self::MARK_TAGS[$markType] ?? null) {
                    $text = "<{$tag}>{$text}</{$tag}>";
                }
            }

            $out .= $text;
        }

        return $out;
    }

    /** @param array<int, mixed> $nodes */
    private function nodesToMarkdown(array $nodes, int $depth = 0): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';
            $children = $node['content'] ?? [];

            if ($type === 'heading') {
                $level = max(1, min(6, (int) ($node['attrs']['level'] ?? 2)));
                $out .= str_repeat('#', $level).' '.$this->inlineMarkdown($children)."\n\n";

                continue;
            }

            if ($type === 'paragraph') {
                $out .= $this->inlineMarkdown($children)."\n\n";

                continue;
            }

            if ($type === 'bulletList' || $type === 'orderedList') {
                foreach ($children as $index => $item) {
                    if (! is_array($item)) {
                        continue;
                    }

                    $bullet = $type === 'bulletList' ? '- ' : ($index + 1).'. ';
                    $body = trim($this->nodesToMarkdown($item['content'] ?? [], $depth + 1));
                    $out .= str_repeat('  ', $depth).$bullet.$body."\n";
                }

                $out .= "\n";

                continue;
            }

            if ($type === 'blockquote') {
                foreach (explode("\n", trim($this->nodesToMarkdown($children))) as $line) {
                    $out .= '> '.$line."\n";
                }

                $out .= "\n";

                continue;
            }

            if ($type === 'codeBlock') {
                $out .= "```\n".$this->plain($children)."\n```\n\n";

                continue;
            }

            if ($type === 'horizontalRule') {
                $out .= "---\n\n";

                continue;
            }

            $out .= $this->inlineMarkdown($children);
        }

        return $out;
    }

    /** @param array<int, mixed> $nodes */
    private function inlineMarkdown(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $type = $node['type'] ?? '';

            if ($type === 'hardBreak') {
                $out .= "  \n";

                continue;
            }

            if ($type !== 'text') {
                $out .= $this->inlineMarkdown($node['content'] ?? []);

                continue;
            }

            $text = (string) ($node['text'] ?? '');

            foreach ($node['marks'] ?? [] as $mark) {
                $text = match ($mark['type'] ?? '') {
                    'bold', 'strong' => '**'.$text.'**',
                    'italic', 'em' => '*'.$text.'*',
                    'code' => '`'.$text.'`',
                    'strike' => '~~'.$text.'~~',
                    'link' => '['.$text.']('.($mark['attrs']['href'] ?? '').')',
                    default => $text,
                };
            }

            $out .= $text;
        }

        return $out;
    }

    /** @param array<int, mixed> $nodes */
    private function plain(array $nodes): string
    {
        $out = '';

        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }

            $out .= ($node['text'] ?? '').$this->plain($node['content'] ?? []);
        }

        return $out;
    }
}
