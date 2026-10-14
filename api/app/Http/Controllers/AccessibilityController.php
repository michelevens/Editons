<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Publishing\AccessibilityAudit;
use App\Services\ManuscriptWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accessibility.
 *
 * The checks are computed on every request rather than stored: all of them go
 * stale the moment a chapter is edited, and a tick beside a check that no
 * longer holds is worse than no check.
 *
 * Writing alt text goes through the versioned writer, like any other change
 * to a manuscript. It is an edit to the book, not a setting.
 */
class AccessibilityController extends Controller
{
    public function __construct(
        private readonly AccessibilityAudit $audit,
        private readonly ManuscriptWriter $writer,
    ) {}

    public function show(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->audit->run($book));
    }

    /**
     * Describe one image.
     *
     * Addressed by its position among the images in that section, because
     * ProseMirror nodes carry no stable id. That is sound as long as the
     * client is working from a current audit — and if the manuscript moved
     * underneath it, the index may name a different image, so a mismatch is
     * refused rather than written to the wrong one.
     */
    public function describeImage(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'index' => ['required', 'integer', 'min:0'],
            // Empty is allowed and meaningful: it declares the image
            // decorative, which is a real answer rather than a missing one.
            'alt' => ['present', 'nullable', 'string', 'max:500'],
            // What the client believed it was describing. Optional, but when
            // given it is checked before anything is written.
            'src' => ['nullable', 'string'],
        ]);

        $content = $section->content ?? ['type' => 'doc', 'content' => []];
        $seen = 0;
        $found = false;

        $updated = $this->mapImages(
            $content,
            $seen,
            function (array $node) use ($data, &$found): array {
                if (isset($data['src']) && ($node['attrs']['src'] ?? '') !== $data['src']) {
                    // The image at this position is not the one the client
                    // meant. Leave it alone.
                    return $node;
                }

                $node['attrs']['alt'] = (string) ($data['alt'] ?? '');
                $found = true;

                return $node;
            },
            $data['index'],
        );

        if (! $found) {
            return response()->json([
                'message' => 'That image is no longer where it was. Reload the page and try again.',
            ], 409);
        }

        $this->writer->save(
            section: $section,
            content: $updated,
            reason: 'manual',
            userId: $request->user()->id,
            label: 'Image description',
        );

        return response()->json($this->audit->run($section->book));
    }

    /**
     * Apply a callback to the nth image in the tree.
     *
     * @param  array<string, mixed>  $node
     * @return array<string, mixed>
     */
    private function mapImages(array $node, int &$seen, callable $callback, int $target): array
    {
        if (($node['type'] ?? '') === 'image') {
            if ($seen === $target) {
                $node = $callback($node);
            }

            $seen++;

            return $node;
        }

        foreach ($node['content'] ?? [] as $index => $child) {
            if (is_array($child)) {
                $node['content'][$index] = $this->mapImages($child, $seen, $callback, $target);
            }
        }

        return $node;
    }
}
