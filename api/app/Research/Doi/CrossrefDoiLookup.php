<?php

namespace App\Research\Doi;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Crossref's public REST API: https://api.crossref.org/works/{doi}.
 *
 * Free and keyless. Crossref asks callers to identify themselves (the
 * "polite pool") with a mailto in the User-Agent, which costs nothing and
 * gets better service. Only the DOI leaves the server — never anything from
 * the manuscript.
 */
class CrossrefDoiLookup implements DoiLookup
{
    public function find(string $doi): ?array
    {
        try {
            $response = Http::timeout(8)
                ->acceptJson()
                ->withUserAgent(config('app.name').' (mailto:'.config('mail.from.address').')')
                ->get('https://api.crossref.org/works/'.rawurlencode($doi));
        } catch (ConnectionException $e) {
            throw new DoiLookupFailed($e->getMessage(), previous: $e);
        }

        if ($response->status() === 404) {
            return null;
        }

        if (! $response->successful()) {
            throw new DoiLookupFailed('Crossref answered '.$response->status());
        }

        return self::map((array) $response->json('message', []));
    }

    /**
     * A Crossref "work" message to source fields.
     *
     * @param  array<string, mixed>  $w
     * @return array<string, string|null>
     */
    public static function map(array $w): array
    {
        $type = (string) ($w['type'] ?? '');
        $kind = match ($type) {
            'journal-article', 'proceedings-article', 'posted-content' => 'journal_article',
            'book', 'monograph', 'edited-book', 'book-chapter', 'reference-book' => 'book',
            'report', 'report-component' => 'report',
            default => 'other',
        };

        $people = collect($w['author'] ?? $w['editor'] ?? [])
            ->map(fn ($p) => isset($p['family'])
                ? trim($p['family'].(isset($p['given']) ? ', '.$p['given'] : ''))
                : ($p['name'] ?? null))
            ->filter()
            ->implode('; ');

        if ($people !== '' && ! isset($w['author']) && isset($w['editor'])) {
            $people .= count($w['editor']) > 1 ? ' (eds.)' : ' (ed.)';
        }

        $year = data_get($w, 'issued.date-parts.0.0') ?? data_get($w, 'published.date-parts.0.0');

        return [
            'kind' => $kind,
            'title' => $w['title'][0] ?? null,
            'authors' => $people === '' ? null : $people,
            'publisher' => $w['publisher'] ?? null,
            'published' => $year ? (string) $year : null,
            'container' => $w['container-title'][0] ?? null,
            'volume' => isset($w['volume']) ? (string) $w['volume'] : null,
            'issue' => isset($w['issue']) ? (string) $w['issue'] : null,
            'pages' => isset($w['page']) ? str_replace('-', '–', (string) $w['page']) : null,
            'edition' => isset($w['edition-number']) ? (string) $w['edition-number'] : null,
            'url' => $w['URL'] ?? null,
            'doi' => $w['DOI'] ?? null,
            'isbn' => $w['ISBN'][0] ?? null,
            'notes' => null,
        ];
    }
}
