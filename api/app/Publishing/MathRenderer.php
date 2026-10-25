<?php

namespace App\Publishing;

use Illuminate\Support\Facades\Log;
use Symfony\Component\Process\Process;

/**
 * Typesets LaTeX for the exports, through resources/node/math.cjs.
 *
 * The canonical content stores only the LaTeX (ADR-0002: the document is the
 * source, renderers derive from it). Every export asks this for what its
 * format needs: SVG for print, where the PDF renderer has no math engine;
 * MathML for EPUB and HTML, which readers render natively and screen readers
 * can speak.
 *
 * Results are cached by their LaTeX, so re-exporting a 300-equation thesis
 * does not typeset 300 equations again. When Node is unavailable the caller
 * gets null and shows the LaTeX itself: an equation printed as its source is
 * a visible, fixable problem; an equation silently dropped is not.
 */
class MathRenderer
{
    /** @var array<string, array<string, mixed>|null> */
    private array $memo = [];

    /**
     * @param  array<int, array{latex: string, display: bool}>  $items
     * @return array<int, array<string, mixed>|null> keyed like $items
     */
    public function render(array $items): array
    {
        $missing = [];

        foreach ($items as $index => $item) {
            $key = $this->key($item);

            if (! array_key_exists($key, $this->memo) && ($cached = $this->fromCache($key)) !== null) {
                $this->memo[$key] = $cached;
            }

            if (! array_key_exists($key, $this->memo)) {
                $missing[$key] = ['latex' => $item['latex'], 'display' => (bool) $item['display']];
            }
        }

        if ($missing) {
            $results = $this->typeset(array_values($missing));

            foreach (array_keys($missing) as $i => $key) {
                $result = $results[$i] ?? null;
                $this->memo[$key] = $result;

                if ($result !== null) {
                    $this->toCache($key, $result);
                }
            }
        }

        return array_map(fn ($item) => $this->memo[$this->key($item)] ?? null, $items);
    }

    /** @return array<string, mixed>|null */
    public function one(string $latex, bool $display): ?array
    {
        return $this->render([['latex' => $latex, 'display' => $display]])[0];
    }

    /**
     * @param  array<int, array{latex: string, display: bool}>  $items
     * @return array<int, array<string, mixed>|null>
     */
    private function typeset(array $items): array
    {
        $process = new Process(
            [config('publishing.node_binary', 'node'), resource_path('node/math.cjs')],
            base_path(),
            null,
            json_encode($items),
            (int) config('publishing.math_timeout', 60),
        );

        try {
            $process->run();
        } catch (\Throwable $e) {
            Log::warning('Math typesetting could not start', ['error' => $e->getMessage()]);

            return array_fill(0, count($items), null);
        }

        $results = json_decode($process->getOutput(), true);

        if (! $process->isSuccessful() || ! is_array($results)) {
            Log::warning('Math typesetting failed', ['error' => mb_substr($process->getErrorOutput(), 0, 500)]);

            return array_fill(0, count($items), null);
        }

        // An equation that does not parse comes back as {error}: kept, so the
        // export shows the source and the message rather than nothing.
        return $results;
    }

    /**
     * Bumped whenever math.cjs changes what it produces, so equations cached
     * by an older typesetter are set again rather than served stale.
     */
    private const VERSION = 2;

    /** @param array{latex: string, display: bool} $item */
    private function key(array $item): string
    {
        return sha1('v'.self::VERSION.'|'.($item['display'] ? 'D' : 'I').'|'.$item['latex']);
    }

    private function path(string $key): string
    {
        return storage_path('framework/cache/math/'.substr($key, 0, 2).'/'.$key.'.json');
    }

    /** @return array<string, mixed>|null */
    private function fromCache(string $key): ?array
    {
        $path = $this->path($key);

        // A cache file another process is still writing is a miss, not a
        // failed export.
        $json = is_file($path) ? @file_get_contents($path) : false;

        return $json === false ? null : json_decode($json, true);
    }

    /** @param array<string, mixed> $result */
    private function toCache(string $key, array $result): void
    {
        $path = $this->path($key);

        if (! is_dir(dirname($path))) {
            @mkdir(dirname($path), 0755, true);
        }

        @file_put_contents($path, json_encode($result));
    }
}
