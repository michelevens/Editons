<?php

namespace App\Publishing;

use App\Models\Book;

/**
 * Will this book print at a given service?
 *
 * Each rule is the service's own published figure (PrintSpecs, config/
 * publishing.php) applied to this book: its trim, its page count, its
 * margins, its pictures, its ISBN and its cover. A rejected print file
 * costs an author days, and most rejections are one of these.
 *
 * Every item is pass, warn or fail with a fix the author can act on.
 * A rule that depends on the page count only fails on the typeset count;
 * on an estimate it warns, because an estimate is not a fact about the
 * book (PrintPages).
 */
class PrintCheck
{
    public function __construct(
        private readonly SubmissionCheck $submission,
        private readonly CoverWrap $wraps,
        private readonly BookImages $images,
    ) {}

    /** @return array<string, array<string, mixed>> service => result */
    public function all(Book $book): array
    {
        $pages = PrintPages::for($book);
        $pending = $this->submission->pendingSuggestions($book);
        $lowRes = $this->lowResolutionImages($book);

        return collect(PrintSpecs::checkedServices())
            ->mapWithKeys(fn (string $service) => [$service => $this->for($book, $service, $pages, $pending, $lowRes)])
            ->all();
    }

    /**
     * @param  array{pages: int, exact: bool, at: ?string}|null  $pages
     * @return array{service: string, label: string, status: string, pages: array, items: list<array{key: string, status: string, message: string, fix: ?string}>}
     */
    public function for(Book $book, string $service, ?array $pages = null, ?int $pending = null, ?int $lowRes = null): array
    {
        $pages ??= PrintPages::for($book);
        $count = PrintSpecs::evenPages($pages['pages']);
        $label = PrintSpecs::label($service);
        $layout = $book->layout();
        $print = $book->printSettings();
        $soft = $pages['exact'] ? 'fail' : 'warn';
        $about = $pages['exact'] ? "{$count} pages" : "about {$count} pages (estimated; generate the print interior for the exact count)";
        $items = [];
        $add = function (string $key, string $status, string $message, ?string $fix = null) use (&$items): void {
            $items[] = compact('key', 'status', 'message', 'fix');
        };

        // Trim: a size the service lists for this paper, else a custom size
        // inside its published range.
        $paperName = $print['ink'] === 'bw' ? $print['paper'] : 'white';
        $custom = PrintSpecs::customTrimSupported($service, $layout['trim'], $paperName);

        match (true) {
            PrintSpecs::trimSupported($service, $layout['trim'], $paperName) => $add('trim', 'pass', "{$label} prints {$layout['trim']}."),
            $custom === true => $add('trim', 'pass', "{$layout['trim']} is a custom size within {$label}'s custom trim range for {$this->paperName($print)} (paperback)."),
            default => $add('trim', 'fail', "{$label} does not print {$layout['trim']} on {$this->paperName($print)}, as a listed or a custom size.", 'Choose another trim size in Design.'),
        };

        // Paper and ink.
        $range = PrintSpecs::pageRange($service, $print['paper'], $print['ink'], $layout['trim']);

        if ($range === null) {
            $add('paper', 'warn', "The page limits for this paper and ink at {$label} are not encoded here.", "Check {$label}'s own size matrix before ordering.");
        } elseif ($count < $range[0] || $count > $range[1]) {
            $add('pages', $soft, "{$label} prints {$range[0]}–{$range[1]} pages on {$this->paperName($print)}; this book is {$about}.",
                $count < $range[0] ? 'Add pages (front and back matter count) or choose another service.' : 'Choose a larger trim or smaller type in Design, or split the book.');
        } else {
            $add('pages', 'pass', "{$about}, within {$label}'s {$range[0]}–{$range[1]} for {$this->paperName($print)}.");
        }

        // Inside margin.
        $required = PrintSpecs::requiredGutter($service, $count);
        // The margin the interior is set at: the one the typeset interior
        // recorded, else what PrintBook will use for this length (the
        // setting, widened to the strictest service's band). An interior
        // typeset before margins tracked the length recorded none, and is
        // held to its setting.
        $margins = PageSetup::margins($layout);
        $margin = match (true) {
            $pages['exact'] && isset($pages['margin']) => (float) $pages['margin'],
            $pages['exact'] => $margins['inside'],
            default => PrintBook::insideMargin($layout, $count),
        };

        if ($margin + 1e-9 < $required) {
            $add('margins', $soft,
                sprintf('%s needs an inside margin of at least %s in at %s; this book\'s is %s in.', $label, $this->inches($required), $about, $this->inches($margin)),
                sprintf('Set the inside margin to %s in or more in Design.', $this->inches($required)));
        } else {
            $add('margins', 'pass', sprintf('Inside margin %s in meets %s\'s %s in for this length.', $this->inches($margin), $label, $this->inches($required)));
        }

        // Top, bottom and outside: the service's floor (KDP), or its
        // recommendation (IngramSpark), which only warns.
        $edge = (float) (PrintSpecs::spec($service)['outside_margin'] ?? 0.0);
        $outside = $pages['exact'] && isset($pages['outside']) ? (float) $pages['outside'] : PageSetup::sides($layout, $margin)[1];
        $narrowest = min($outside, $margins['top'], $margins['bottom']);

        if ($edge > 0 && $narrowest + 1e-9 < $edge) {
            $recommended = (bool) (PrintSpecs::spec($service)['outside_recommended'] ?? false);
            $add('edges', $recommended ? 'warn' : 'fail',
                sprintf('%s %s at least %s in at the top, bottom and outside; the narrowest here is %s in.', $label, $recommended ? 'recommends' : 'requires', $this->inches($edge), $this->inches($narrowest)),
                sprintf('Widen the top, bottom or outside margin to %s in in Design.', $this->inches($edge)));
        }

        // Pictures.
        $lowRes ??= $this->lowResolutionImages($book);
        $dpi = (int) config('publishing.print_dpi');
        $lowRes > 0
            ? $add('images', 'warn', $lowRes === 1 ? "1 picture will print below {$dpi} dpi and may look soft." : "{$lowRes} pictures will print below {$dpi} dpi and may look soft.", 'Replace them with larger originals.')
            : $add('images', 'pass', "Every picture prints at {$dpi} dpi or more.");

        // Pending suggestions.
        $pending ??= $this->submission->pendingSuggestions($book);

        if ($pending > 0) {
            $add('suggestions', 'warn', "{$pending} pending suggestion".($pending === 1 ? '' : 's').': the print files leave '.($pending === 1 ? 'it' : 'them').' out.', 'Accept or reject them in the manuscript.');
        }

        // Caption numbers typed by hand that are not the ones that print.
        $typed = Numbering::typedMismatches($book->sections()->orderBy('position')->get());

        if ($typed !== []) {
            $first = $typed[0];
            $add('captions', 'warn',
                sprintf('%d caption%s typed with a number that does not match %s chapter (first: “%s” in “%s”, which prints as “%s”).',
                    count($typed), count($typed) === 1 ? ' is' : 's are', count($typed) === 1 ? 'its' : 'their', $first['typed'], $first['section'], $first['prints']),
                'Write captions as “Tableau ?” or “Figure ?” and let the book number them.');
        }

        // Pages the last typeset interior left nearly empty.
        foreach ($pages['layout'] ?? [] as $note) {
            $add('layout', 'warn', $note, 'Shorten or lengthen the section slightly, or accept the short page.');
        }

        // ISBN.
        foreach (IsbnPlan::printIssues($book, $service) as $issue) {
            $add('isbn', ...$issue);
        }

        // Cover.
        $this->coverItems($book, $service, $count, $add);

        $statuses = array_column($items, 'status');

        return [
            'service' => $service,
            'label' => $label,
            'status' => in_array('fail', $statuses, true) ? 'fail' : (in_array('warn', $statuses, true) ? 'warn' : 'pass'),
            'pages' => array_diff_key($pages, ['layout' => 1, 'margin' => 1, 'outside' => 1]) + ['count' => $count],
            'items' => $items,
        ];
    }

