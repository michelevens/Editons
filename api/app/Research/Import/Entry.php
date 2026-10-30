<?php

namespace App\Research\Import;

use App\Models\ResearchSource;
use Illuminate\Support\Str;

/**
 * One imported record, made safe for the library, and the keys that say
 * whether the library already has it.
 *
 * Every imported or looked-up value passes through clean() — the file is
 * somebody else's data, and the same rules apply whether it came from a
 * colleague's .bib or from Crossref: plain text only (tags and control
 * characters gone), lengths cut to what the columns hold, a URL only when it
 * is http(s), a DOI only when it looks like one. Nothing here can set the
 * verification: that stays a separate, recorded act.
 */
final class Entry
{
    /** The largest file the import reads, in bytes. */
    public const MAX_BYTES = 1_048_576;

    /** The most records one import reads. */
    public const MAX_ENTRIES = 500;

    /** Column => longest value it holds. */
    private const LIMITS = [
        'title' => 255, 'authors' => 255, 'publisher' => 255, 'published' => 255,
        'container' => 255, 'volume' => 40, 'issue' => 40, 'pages' => 40,
        'edition' => 40, 'url' => 2000, 'doi' => 255, 'isbn' => 255, 'notes' => 5000,
    ];

    /**
     * @param  array<string, mixed>  $raw
     * @return array<string, string|null>|null null when there is no title
     */
    public static function clean(array $raw): ?array
    {
        $out = ['kind' => in_array($raw['kind'] ?? null, ResearchSource::KINDS, true) ? $raw['kind'] : 'other'];

        foreach (self::LIMITS as $field => $max) {
            $value = $raw[$field] ?? null;
            $value = is_scalar($value) ? self::text((string) $value, multiline: $field === 'notes') : '';
            $out[$field] = $value === '' ? null : $value;
        }

        $out['authors'] = self::authors($out['authors']);
        $out['url'] = self::url($out['url']);
        $out['doi'] = self::doi($out['doi']);

        foreach (self::LIMITS as $field => $max) {
            if ($out[$field] !== null && mb_strlen($out[$field]) > $max) {
                $out[$field] = rtrim(mb_substr($out[$field], 0, $max - 1)).'…';
            }
        }

        return $out['title'] === null ? null : $out;
    }

    /** Plain text: no markup, no control characters, one line unless asked. */
    public static function text(string $s, bool $multiline = false): string
    {
        $s = mb_scrub($s, 'UTF-8');
        // Decode first so an encoded tag ("&lt;script&gt;") cannot survive
        // as a tag once something else decodes it.
        $s = html_entity_decode($s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $s = strip_tags($s);
        $s = preg_replace($multiline ? '/[^\P{C}\n]/u' : '/\p{C}/u', ' ', $s) ?? '';

        return $multiline
            ? trim(preg_replace(['/[^\S\n]+/u', '/\n{3,}/'], [' ', "\n\n"], $s) ?? '')
            : trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** "10.1000/XYZ" from "https://doi.org/10.1000/xyz", "doi:10.1000/xyz" …; null if not a DOI. */
    public static function doi(?string $doi): ?string
    {
        if ($doi === null) {
            return null;
        }

        $doi = preg_replace('~^(?:https?://(?:dx\.)?doi\.org/|doi:\s*)~i', '', trim($doi)) ?? '';

        return preg_match('~^10\.\d{4,9}/\S+$~', $doi) ? $doi : null;
    }

    /** An http(s) address or nothing — never javascript:, data: or a bare path. */
    public static function url(?string $url): ?string
    {
        if ($url === null || ! preg_match('~^https?://~i', $url)) {
            return null;
        }

        return filter_var($url, FILTER_VALIDATE_URL) ? $url : null;
    }

    /** Over-long author lists end "; et al." on a name boundary, not mid-name. */
    private static function authors(?string $authors): ?string
    {
        if ($authors === null || mb_strlen($authors) <= 255) {
            return $authors;
        }

        $kept = '';
        foreach (explode('; ', $authors) as $name) {
            if (mb_strlen($kept.$name) + 12 > 255) {
                break;
            }
            $kept .= ($kept === '' ? '' : '; ').$name;
        }

        return ($kept === '' ? mb_substr($authors, 0, 240) : $kept).'; et al.';
    }

    /**
     * What makes two records the same work, strongest first: the DOI, then
     * the address, then the title folded (case, accents, punctuation).
     *
     * @param  array<string, mixed>  $entry
     * @return list<string>
     */
    public static function keys(array $entry): array
    {
        $keys = [];

        if ($doi = self::doi($entry['doi'] ?? null)) {
            $keys[] = 'doi:'.mb_strtolower($doi);
        }

        if ($url = self::url($entry['url'] ?? null)) {
            $u = parse_url(mb_strtolower($url));
            $host = preg_replace('/^www\./', '', $u['host'] ?? '');
            $keys[] = 'url:'.$host.rtrim($u['path'] ?? '', '/').(isset($u['query']) ? '?'.$u['query'] : '');
        }

        $title = trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', Str::lower(Str::ascii((string) ($entry['title'] ?? '')))) ?? '');
        if ($title !== '') {
            $keys[] = 'title:'.$title;
        }

        return $keys;
    }
}
