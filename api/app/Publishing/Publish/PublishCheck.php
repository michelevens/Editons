<?php

namespace App\Publishing\Publish;

use App\Commerce\Print\LuluPackage;
use App\Commerce\Print\LuluPrinting;
use App\Models\Book;
use App\Publishing\AiDisclosure;
use App\Publishing\CoverRenderer;
use App\Publishing\IsbnPlan;
use App\Publishing\PrintPages;
use App\Publishing\PrintSpecs;
use App\Publishing\RightsDeclaration;
use App\Publishing\Royalties;
use App\Publishing\SubmissionCheck;

/**
 * Step 1 of Publish: one answer to "can this book go out?".
 *
 * It runs the checks the studio already has, for the channels chosen in
 * step 2, and turns them into one list with a single green / amber / red
 * summary:
 *
 * - SubmissionCheck: the listing (title, author, description, category,
 *   price, manuscript length), the rights and AI declarations, and the
 *   print rules (PrintCheck) of each chosen print store; its warnings
 *   (pending suggestions, store limits from StoreReadiness, legal deposit).
 * - the ISBN plan for each chosen edition (IsbnPlan);
 * - the prices: a paperback price above KDP's printing floor;
 * - the cover and back cover;
 * - the files: an EPUB, print interior and cover wraps newer than the last
 *   manuscript change (Freshness), with one "Regenerate files" action;
 * - per channel: KDP's languages, Google's need for an ISBN in bulk, Lulu.
 *
 * Every problem carries a fix: the studio pane that fixes it, or an action
 * on this screen (regenerate, choose channels). Nothing is decided here
 * that the underlying check does not decide.
 */
class PublishCheck
{
    /** SubmissionCheck keys → the pane that fixes them. */
    private const FIX = [
        'title' => ['settings', 'Book Settings'],
        'author_name' => ['export', 'Export & Publish'],
        'description' => ['export', 'Store listing'],
        'category' => ['export', 'Store listing'],
        'list_price' => ['export', 'Store listing'],
        'isbn' => ['rights', 'ISBN & Rights'],
        'manuscript' => ['manuscript', 'Manuscript'],
        'channels' => ['channels', 'Choose channels'],
        'rights_declaration' => ['rights', 'ISBN & Rights'],
        'ai_disclosure' => ['rights', 'ISBN & Rights'],
        'rights_unsure' => ['rights', 'ISBN & Rights'],
        'suggestions' => ['manuscript', 'Review suggestions'],
        'epub' => ['regenerate', 'Regenerate files'],
        'legal_deposit' => ['rights', 'ISBN & Rights'],
    ];

    /** Print-check item keys → the pane that fixes them. */
    private const PRINT_FIX = [
        'trim' => ['layout', 'Design & Layout'],
        'pages' => ['layout', 'Design & Layout'],
        'margins' => ['layout', 'Design & Layout'],
        'paper' => ['export', 'Print & price'],
        'isbn' => ['rights', 'ISBN & Rights'],
        'cover' => ['cover', 'Cover Studio'],
        'back' => ['export', 'Print cover'],
        'spine' => ['export', 'Print cover'],
        'images' => ['manuscript', 'Manuscript'],
        'captions' => ['manuscript', 'Manuscript'],
        'layout' => ['manuscript', 'Manuscript'],
        'suggestions' => ['manuscript', 'Review suggestions'],
    ];

    public const GROUPS = [
        'channels' => 'Channels',
        'listing' => 'Store listing',
        'declarations' => 'Rights & AI declarations',
        'isbn' => 'ISBN',
        'prices' => 'Prices',
        'cover' => 'Cover',
        'print' => 'Print',
        'files' => 'Files',
    ];

    public function __construct(
        private readonly SubmissionCheck $submission,
        private readonly LuluPrinting $lulu,
        private readonly CoverRenderer $covers,
    ) {}

