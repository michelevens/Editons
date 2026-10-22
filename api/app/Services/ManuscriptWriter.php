<?php

namespace App\Services;

use App\Models\ManuscriptSection;
use App\Models\ManuscriptVersion;
use App\Publishing\Numbering;
use Illuminate\Support\Facades\DB;

/**
 * Saves manuscript content and decides when a version is worth keeping.
 *
 * Lives in a service rather than the controller because two rules sit here
 * that are easy to get wrong and must not be duplicated: when a snapshot is
 * taken, and how version numbers stay unique under concurrent saves.
 */
class ManuscriptWriter
{
    /**
     * Minimum gap between autosave snapshots of the same section.
     *
     * Autosave fires every few seconds while someone types. Snapshotting each
     * one would turn version history into a keystroke log — technically
     * complete, useless to read. Five minutes keeps the list scannable while
     * bounding how much work a crash can lose.
     *
     * Explicit saves ignore this entirely: if a human asked, they get one.
     */
    public const AUTOSAVE_VERSION_INTERVAL_SECONDS = 300;

    /**
     * Write content to a section, snapshotting first when warranted.
     *
     * The snapshot captures the state BEFORE the write, so a version always
     * represents something the author can return to. Snapshotting after would
     * record the state they are trying to escape.
     *
     * @param  array|null  $content  ProseMirror document JSON
     * @param  'autosave'|'manual'|'import'|'restore'|'ai_suggestion'|'ai_draft'  $reason
     *
     * The two ai_ reasons mark text that came from a reviewer or a generator
     * rather than from the author. Kept distinct from `manual` because an
     * author may have to declare AI assistance, and a declaration is only
     * honest if the history is — the distinction cannot be reconstructed
     * once it has been recorded as something else.
     */
    public function save(
        ManuscriptSection $section,
        ?array $content,
        ?string $title = null,
        string $reason = 'autosave',
        ?int $userId = null,
        ?string $label = null,
    ): ManuscriptSection {
        return DB::transaction(function () use ($section, $content, $title, $reason, $userId, $label) {
            // Lock the row: two autosaves racing could otherwise both read
            // the same version_number and one would violate the unique index.
            $section = ManuscriptSection::lockForUpdate()->findOrFail($section->id);

            $contentChanged = ! $this->sameDocument($section->content, $content);
            $titleChanged = $title !== null && $title !== $section->title;

            if (($contentChanged || $titleChanged) && $this->shouldSnapshot($section, $reason)) {
                $this->snapshot($section, $reason, $userId, $label);
            }

            $section->fill(array_filter([
                'title' => $title,
            ], fn ($v) => $v !== null));

            // Captions, numbered equations and headings get the label a
            // cross-reference points at, here on the chapter's own save,
            // under its lock. Reading the book for cross-references used to
            // give them, and wrote other chapters back from a stale copy.
            $section->content = Numbering::label($content) ?? $content;
            $section->word_count = ManuscriptSection::countWords($content);
            $section->save();

            // Book-level count is denormalised; keep it honest.
            //
            // Load the book fresh rather than through $section->book. The
            // relation returns a DIFFERENT instance from any Book the caller
            // already holds, so updating it left the caller's copy — and
            // anything that had eager-loaded it — reading a stale 0. Caught
            // by the roll-up test, which asserted on the caller's instance.
            $section->book()->first()->recalculateWordCount();

            return $section->refresh();
        });
    }

    /**
     * Are two ProseMirror documents the same?
     *
     * NOT a json_encode comparison. Postgres jsonb does not preserve key
     * order — a document saved as {"type":"text","text":"x"} comes back as
     * {"text":"x","type":"text"} — so encoding both and comparing strings
     * reports every save as a change, and every save then cut a version.
     *
     * Recursive ksort normalises ordering at every depth before comparing.
     * PHP's == on arrays is order-insensitive for string keys but not for the
     * numeric keys ProseMirror uses for child nodes, where order is meaning,
     * so sorting only the string keys is deliberate.
     */
    private function sameDocument(?array $a, ?array $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $this->normalise($a) === $this->normalise($b);
    }

    private function normalise(array $node): array
    {
        // Sort string keys only. A list of child nodes is ordered data and
        // reordering it would make two genuinely different documents equal.
        $isList = array_is_list($node);

        if (! $isList) {
            ksort($node);
        }

        foreach ($node as $k => $v) {
            if (is_array($v)) {
                $node[$k] = $this->normalise($v);
            }
        }

        return $node;
    }

    /**
     * Should this save produce a version?
     *
     * Never for a section that has no prior state — there is nothing to
     * preserve, and an empty "version 1" is noise. Always for an explicit
     * save. For autosave, only once the interval has elapsed.
     */
    private function shouldSnapshot(ManuscriptSection $section, string $reason): bool
    {
        if ($section->content === null && $section->versions()->doesntExist()) {
            return false;
        }

        if ($reason !== 'autosave') {
            return true;
        }

        $last = $section->versions()->first();

        return ! $last
            || $last->created_at->diffInSeconds(now()) >= self::AUTOSAVE_VERSION_INTERVAL_SECONDS;
    }

    /** Write the immutable snapshot. Caller holds the row lock. */
    private function snapshot(
        ManuscriptSection $section,
        string $reason,
        ?int $userId,
        ?string $label,
    ): ManuscriptVersion {
        $next = (int) $section->versions()->max('version_number') + 1;

        return ManuscriptVersion::create([
            'manuscript_section_id' => $section->id,
            'user_id' => $userId,
            'version_number' => $next,
            'title' => $section->title,
            'content' => $section->content,
            'word_count' => $section->word_count,
            'reason' => $reason,
            'label' => $label,
        ]);
    }

    /**
     * Restore a section to an earlier version.
     *
     * Restoring is itself a save with reason 'restore', so the state being
     * replaced is snapshotted first. Nothing is lost by going back — the
     * version you left is still in the list.
     */
    public function restore(
        ManuscriptSection $section,
        ManuscriptVersion $version,
        ?int $userId = null,
    ): ManuscriptSection {
        return $this->save(
            section: $section,
            content: $version->content,
            title: $version->title,
            reason: 'restore',
            userId: $userId,
            label: "Restored from v{$version->version_number}",
        );
    }
}