    private function coverItems(Book $book, string $service, int $count, callable $add): void
    {
        $label = PrintSpecs::label($service);
        $settings = app(CoverRenderer::class)->settings($book);

        if ($settings['layout'] === 'finished' && ! $settings['artwork']) {
            $add('cover', 'warn', 'The cover is set to a finished design but has no artwork, so a designed cover is used.', 'Upload the finished cover in Cover Studio.');
        } else {
            $add('cover', 'pass', $settings['artwork'] ? 'Front cover: your artwork.' : 'Front cover: the cover designed in Cover Studio.');
        }

        if ($this->wraps->backArtwork($book)) {
            $spec = PrintSpecs::spec($service);
            $add('back', isset($spec['barcode']) ? 'warn' : 'pass', 'Back cover: your uploaded image.',
                isset($spec['barcode']) ? sprintf('Keep a %s × %s in area clear at the lower right for the barcode.', $this->inches($spec['barcode']['width']), $this->inches($spec['barcode']['height'])) : null);
        } elseif ($this->wraps->backCopy($book) === '') {
            $add('back', 'warn', 'The back cover will carry only the title: the book has no description.', 'Write the description (store listing) or a Back Cover Copy part.');
        } else {
            // The back panel does not depend on the spine, so any width
            // will do for a service that only gives it in its template.
            $paper = PrintSpecs::spec($service)['spine_papers'][0] ?? $book->printSettings()['paper'];
            $geometry = $this->wraps->geometry($book, ['service' => $service, 'paper' => $paper, 'ink' => $book->printSettings()['ink'], 'spine' => 0.5], $count);

            $this->wraps->copyFits($book, $geometry)['fits']
                ? $add('back', 'pass', 'Back cover: designed from your description.')
                : $add('back', 'warn', 'The description is too long for the back cover even at 8 pt, so the back is cut at the last line that fits.', 'Shorten the description, or upload a back cover under Print cover.');
        }

        $minimum = PrintSpecs::spec($service)['spine_text_min_pages'];

        if (! PrintSpecs::spineText($service, $count)) {
            $add('spine', 'pass', "The spine stays blank: {$label} allows spine text from {$minimum} pages.");
        }
    }