    /** @return array<string, mixed> */
    public function run(Book $book): array
    {
        $chosen = PublishPlan::chosen($book);
        $services = PublishPlan::services($book);
        $items = [];
        $add = function (string $group, string $key, string $status, string $message, ?array $fix = null) use (&$items): void {
            $items[] = ['group' => $group, 'key' => $key, 'status' => $status, 'message' => $message,
                'fix' => $fix ? ['pane' => $fix[0], 'label' => $fix[1]] : null];
        };

        // Channels.
        if ($chosen === []) {
            $add('channels', 'channels', 'fail', 'Choose at least one channel in step 2.', ['channels', 'Choose channels']);
        } else {
            $add('channels', 'channels', 'pass', count($chosen).' channel'.(count($chosen) === 1 ? '' : 's').' chosen: '.implode(', ', array_map(fn ($c) => PublishPlan::CARDS[$c][0], $chosen)).'.');
        }

        if (array_intersect($chosen, ['kdp_ebook', 'kdp_paperback']) !== []) {
            $language = KdpGuide::language($book);

            if ($language['status'] === 'fail') {
                $add('channels', 'kdp_language', 'fail', 'Amazon KDP: '.$language['note'], ['settings', 'Book Settings']);
            }
        }

        if (in_array('google_play', $chosen, true) && ! IsbnPlan::plan($book)['ebook']['isbn']) {
            $add('channels', 'google_isbn', 'warn', 'Google Play: the bulk upload needs an ISBN for each book ("If your books don\'t have unique identifiers (such as ISBNs), you cannot add them ... using a spreadsheet"). Without one, add the book on its own in Partner Center.', ['rights', 'ISBN & Rights']);
        }

        // Listing, declarations and the chosen print stores' rules.
        foreach ($this->submission->errors($book) as $key => $message) {
            if ($key === 'channels') {
                continue;
            }

            $add($this->group($key), $key, 'fail', $message, $this->fixFor($key));
        }

        foreach ($this->submission->warnings($book) as $key => $message) {
            $add($this->group($key), $key, 'warn', $message, $this->fixFor($key));
        }

        $failing = array_column($items, 'key');

        foreach (['title' => 'Title', 'description' => 'Description', 'category' => 'Category', 'list_price' => 'List price'] as $key => $label) {
            if (! in_array($key, $failing, true)) {
                $add('listing', $key, 'pass', $label.': ready.');
            }
        }

        if (! in_array('rights_declaration', $failing, true)) {
            $add('declarations', 'rights_declaration', RightsDeclaration::confirmed($book) ? 'pass' : 'warn',
                RightsDeclaration::confirmed($book) ? 'Rights declaration confirmed.' : 'No rights declaration yet.', ['rights', 'ISBN & Rights']);
        }

        if (AiDisclosure::required($book) && AiDisclosure::confirmed($book)) {
            $add('declarations', 'ai_confirmed', 'pass', 'AI-content answers confirmed.');
        }

        // ISBN per chosen edition.
        $plan = IsbnPlan::plan($book);
        $editions = array_filter([
            'ebook' => array_intersect($chosen, ['store', 'kdp_ebook', 'google_play']) !== [],
            'paperback' => $services !== [],
        ]);

        foreach (array_keys($editions) as $edition) {
            $row = $plan[$edition];
            match (true) {
                (bool) $row['isbn'] => $add('isbn', "isbn_{$edition}", 'pass', ucfirst($edition).' ISBN '.$row['isbn'].'.'),
                $row['source'] === 'own' => null, // reported by the store and print checks
                $row['source'] === 'store' => $add('isbn', "isbn_{$edition}", 'pass', ucfirst($edition).': ISBN assigned by the store.'),
                default => $add('isbn', "isbn_{$edition}", 'warn', ucfirst($edition).': no ISBN plan chosen. Choose your own ISBN or one a store assigns.', ['rights', 'ISBN & Rights']),
            };
        }

        // Prices.
        $prices = Royalties::prices($book);

        if ($services !== []) {
            if ($prices['print'] === null) {
                $add('prices', 'print_price', 'fail', 'No paperback price.', ['export', 'Print & price']);
            } elseif (in_array('kdp', $services, true)) {
                $pages = PrintPages::for($book);
                $terms = Royalties::kdpPaperback($prices['print'], $pages['pages'], $book->printSettings()['ink'], $book->layout()['trim']);

                $terms['minimum_price'] !== null && $prices['print'] < $terms['minimum_price']
                    ? $add('prices', 'print_price', 'fail', sprintf('Paperback $%s is below KDP\'s lowest list price for this book, $%s.', number_format($prices['print'], 2), number_format($terms['minimum_price'], 2)), ['export', 'Print & price'])
                    : $add('prices', 'print_price', 'pass', sprintf('Paperback $%s; about $%s a copy at KDP.', number_format($prices['print'], 2), number_format((float) $terms['royalty'], 2)));
            } else {
                $add('prices', 'print_price', 'pass', sprintf('Paperback $%s.', number_format($prices['print'], 2)));
            }
        }

        if ($prices['ebook'] !== null && ! in_array('list_price', $failing, true)) {
            $add('prices', 'ebook_price', 'pass', sprintf('Ebook $%s.', number_format($prices['ebook'], 2)));
        }

        // Cover. Print stores' back-cover rules come from PrintCheck above.
        $settings = $this->covers->settings($book);
        $settings['layout'] === 'finished' && ! $settings['artwork']
            ? $add('cover', 'front', 'warn', 'The cover is set to a finished design but has no artwork; a designed cover is used.', ['cover', 'Cover Studio'])
            : $add('cover', 'front', 'pass', $settings['artwork'] ? 'Front cover: your artwork.' : 'Front cover: designed in Cover Studio.');

        if ($services !== [] && ! collect($items)->contains(fn ($i) => str_ends_with($i['key'], '_back'))) {
            $add('cover', 'back', 'pass', 'Back cover ready for print.');
        }

        // Lulu.
        if (in_array('lulu', $chosen, true)) {
            $this->luluItems($book, $add);
        }

        // Files.
        $files = Freshness::files($book, PublishPlan::wantsEbook($book), $services);

        foreach ($files as $file) {
            $name = $file['label'].(isset($file['service']) ? ' ('.PrintSpecs::label($file['service']).')' : '');
            $file['fresh']
                ? $add('files', 'file_'.$file['format'].($file['service'] ?? ''), 'pass', $name.': up to date.')
                : $add('files', 'file_'.$file['format'].($file['service'] ?? ''), $file['export'] ? 'warn' : 'fail', $name.': '.$file['reason'], ['regenerate', 'Regenerate files']);
        }

        // A file SubmissionCheck already warned is missing is reported once.
        $items = collect($items)->reject(fn ($i) => $i['key'] === 'epub' && collect($files)->contains('format', 'epub'))->values()->all();

        $statuses = array_column($items, 'status');
        $status = in_array('fail', $statuses, true) ? 'red' : (in_array('warn', $statuses, true) ? 'amber' : 'green');

        return [
            'status' => $status,
            'summary' => match ($status) {
                'red' => 'Not ready: fix the red items first.',
                'amber' => 'Ready, with things worth checking.',
                default => 'Ready to publish.',
            },
            'counts' => [
                'fail' => count(array_keys($statuses, 'fail', true)),
                'warn' => count(array_keys($statuses, 'warn', true)),
                'pass' => count(array_keys($statuses, 'pass', true)),
            ],
            'groups' => self::GROUPS,
            'items' => $items,
            'files' => array_map(fn ($f) => [
                'format' => $f['format'],
                'service' => $f['service'] ?? null,
                'label' => $f['label'],
                'fresh' => $f['fresh'],
                'reason' => $f['reason'],
                'export_id' => $f['export']?->id,
                'created_at' => $f['export']?->created_at?->toIso8601String(),
                'download' => $f['export'] ? route('publishing.download', [$book->id, $f['export']->id]) : null,
            ], $files),
            'stale' => collect($files)->contains(fn ($f) => ! $f['fresh']),
            'last_change' => Freshness::lastChange($book)?->toIso8601String(),
        ];
    }

