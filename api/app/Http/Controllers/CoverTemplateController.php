<?php

namespace App\Http\Controllers;

use App\Covers\CoverTemplates;
use App\Models\Book;
use App\Models\CoverTemplate;
use App\Platform\AdminAudit;
use App\Platform\House\House;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Cover templates from the studio and the shelf: save a book's cover
 * design under a name, update it from a book, preview and apply it to
 * books (one, or several from the shelf), detach, restore the cover it
 * replaced, rename and delete (App\Covers\CoverTemplates). A house book
 * changed by staff needs a reason and writes an admin audit row.
 */
class CoverTemplateController extends Controller
{
    public function __construct(private readonly CoverTemplates $templates) {}

    /** The signed-in account's templates, for the shelf's bulk action. */
    public function index(Request $request): JsonResponse
    {
        return response()->json(['templates' => CoverTemplates::listed(CoverTemplate::where('user_id', $request->user()->id))]);
    }

    /** "Save as cover template". */
    public function store(Request $request, Book $book): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:2', 'max:80'],
            'include_images' => ['sometimes', 'boolean'],
            'follow' => ['sometimes', 'boolean'],
        ]);

        $template = $this->templates->save($book, $request->user(), $data['name'], $data['include_images'] ?? false, $data['follow'] ?? true);

        return response()->json(['template' => ['id' => $template->id, 'name' => $template->name], 'summary' => CoverTemplates::summary($book->fresh(), $request->user())], 201);
    }

    /** "Update the template from this book": every book that follows it changes. */
    public function update(Request $request, CoverTemplate $template): JsonResponse
    {
        $data = $request->validate(['book' => ['required', 'integer']]);
        $book = Book::findOrFail($data['book']);
        $count = $this->templates->update($template, $book, $request->user());

        return response()->json(['updated' => $count, 'summary' => CoverTemplates::summary($book->fresh(), $request->user())]);
    }

    public function rename(Request $request, CoverTemplate $template): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:2', 'max:80']]);
        $this->templates->rename($template, $request->user(), $data['name']);

        return response()->json(['template' => ['id' => $template->id, 'name' => $template->name]]);
    }

    public function destroy(Request $request, CoverTemplate $template): JsonResponse
    {
        $this->templates->delete($template, $request->user());

        return response()->json(['deleted' => true]);
    }

    /** Thumbnails and overflow flags per book, before anything changes. */
    public function preview(Request $request, CoverTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'books' => ['required', 'array', 'min:1', 'max:'.CoverTemplates::MAX_PREVIEW],
            'books.*' => ['integer'],
            'artwork' => ['sometimes', 'nullable', 'string', Rule::in(CoverTemplates::ARTWORK_CHOICES)],
        ]);

        return response()->json(['template' => ['id' => $template->id, 'name' => $template->name], 'books' => $this->templates->preview($template, $data['books'], $request->user(), $data['artwork'] ?? null)]);
    }

    /** Apply to one book or several; each book's result on its own. */
    public function apply(Request $request, CoverTemplate $template): JsonResponse
    {
        $data = $request->validate([
            'books' => ['required', 'array', 'min:1', 'max:200'],
            'books.*' => ['integer'],
            'follow' => ['sometimes', 'boolean'],
            'artwork' => ['sometimes', 'nullable', 'string', Rule::in(CoverTemplates::ARTWORK_CHOICES)],
            'reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $reason = trim((string) ($data['reason'] ?? ''));

        if ($template->user?->isHouse() && mb_strlen($reason) < 4) {
            throw ValidationException::withMessages(['reason' => 'House books are changed with a reason, kept in the audit log.']);
        }

        $results = $this->templates->applyMany($template, $data['books'], $request->user(), $data['follow'] ?? true, $data['artwork'] ?? null,
            self::auditor($template, $data['follow'] ?? true, $data['artwork'] ?? null, $reason, $request->user()));

        return response()->json(['results' => $results]);
    }

    /** "Customise this book's cover": stop following, keep the cover. */
    public function detach(Request $request, Book $book): JsonResponse
    {
        $this->templates->detach($book, $request->user());

        return response()->json(['summary' => CoverTemplates::summary($book->fresh(), $request->user())]);
    }

    /** "Restore the previous cover": the cover the template replaced. */
    public function restore(Request $request, Book $book): JsonResponse
    {
        $this->templates->restore($book, $request->user());

        return response()->json(['summary' => CoverTemplates::summary($book->fresh(), $request->user())]);
    }

    /**
     * The audit row for each house book a template is applied to, written
     * in that book's transaction (CoverTemplates::applyMany's $each).
     */
    public static function auditor(CoverTemplate $template, bool $follow, ?string $artwork, string $reason, $actor): callable
    {
        return function (Book $book, array $change) use ($template, $follow, $artwork, $reason, $actor) {
            if (House::isHouseBook($book)) {
                [$from, $to] = AdminAudit::diff($change['before'], $change['after']);
                AdminAudit::record('house.cover_template_applied', $book, $from ?: null,
                    $to + ['cover_template' => $template->name, 'follow' => $follow, 'artwork' => $artwork], $reason, $actor);
            }
        };
    }
}
