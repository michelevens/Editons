<?php

namespace App\Platform\Trust;

use Illuminate\Support\Str;

/**
 * The application log, read back for the owner, with secrets taken out.
 *
 * Laravel writes wherever LOG_CHANNEL says. A file channel (single, daily)
 * leaves files under storage/logs that can be read here; a stream channel
 * (stderr, which is what a container platform collects) leaves nothing on
 * disk, and the page says so rather than showing an empty list as if all
 * were quiet. Railway keeps that stream in its own log viewer.
 *
 * Only the tail of a file is read (the last 2 MB at most), so a log that
 * grew for a month cannot take the request down with it. Every line is
 * redacted before it leaves this class: bearer tokens, API keys, passwords
 * and secrets in key=value or JSON form, signed-URL signatures, card-like
 * numbers and email addresses.
 */
final class LogViewer
{
    public const LEVELS = ['emergency', 'alert', 'critical', 'error', 'warning', 'notice', 'info', 'debug'];

    private const TAIL_BYTES = 2 * 1024 * 1024;

    private const MAX_LINES = 1000;

    private readonly string $directory;

    /** The folder read; storage/logs unless a test says otherwise. */
    public function __construct(?string $directory = null)
    {
        $this->directory = $directory ?? storage_path('logs');
    }

    /**
     * What the configured channel writes to.
     *
     * @return array{channel: string, drivers: list<string>, files: list<array{name: string, bytes: int, modified: int}>, stream_only: bool}
     */
    public function describe(): array
    {
        $channel = (string) config('logging.default');
        $drivers = $this->drivers($channel);
        $files = $this->files();

        return [
            'channel' => $channel,
            'drivers' => $drivers,
            'files' => $files,
            // Nothing on disk to read: everything goes to a stream.
            'stream_only' => array_intersect($drivers, ['single', 'daily']) === [] && $files === [],
        ];
    }

    /**
     * The newest entries of one file, filtered, newest first.
     *
     * @return list<array{at: ?string, env: ?string, level: string, message: string}>
     */
    public function tail(?string $file, int $limit = 200, ?string $level = null, ?string $search = null): array
    {
        $path = $this->path($file);

        if (! $path) {
            return [];
        }

        $limit = max(1, min(self::MAX_LINES, $limit));
        $entries = $this->parse($this->readTail($path));

        if ($level && in_array($level, self::LEVELS, true)) {
            // A level and everything more severe: "warning" shows errors too.
            $allowed = array_slice(self::LEVELS, 0, array_search($level, self::LEVELS, true) + 1);
            $entries = array_filter($entries, fn ($e) => in_array($e['level'], $allowed, true));
        }

        $entries = array_map(fn ($e) => ['message' => self::redact($e['message'])] + $e, $entries);

        if (filled($search)) {
            // Searched after redaction: a secret cannot be found by guessing it.
            $entries = array_filter($entries, fn ($e) => Str::contains($e['message'], (string) $search, ignoreCase: true));
        }

        return array_slice(array_reverse(array_values($entries)), 0, $limit);
    }

