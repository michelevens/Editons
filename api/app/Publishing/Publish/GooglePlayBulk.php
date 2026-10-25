<?php

namespace App\Publishing\Publish;

use App\Models\Book;
use App\Models\BookExport;
use App\Platform\House\House;
use App\Publishing\CoverRenderer;
use App\Publishing\Exporter;
use App\Publishing\IsbnPlan;
use App\Publishing\PrintPages;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Google Play Books' bulk upload: a book list and the files.
 *
 * Google has no upload API. Its Partner Center takes a book list, "one
 * book per row", "Separated by tabs" with "UTF-16 encoding", whose columns
 * its help page lists (config publishing.publish.google_play), then the
 * content files, each named by the book's identifier so Google matches it
 * to the row: `ISBN.epub` and `ISBN_frontcover.jpg` (answer 3424254).
 *
 * Google's own template is downloaded from the account's Book Catalog and
 * may carry columns the help page does not list (price columns, the
 * template's defaults); the screen says so, and this file follows the
 * help page's column list exactly. "If your books don't have unique
 * identifiers (such as ISBNs), you cannot add them to your book catalogue
 * using a spreadsheet": a book without an ebook ISBN is left out of the
 * list, with the reason, to be added on its own.
 */
class GooglePlayBulk
{
    public function __construct(private readonly CoverRenderer $covers) {}

    /**
     * Which books go in the list and which cannot, and why.
     *
     * @param  Collection<int, Book>  $books
     * @return array{rows: list<array{book: Book, isbn: string, epub: ?BookExport}>, skipped: list<array{id: int, title: string, reason: string}>}
     */
    public function plan(Collection $books): array
    {
        $rows = [];
        $skipped = [];

        foreach ($books as $book) {
            $isbn = IsbnPlan::plan($book)['ebook']['isbn'];
            $epub = Freshness::file($book, 'epub');

            $reason = match (true) {
                ! $isbn => 'No ebook ISBN: Google\'s bulk upload matches books by identifier. Add this book on its own in Partner Center, or assign an ISBN.',
                ! $epub['export'] => 'No EPUB yet: regenerate the files.',
                default => null,
            };

            if ($reason) {
                $skipped[] = ['id' => $book->id, 'title' => (string) $book->title, 'reason' => $reason];

                continue;
            }

            $rows[] = ['book' => $book, 'isbn' => $isbn, 'epub' => $epub['export'], 'fresh' => $epub['fresh']];
        }

        return ['rows' => $rows, 'skipped' => $skipped];
    }

    /** One row, keyed by the help page's column names. @return array<string, string> */
    public function row(Book $book, string $isbn): array
    {
        $publication = $book->publication();
        $code = substr(Exporter::languageCode($book->language), 0, 2);
        $author = trim((string) $book->author_name);
        $contributors = array_values(array_filter([
            $author !== '' ? $author.' [Author]' : null,
            ...collect($publication['credits'] ?? [])
                ->filter(fn ($c) => trim((string) ($c['name'] ?? '')) !== '')
                ->map(fn ($c) => trim((string) $c['name']).' ['.(trim((string) ($c['role'] ?? '')) ?: 'Contributor').']')
                ->all(),
        ]));
        $publisher = trim((string) ($publication['imprint'] ?: $publication['publisher'] ?: (House::isHouseBook($book) ? $book->user?->name : '')));

        $values = [
            'Identifier' => $isbn,
            'Enable for Sale?' => 'Yes',
            'Title' => (string) $book->title,
            'Subtitle' => (string) $book->subtitle,
            'Book Format' => 'Digital',
            'Related Identifier [Format, Relationship], Semicolon-Separated' => ($print = IsbnPlan::plan($book)['paperback']['isbn'])
                ? $print.' [Paperback, Alternative format]' : '',
            'Contributor [Role], Semicolon-Separated' => implode('; ', $contributors),
            'Language' => config("publishing.publish.google_play.languages.{$code}") ?? '',
            'Description' => KdpGuide::descriptionHtml((string) (($book->meta ?? [])['description'] ?? '')),
            'Publication Date' => $book->launch_date ? 'D:'.$book->launch_date->toDateString() : '',
            'Page Count' => (string) PrintPages::for($book)['pages'],
            'Series Name' => (string) $book->series_name,
            'Volume in Series' => $book->series_number ? (string) $book->series_number : '',
            'Publisher Name' => $publisher,
        ];

        return collect(config('publishing.publish.google_play.columns'))
            ->mapWithKeys(fn (string $column) => [$column => self::cell($values[$column] ?? '')])
            ->all();
    }

    /**
     * The book list as Google asks for it: tab-separated, UTF-16 with a
     * byte-order mark.
     *
     * @param  list<array{book: Book, isbn: string}>  $rows
     */
    public function spreadsheet(array $rows): string
    {
        $columns = config('publishing.publish.google_play.columns');
        $lines = [implode("\t", $columns)];

        foreach ($rows as $row) {
            $lines[] = implode("\t", $this->row($row['book'], $row['isbn']));
        }

        return "\xFF\xFE".mb_convert_encoding(implode("\r\n", $lines)."\r\n", 'UTF-16LE', 'UTF-8');
    }

    /**
     * A zip with the book list, each EPUB and front cover named by ISBN,
     * and a note of the books left out. Returns the temporary file's path.
     *
     * @param  Collection<int, Book>  $books
     */
    public function zip(Collection $books): string
    {
        $plan = $this->plan($books);
        $path = tempnam(sys_get_temp_dir(), 'gplay');
        $zip = new ZipArchive;

        if ($zip->open($path, ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The zip could not be written.');
        }

        $zip->addFromString('google-play-book-list.tsv', $this->spreadsheet($plan['rows']));

        foreach ($plan['rows'] as $row) {
            $disk = Storage::disk('files');

            if ($row['epub']->path && $disk->exists($row['epub']->path)) {
                $zip->addFromString($row['isbn'].'.epub', $disk->get($row['epub']->path));
            }

            $zip->addFromString($row['isbn'].'_frontcover.jpg', $disk->get($this->covers->ensure($row['book'])));
        }

        $zip->addFromString('README.txt', $this->readme($plan));
        $zip->close();

        return $path;
    }

    private function readme(array $plan): string
    {
        $sources = config('publishing.publish.google_play.sources');
        $lines = [
            'Google Play Books bulk upload, prepared by Écrivain on '.now()->toDateString().'.',
            '',
            '1. In Partner Center (https://play.google.com/books/publish/), Book Catalog > Advanced options > Upload book list: choose google-play-book-list.tsv (tab-separated, UTF-16).',
            '2. Then Advanced options > Upload content files: choose the .epub and _frontcover.jpg files. Each is named by the ebook ISBN, which is how Google matches it to its row.',
            '3. Check the upload history for rows with errors.',
            '',
            'The columns follow Google\'s help page ('.$sources['columns'].'). Google\'s own template, downloaded from your Book Catalog, may add columns (prices, your template\'s defaults): if it differs, copy the rows into it. Prices are set in Partner Center.',
        ];

        if ($plan['skipped'] !== []) {
            $lines[] = '';
            $lines[] = 'Not in this list:';

            foreach ($plan['skipped'] as $skip) {
                $lines[] = '- '.$skip['title'].': '.$skip['reason'];
            }
        }

        return implode("\r\n", $lines)."\r\n";
    }

    /** A cell cannot hold a tab or a line break. */
    private static function cell(string $value): string
    {
        return trim(preg_replace('/[\t\r\n]+/', ' ', $value));
    }
}
