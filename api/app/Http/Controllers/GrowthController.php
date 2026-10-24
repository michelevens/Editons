<?php

namespace App\Http\Controllers;

use App\Billing\CreditLedger;
use App\Billing\Entitlements;
use App\Marketing\KitGenerator;
use App\Models\Book;
use App\Platform\Features;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

/**
 * Notes, the Marketing Kit, and the screens that are not built.
 *
 * The last of those is the point of `locked()`. Audiobook Studio, Course
 * Builder and Author Website are in the prototype and in the menu, and
 * pretending otherwise by hiding them would make the product impossible to
 * judge. What they must not do is pretend to work: each says what it would
 * do, which plan it belongs to, and that it is not built — rather than
 * showing a disabled button or an empty state that looks like a bug.
 */
class GrowthController extends Controller
{
    public function __construct(
        private readonly KitGenerator $generator,
        private readonly CreditLedger $ledger,
        private readonly Entitlements $entitlements,
    ) {}

    // --- Notes ----------------------------------------------------------

    public function notes(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json(['notes' => $book->notes ?? '']);
    }

    public function saveNotes(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'notes' => ['present', 'nullable', 'string', 'max:20000'],
        ]);

        // Coalesced to '' rather than stored as null: `nullable` turns an
        // empty string into null, and then clearing the notes and never
        // having written any become indistinguishable. The screen shows a
        // textarea either way, so the distinction is not worth keeping — but
        // round-tripping what was sent is.
        //
        // Not versioned, unlike the manuscript: these are the author's
        // working notes about the book rather than part of it, and a version
        // history of a scratchpad is noise.
        $book->update(['notes' => $data['notes'] ?? '']);

        return response()->json(['notes' => $book->fresh()->notes ?? '']);
    }

    // --- Marketing kit --------------------------------------------------

    public function kit(Book $book): JsonResponse
    {
        $this->authorize('view', $book);

        return response()->json($this->kitPayload($book));
    }

    public function generateKit(Request $request, Book $book): JsonResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'audience' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        // Charged like any other generated work, and checked before it runs
        // so the author is told up front rather than part way through.
        $this->entitlements->assertCanAfford($request->user(), 'marketing_kit');

        $charge = $this->ledger->spend(
            user: $request->user(),
            label: 'marketing_kit',
            subject: $book,
        );

        try {
            $kit = $this->generator->generate($book, $data['audience'] ?? null);
        } catch (Throwable $e) {
            // Refunded, for the same reason a failed review is: nobody should
            // pay for work that did not happen.
            $this->ledger->refund($charge, 'Marketing kit failed');

            return response()->json(['message' => $e->getMessage()], 422);
        }

        $book->update(['marketing_kit' => $kit]);

        return response()->json($this->kitPayload($book->fresh()));
    }

    /** @return array<string, mixed> */
    private function kitPayload(Book $book): array
    {
        return [
            'kit' => $book->marketing_kit,
            'cost' => config('billing.costs.marketing_kit'),
            'has_chapters' => $book->sections()->where('type', '!=', 'part')->exists(),
        ];
    }

    // --- The screens that are not built ---------------------------------

    /**
     * What a screen would do, and why it is not here yet.
     *
     * Honest rather than decorative: each names the plan it belongs to, so
     * the entry in the menu is informative instead of a dead end.
     */
    public function locked(Book $book, string $screen): JsonResponse
    {
        $this->authorize('view', $book);

        $screens = [
            'audio' => [
                'title' => 'Audiobook Studio',
                'summary' => 'Narrate the book with a synthetic voice, chapter by chapter, and export audio for the audiobook stores.',
                'plan' => 'author',
                'needs' => 'A text-to-speech provider, and a per-minute cost this product has not set yet.',
            ],
            'course' => [
                'title' => 'Course Builder',
                'summary' => 'Turn the manuscript into lessons with exercises and quizzes, built from the chapters you already have.',
                'plan' => 'studio',
                'needs' => 'A lesson and quiz model, and somewhere to publish a course to.',
            ],
            'site' => [
                'title' => 'Author Website',
                'summary' => 'A one-page site for the book, with a newsletter sign-up and links to every store it is on sale in.',
                'plan' => 'author',
                'needs' => 'Hosting, a subdomain, and an email list the sign-up can write to.',
            ],
        ];

        $detail = $screens[$screen] ?? abort(404);

        // A coming-soon entry the console has switched off is gone, not locked.
        abort_unless(Features::enabled(['audio' => 'audiobook', 'course' => 'course_builder', 'site' => 'author_site'][$screen], request()->user()), 404);

        $plan = config("billing.plans.{$detail['plan']}");

        return response()->json([
            'screen' => $screen,
            'title' => $detail['title'],
            'summary' => $detail['summary'],
            'needs' => $detail['needs'],
            'plan' => [
                'key' => $detail['plan'],
                'name' => $plan['name'],
                'price' => $plan['price'],
            ],
            // Whether this account would already be entitled to it. The
            // screen can then avoid telling somebody on Studio to upgrade.
            'entitled' => $this->entitlements->tier($book->user) >= ($plan['tier'] ?? 0),
        ]);
    }
}