    /** Remove anything that could let somebody in, or name somebody. */
    public static function redact(string $text): string
    {
        $patterns = [
            // Authorization: Bearer xxx / Basic xxx
            '/\b(Bearer|Basic)\s+[A-Za-z0-9._~+\/=-]{8,}/i' => '$1 [redacted]',
            // "password":"x", password=x, api_key: x, secret, token …
            '/(["\']?(?:[a-z_]*(?:password|passwd|secret|token|api[_-]?key|apikey|authorization|cookie|signature|private[_-]?key|access[_-]?key|client[_-]?secret)[a-z_]*)["\']?\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^\s,&}\]]+)/i' => '$1[redacted]',
            // Provider keys by their prefixes.
            '/\b(sk|pk|rk|whsec|re)_(live|test)?_?[A-Za-z0-9]{12,}\b/' => '[redacted-key]',
            '/\bsk-ant-[A-Za-z0-9_-]{10,}/' => '[redacted-key]',
            '/\bsk-[A-Za-z0-9_-]{20,}/' => '[redacted-key]',
            '/\bAKIA[0-9A-Z]{16}\b/' => '[redacted-key]',
            '/\bbase64:[A-Za-z0-9+\/=]{20,}/' => '[redacted-key]',
            // JWTs.
            '/\beyJ[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}\.[A-Za-z0-9_-]{8,}/' => '[redacted-jwt]',
            // Signed URL parameters and session ids in query strings.
            '/([?&](?:signature|token|key|code|session_id)=)[^&\s"\']+/i' => '$1[redacted]',
            // Email addresses.
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '[email]',
            // Card-like digit runs (13–19 digits, spaces or dashes allowed).
            '/\b(?:\d[ -]?){13,19}\b/' => '[number]',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }

    /** @return list<string> */
    private function drivers(string $channel, int $depth = 0): array
    {
        $config = config("logging.channels.{$channel}");

        if (! is_array($config) || $depth > 3) {
            return [];
        }

        if (($config['driver'] ?? null) === 'stack') {
            $out = [];

            foreach ((array) ($config['channels'] ?? []) as $inner) {
                $out = [...$out, ...$this->drivers(trim((string) $inner), $depth + 1)];
            }

            return array_values(array_unique($out));
        }

        // A monolog channel writing to php://stderr is the container stream.
        if (($config['driver'] ?? null) === 'monolog') {
            $stream = (string) ($config['handler_with']['stream'] ?? $config['with']['stream'] ?? '');

            return [str_contains($stream, 'stderr') ? 'stderr' : (str_contains($stream, 'stdout') ? 'stdout' : 'monolog')];
        }

        return [(string) ($config['driver'] ?? $channel)];
    }

    /** @return list<array{name: string, bytes: int, modified: int}> */
    private function files(): array
    {
        $dir = $this->directory;
        $out = [];

        foreach (glob($dir.DIRECTORY_SEPARATOR.'*.log') ?: [] as $path) {
            $out[] = ['name' => basename($path), 'bytes' => (int) @filesize($path), 'modified' => (int) @filemtime($path)];
        }

        usort($out, fn ($a, $b) => $b['modified'] <=> $a['modified']);

        return $out;
    }

    /** The full path of a log file by name, only ever inside storage/logs. */
    private function path(?string $file): ?string
    {
        $files = array_column($this->files(), 'name');
        $name = $file ?: ($files[0] ?? null);

        if (! $name || ! in_array($name, $files, true)) {
            return null;
        }

        return $this->directory.DIRECTORY_SEPARATOR.$name;
    }

    private function readTail(string $path): string
    {
        $size = (int) @filesize($path);
        $handle = @fopen($path, 'rb');

        if (! $handle) {
            return '';
        }

        $start = max(0, $size - self::TAIL_BYTES);
        fseek($handle, $start);
        $text = (string) stream_get_contents($handle);
        fclose($handle);

        // A cut-off first line is not an entry.
        if ($start > 0 && ($newline = strpos($text, "\n")) !== false) {
            $text = substr($text, $newline + 1);
        }

        return $text;
    }

    /**
     * Laravel's line format: "[2026-10-24 10:00:00] production.ERROR: message".
     * Lines that do not start an entry (stack frames) belong to the one above.
     *
     * @return list<array{at: ?string, env: ?string, level: string, message: string}>
     */
    private function parse(string $text): array
    {
        $entries = [];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            if (preg_match('/^\[(\d{4}-\d{2}-\d{2}[ T][\d:.+\-]+)\]\s+(\w+)\.(\w+):\s?(.*)$/', $line, $m)) {
                $entries[] = ['at' => $m[1], 'env' => $m[2], 'level' => strtolower($m[3]), 'message' => $m[4]];
            } elseif ($entries !== [] && $line !== '') {
                $last = count($entries) - 1;

                // A stack trace is kept short: the first lines say where.
                if (substr_count($entries[$last]['message'], "\n") < 12) {
                    $entries[$last]['message'] .= "\n".$line;
                }
            }
        }

        return $entries;
    }
}
