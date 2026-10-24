<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\Isbn;
use App\Models\ManuscriptSection;
use App\Publishing\AiDisclosure;
use App\Publishing\IsbnPlan;
use App\Publishing\LegalDeposit;
use App\Publishing\RightsDeclaration;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

/**
 * ISBN & Rights.
 *
 * The checklist mixes two kinds of item: things derivable from the manuscript
 * (is there a copyright page) and things only the author knows (did you
 * register with the Copyright Office). The first are computed on every
 * request so they cannot go stale; the second are stored attestations.
 *
 * Nothing here gives legal advice. The checklist names what publishers
 * normally do and links to the official source.
 */
class RightsController extends Controller
{
    public function show(Request $request, Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->payload($request, $book));
    }

    /** Assign a number from the pool to one of this book's editions. */
    public function assign(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'edition_format' => ['required', 'string', 'in:'.implode(',', Book::EDITION_FORMATS)],
            'isbn_id' => ['nullable', 'integer'],
            'isbn' => ['nullable', 'string', 'max:20'],
        ]);

        $this->assertFormatFree($book, $data['edition_format']);

        if ($id = $data['isbn_id'] ?? null) {
            // Scoped to the author's own pool: an id from the request must
            // not reach another account's numbers.
            $isbn = $request->user()->isbns()->whereKey($id)->whereNull('book_id')->firstOrFail();
        } else {
            $number = Isbn::normalise((string) ($data['isbn'] ?? ''));

            if (! Isbn::isValid($number)) {
                throw ValidationException::withMessages([
                    'isbn' => 'That is not a valid ISBN-13. Check the digits.',
                ]);
            }

            if ($request->user()->isbns()->where('isbn', $number)->exists()) {
                throw ValidationException::withMessages([
                    'isbn' => 'That number is already in your account.',
                ]);
            }

            $isbn = $request->user()->isbns()->create([
                'isbn' => $number,
                'source' => 'own',
            ]);
        }

        $isbn->update([
            'book_id' => $book->id,
            'edition_format' => $data['edition_format'],
        ]);

        // The ebook ISBN is the one that goes into the EPUB and onto the
        // store listing, so it is mirrored onto the book itself.
        if ($data['edition_format'] === 'ebook') {
            $book->update(['isbn' => $isbn->isbn]);
        }

        return response()->json($this->payload($request, $book->fresh()));
    }

    /** Return a number to the pool. */
    public function unassign(Request $request, Book $book, Isbn $isbn): JsonResponse
    {
        $this->authorize('update', $book);

        abort_unless($isbn->book_id === $book->id, 404);

        $wasEbook = $isbn->edition_format === 'ebook';

        $isbn->update(['book_id' => null, 'edition_format' => null]);

        if ($wasEbook) {
            $book->update(['isbn' => null]);
        }

        return response()->json($this->payload($request, $book->fresh()));
    }

    /** Add numbers to the pool. */
    public function addToPool(Request $request): JsonResponse
    {
        $data = $request->validate([
            'isbns' => ['required', 'array', 'min:1', 'max:100'],
            'isbns.*' => ['required', 'string', 'max:20'],
        ]);

        $added = 0;
        $rejected = [];

        foreach ($data['isbns'] as $candidate) {
            $number = Isbn::normalise($candidate);

            if (! Isbn::isValid($number)) {
                $rejected[] = $candidate.' — not a valid ISBN-13';

                continue;
            }

            if ($request->user()->isbns()->where('isbn', $number)->exists()) {
                $rejected[] = $candidate.' — already in your account';

                continue;
            }

            $request->user()->isbns()->create(['isbn' => $number]);
            $added++;
        }

        // Partial success is reported rather than rolled back: pasting ten
        // numbers and having one typo discard the other nine would be worse.
        return response()->json([
            'added' => $added,
            'rejected' => $rejected,
            'pool' => $this->pool($request),
        ]);
    }

    /** Choose, per edition, the author's own ISBN or one a store assigns. */
    public function updateIsbnPlan(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'edition' => ['required', 'string', 'in:'.implode(',', Book::ISBN_EDITIONS)],
            'source' => ['required', 'string', 'in:'.implode(',', Book::ISBN_SOURCES)],
        ]);

        $book->isbn_plan = array_merge($book->isbn_plan ?? [], [$data['edition'] => $data['source']]);
        $book->save();

        return response()->json($this->payload($request, $book->fresh()));
    }

    /**
     * The author confirms they hold the rights, listing the third-party
     * material in the book and the permission held for each. Who and when
     * are recorded from the session, never from the request.
     */
    public function declareRights(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'holds_rights' => ['accepted'],
            'materials' => ['present', 'array', 'max:50'],
            'materials.*.kind' => ['required', 'string', 'in:'.implode(',', array_keys(RightsDeclaration::KINDS))],
            'materials.*.description' => ['required', 'string', 'max:500'],
            'materials.*.permission' => ['required', 'string', 'in:'.implode(',', array_keys(RightsDeclaration::PERMISSIONS))],
        ]);

        RightsDeclaration::confirm($book, $data['materials'], $request->user());

        return response()->json($this->payload($request, $book->fresh()));
    }

    /**
     * The author's answers to KDP's AI-content questions. The screen shows
     * the evidence and a suggestion; the answers are the author's own.
     */
    public function discloseAi(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $answers = 'in:'.implode(',', config('publishing.ai_disclosure.answers'));
        $data = $request->validate([
            'confirm' => ['accepted'],
            'answers' => ['required', 'array'],
            'answers.text' => ['required', 'string', $answers],
            'answers.images' => ['required', 'string', $answers],
            'answers.translations' => ['required', 'string', $answers],
        ]);

        AiDisclosure::confirm($book, Arr::only($data['answers'], AiDisclosure::categories()), $request->user());

        return response()->json($this->payload($request, $book->fresh()));
    }

    /** Tick or untick one of the manual attestations. */
    public function updateRights(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'key' => ['required', 'string', 'in:'.implode(',', array_keys(Book::DEFAULT_RIGHTS))],
            'value' => ['required', 'boolean'],
        ]);

        $book->update([
            'rights' => array_merge($book->rights(), [$data['key'] => $data['value']]),
        ]);

        return response()->json($this->payload($request, $book->fresh()));
    }

    /**
     * Insert a copyright page into the front matter.
     *
     * Deliberately plain and factual. It asserts copyright and states that
     * the book is not professional advice — both standard, neither legal
     * counsel, and the author can edit every word of it afterwards.
     */
    public function insertCopyrightPage(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $holder = $book->author_name ?: $request->user()->name;
        $year = now()->year;

        $paragraphs = [
            $book->title,
            "Copyright © {$year} {$holder}",
            'All rights reserved. No part of this publication may be reproduced, '
                .'distributed, or transmitted in any form or by any means without the '
                .'prior written permission of the copyright holder, except for brief '
                .'quotations in reviews and certain other noncommercial uses permitted '
                .'by copyright law.',
            'This book is for general information only. It is not legal, financial, '
                .'or medical advice, and it is not a substitute for advice from a '
                .'qualified professional who knows your situation.',
            'First edition, '.$year.'.',
        ];

        $existing = $book->sections()
            ->where('type', 'front_matter')
            ->where('title', 'Copyright')
            ->first();

        $content = ['type' => 'doc', 'content' => array_map(
            fn (string $text) => [
                'type' => 'paragraph',
                'content' => [['type' => 'text', 'text' => $text]],
            ],
            $paragraphs,
        )];

        if ($existing) {
            // Replacing rather than adding a second page. An author clicking
            // twice should not end up with two copyright pages.
            $existing->update(['content' => $content]);
        } else {
            $book->sections()->create([
                'title' => 'Copyright',
                'type' => 'front_matter',
                // Negative so it sorts above chapter one without renumbering
                // every section below it.
                'position' => -1,
                'content' => $content,
            ]);
        }

        return response()->json($this->payload($request, $book->fresh()));
    }

    private function assertFormatFree(Book $book, string $format): void
    {
        if ($book->isbns()->where('edition_format', $format)->exists()) {
            throw ValidationException::withMessages([
                'edition_format' => 'That edition already has an ISBN. Unassign it first.',
            ]);
        }
    }

    /** @return array<int, array<string, mixed>> */
    private function pool(Request $request): array
    {
        return $request->user()->isbns()
            ->whereNull('book_id')
            ->orderBy('isbn')
            ->get()
            ->map(fn (Isbn $isbn) => [
                'id' => $isbn->id,
                'isbn' => $isbn->isbn,
                'source' => $isbn->source,
            ])
            ->all();
    }

    /** @return array<string, mixed> */
    private function payload(Request $request, Book $book): array
    {
        $assigned = $book->isbns()->get()->keyBy('edition_format');
        $rights = $book->rights();

        $frontMatter = $book->sections()
            ->where('type', 'front_matter')
            ->get()
            ->map(fn (ManuscriptSection $section) => $section->plainText())
            ->implode("\n");

        $imageCount = $book->sections()->get()
            ->sum(fn (ManuscriptSection $section) => $this->countImages($section->content ?? []));

        return [
            'editions' => collect(Book::EDITION_FORMATS)
                ->map(function (string $format) use ($assigned) {
                    $isbn = $assigned->get($format);

                    return [
                        'format' => $format,
                        'label' => self::FORMAT_LABELS[$format],
                        'isbn' => $isbn?->isbn,
                        'isbn_id' => $isbn?->id,
                    ];
                })
                ->all(),
            'pool' => $this->pool($request),
            'checklist' => [
                [
                    'key' => 'copyright_page',
                    'label' => 'Copyright page in the front matter',
                    // Computed every time: an author who deletes the page
                    // should see the check go back to unmet.
                    'done' => str_contains($frontMatter, 'Copyright ©'),
                    'action' => 'insert_copyright',
                    'action_label' => 'Insert copyright page',
                ],
                [
                    'key' => 'disclaimer',
                    'label' => 'A disclaimer saying the book is not professional advice',
                    'done' => str_contains(strtolower($frontMatter), 'not legal'),
                    'action' => 'insert_copyright',
                    'action_label' => 'Insert with the copyright page',
                ],
                [
                    'key' => 'registered_copyright',
                    'label' => 'Registered with the U.S. Copyright Office',
                    'done' => (bool) $rights['registered_copyright'],
                    'action' => 'attest',
                    'action_label' => 'Mark done',
                ],
                [
                    'key' => 'permissions_cleared',
                    'label' => 'Permission obtained for quoted material',
                    'done' => (bool) $rights['permissions_cleared'],
                    'action' => 'attest',
                    'action_label' => 'Mark done',
                ],
                [
                    'key' => 'image_licenses',
                    'label' => 'Licences held for every image',
                    // No images means nothing to licence, so the item is met
                    // rather than permanently outstanding.
                    'done' => $imageCount === 0 || (bool) $rights['image_licenses'],
                    'action' => $imageCount === 0 ? null : 'attest',
                    'action_label' => 'Mark done',
                ],
            ],
            'image_count' => $imageCount,
            // Per edition: the author's own ISBN or a store's, and where to
            // get one for the country of publication.
            'isbn_plan' => IsbnPlan::plan($book),
            'isbn_agency' => IsbnPlan::agency($book),
            'declaration' => RightsDeclaration::for($book),
            'ai' => AiDisclosure::for($book) + ['required' => AiDisclosure::required($book)],
            'legal_deposit' => LegalDeposit::for($book),
            'can_edit' => $request->user()->can('update', $book),
            'registration_url' => 'https://www.copyright.gov/registration/',
        ];
    }

    /** @param array<string, mixed> $node */
    private function countImages(array $node): int
    {
        $count = ($node['type'] ?? null) === 'image' ? 1 : 0;

        foreach ($node['content'] ?? [] as $child) {
            if (is_array($child)) {
                $count += $this->countImages($child);
            }
        }

        return $count;
    }

    private const FORMAT_LABELS = [
        'ebook' => 'eBook',
        'paperback' => 'Paperback',
        'hardcover' => 'Hardcover',
        'large_print' => 'Large print',
        'audiobook' => 'Audiobook',
    ];
}