    /**
     * Pictures that would print below the target resolution: an image is
     * set at most as wide as the text, so fewer pixels than 300 per inch
     * of the measure prints soft.
     */
    public function lowResolutionImages(Book $book): int
    {
        $layout = $book->layout();
        [$width] = PageSetup::trimInches($layout['trim']);
        $margins = PageSetup::margins($layout);
        $measure = $width - $margins['inside'] - $margins['outside'];
        $needed = $measure * (int) config('publishing.print_dpi');
        $low = 0;
        $seen = [];

        $sections = $book->sections()
            ->whereRaw('content::text like ?', ['%"image"%'])
            ->get(['id', 'content']);

        foreach ($sections as $section) {
            $walk = function ($node) use (&$walk, &$low, &$seen, $book, $needed): void {
                if (! is_array($node)) {
                    return;
                }

                if (($node['type'] ?? null) === 'image' && ($src = (string) ($node['attrs']['src'] ?? '')) !== '' && ! isset($seen[$src])) {
                    $seen[$src] = true;
                    $image = $this->images->resolve($src, $book->id);
                    $size = $image ? @getimagesizefromstring($image['data']) : null;

                    if ($size && $size[0] < $needed) {
                        $low++;
                    }
                }

                foreach ($node['content'] ?? [] as $child) {
                    $walk($child);
                }
            };

            $walk($section->content ?? []);
        }

        return $low;
    }

    private function paperName(array $print): string
    {
        return match ($print['ink']) {
            'standard_color' => 'standard colour',
            'premium_color' => 'premium colour',
            default => $print['paper'].' paper',
        };
    }

    private function inches(float $value): string
    {
        return rtrim(rtrim(number_format($value, 3, '.', ''), '0'), '.');
    }
}