    private function luluItems(Book $book, callable $add): void
    {
        $status = $this->lulu->status($book);

        if (! $status['connected']) {
            $add('print', 'lulu', 'warn', 'Lulu is not connected: paperbacks cannot be printed for the store yet. The platform owner adds the Lulu API keys.');

            return;
        }

        $package = LuluPackage::for($book);

        if ($package['id'] === null) {
            $add('print', 'lulu_package', 'fail', 'Lulu: '.$package['problem'], ['layout', 'Design & Layout']);

            return;
        }

        if (! $status['shipping_set']) {
            $add('print', 'lulu_shipping', 'warn', 'Lulu: no paperback shipping charge is set for the store yet (the platform owner sets it).');
        }

        match ($status['state']) {
            'validated', 'live' => $add('print', 'lulu', 'pass', 'Lulu passed the print files ('.LuluPackage::describe($status['pod_package_id']).').'),
            'validating' => $add('print', 'lulu', 'warn', 'Lulu is still checking the print files.'),
            'failed' => $add('print', 'lulu', 'fail', 'Lulu found problems in the print files: '.implode(' ', [...($status['interior']['errors'] ?? []), ...($status['cover']['errors'] ?? [])]), ['regenerate', 'Regenerate files']),
            'stale' => $add('print', 'lulu', 'warn', 'The print files changed after Lulu checked them: send them again in step 3.'),
            default => $add('print', 'lulu', 'warn', 'Lulu has not checked the print files yet: send them in step 3.'),
        };
    }

    private function group(string $key): string
    {
        return match (true) {
            str_starts_with($key, 'print_') => str_ends_with($key, '_isbn') ? 'isbn' : (preg_match('/_(cover|back|spine)$/', $key) ? 'cover' : 'print'),
            str_starts_with($key, 'store_') => 'listing',
            in_array($key, ['rights_declaration', 'ai_disclosure', 'rights_unsure', 'legal_deposit'], true) => 'declarations',
            $key === 'isbn' => 'isbn',
            $key === 'epub' => 'files',
            $key === 'suggestions', $key === 'manuscript' => 'listing',
            default => 'listing',
        };
    }

    /** @return array{0: string, 1: string}|null */
    private function fixFor(string $key): ?array
    {
        if (isset(self::FIX[$key])) {
            return self::FIX[$key];
        }

        if (preg_match('/^print_[a-z]+_(.+)$/', $key, $m)) {
            return self::PRINT_FIX[$m[1]] ?? ['export', 'Print & price'];
        }

        if (preg_match('/^store_[a-z_]+?_(keywords|cover|isbn|price|epub)$/', $key, $m)) {
            return match ($m[1]) {
                'keywords' => ['export', 'Store listing'],
                'cover' => ['cover', 'Cover Studio'],
                'isbn' => ['rights', 'ISBN & Rights'],
                'price' => ['export', 'Store listing'],
                default => ['regenerate', 'Regenerate files'],
            };
        }

        return null;
    }
}
