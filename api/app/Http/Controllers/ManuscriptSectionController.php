<?php

namespace App\Http\Controllers;

use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Services\ManuscriptWriter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ManuscriptSectionController extends Controller
{
    public function __construct(private readonly ManuscriptWriter $writer) {}

    public function store(Request $request, Book $book): RedirectResponse
    {
        $this->authorize('update', $book);

        $data = $request->validate([
            'type' => ['required', 'in:'.implode(',', ManuscriptSection::TYPES)],
            'title' => ['required', 'string', 'max:255'],
            'parent_id' => ['nullable', 'integer'],
        ]);

        // Validate the parent belongs to THIS book. Without it, a crafted
        // parent_id would graft a section onto someone else's manuscript —
        // the policy above only checked the book in the URL.
        $parentId = null;
        if (! empty($data['parent_id'])) {
            $parent = $book->sections()->whereKey($data['parent_id'])->firstOrFail();
            $parentId = $parent->id;
        }

        // parent_id is not mass-assignable (see Scaffolder::chapter), so it
        // is set explicitly: passed to create() it was silently dropped and
        // a chapter added inside a part landed at the top level.
        $section = $book->sections()->make([
            'type' => $data['type'],
            'title' => $data['title'],
            'position' => ManuscriptSection::nextPosition($book->id, $parentId),
        ]);
        $section->forceFill(['parent_id' => $parentId])->save();

        return redirect()->route('books.edit', ['book' => $book, 'section' => $section->id]);
    }

    /**
     * Autosave endpoint.
     *
     * Returns JSON rather than an Inertia redirect: this fires while someone
     * is typing, and a page visit would discard their cursor position.
     */
    public function save(Request $request, ManuscriptSection $section): JsonResponse
    {
        $this->authorize('update', $section);

        $data = $request->validate([
            'content' => ['nullable', 'array'],
            'title' => ['nullable', 'string', 'max:255'],
            // `review`: suggestions accepted or rejected in the editor. A
            // deliberate act, so it always keeps a version, labelled with
            // what was decided — the audit trail of tracked changes.
            'reason' => ['nullable', 'in:autosave,manual,review'],
            'label' => ['nullable', 'string', 'max:120'],
        ]);

        $reason = $data['reason'] ?? 'autosave';

        $section = $this->writer->save(
            section: $section,
            content: $data['content'] ?? null,
            title: $data['title'] ?? null,
            reason: $reason,
            userId: $request->user()->id,
            label: $reason === 'review' ? ($data['label'] ?? 'Suggestions reviewed') : null,
        );

        return response()->json([
            'saved_at' => $section->updated_at->toIso8601String(),
            'word_count' => $section->word_count,
            'book_word_count' => $section->book->word_count,
            'versions_count' => $section->versions()->count(),
        ]);
    }

    /** Version history for the right-hand panel. */
    public function versions(ManuscriptSection $section): JsonResponse
    {
        $this->authorize('view', $section);

        return response()->json([
            'versions' => $section->versions()
                ->with('user:id,name')
                ->limit(50)
                ->get()
                ->map(fn (ManuscriptVersion $v) => [
                    'id' => $v->id,
                    'version_number' => $v->version_number,
                    'title' => $v->title,
                    'word_count' => $v->word_count,
                    'reason' => $v->reason,
                    'label' => $v->label,
                    'author' => $v->user?->name,
                    'created_at' => $v->created_at->toIso8601String(),
                    'created_human' => $v->created_at->diffForHumans(),
                ]),
        ]);
    }

    public function restore(Request $request, ManuscriptSection $section, ManuscriptVersion $version): JsonResponse
    {
        $this->authorize('update', $section);

        // The version must belong to this section. Route-model binding
        // resolves both independently, so nothing else stops a version id
        // from another manuscript being restored over this one.
        abort_unless($version->manuscript_section_id === $section->id, 404);

        $section = $this->writer->restore($section, $version, $request->user()->id);

        return response()->json([
            'content' => $section->content,
            'title' => $section->title,
            'word_count' => $section->word_count,
            'saved_at' => $section->updated_at->toIso8601String(),
        ]);
    }

    /**
     * Rename a section.
     *
     * JSON when asked for it: the binder renames a chapter in place and
     * reloads only itself, never the chapter open in the editor.
     */
    public function update(Request $request, ManuscriptSection $section): RedirectResponse|JsonResponse
    {
        $this->authorize('update', $section);

        $section->update($request->validate([
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'subtitle' => ['nullable', 'string', 'max:255'],
        ]));

        if ($request->wantsJson()) {
            return response()->json(['id' => $section->id, 'title' => $section->title, 'subtitle' => $section->subtitle]);
        }

        return back();
    }

    /**
     * Delete a section (soft: it stays restorable in the database).
     *
     * JSON when asked for it, for the binder's action sheet.
     */
    public function destroy(Request $request, ManuscriptSection $section): RedirectResponse|JsonResponse
    {
        $this->authorize('delete', $section);

        $book = $section->book;
        $section->delete();
        $book->recalculateWordCount();

        if ($request->wantsJson()) {
            return response()->json(['deleted' => $section->id, 'book_word_count' => $book->word_count]);
        }

        return redirect()->route('books.edit', $book);
    }
}
