<?php

namespace App\Publishing;

use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\ManuscriptVersion;
use App\Models\User;

/**
 * KDP's AI-content questions, answered from the book's own records.
 *
 * KDP asks whether a book's text, images or translations are AI-generated
 * ("created by an AI-based tool") and does not ask about AI-assisted work
 * (the author's own content, edited or checked with AI). The app keeps
 * provenance for each kind of AI it applies: a chapter drafted by AI is
 * saved with a version of reason `ai_draft`; an accepted AI fix points at
 * the version it replaced (`applied_version_id`); an AI cover records its
 * model in `artwork_source`. This reads those records, shows them as
 * evidence, and suggests the answer they support.
 *
 * The suggestion is never the answer. The author reads the evidence and
 * confirms each answer themselves; the confirmation records who, when and
 * what they were shown. Text written elsewhere with AI and pasted in, for
 * example, leaves no record here, which is why the author decides.
 */
class AiDisclosure
{
    /** @return array<string, mixed> */
    public static function evidence(Book $book): array
    {
        $sections = $book->sections()->withTrashed()->pluck('id');
        $drafts = ManuscriptVersion::whereIn('manuscript_section_id', $sections)->where('reason', 'ai_draft');
        $fixes = EditorialFinding::whereIn('manuscript_section_id', $sections)
            ->where('status', 'accepted')
            ->whereNotNull('applied_version_id');
        $inline = ManuscriptVersion::whereIn('manuscript_section_id', $sections)->where('reason', 'ai_suggestion');
        $source = ($book->cover ?? [])['artwork_source'] ?? null;
        $aiCover = is_array($source) && ($source['type'] ?? null) === 'ai' && ! empty(($book->cover ?? [])['artwork']);

        return [
            'text' => [
                'ai_drafted_chapters' => (clone $drafts)->distinct()->count('manuscript_section_id'),
                'ai_drafts' => (clone $drafts)->count(),
                'accepted_ai_fixes' => $fixes->count(),
                'ai_suggestions_applied' => $inline->count(),
            ],
            'images' => [
                'ai_cover' => $aiCover ? ['model' => $source['model'] ?? null, 'created_at' => $source['created_at'] ?? null] : null,
            ],
            'translations' => [
                // A translation edition starts as the source text marked for
                // translation; no AI translation runs in the app.
                'translation_edition' => $book->edition_type === 'translation',
            ],
        ];
    }

    /**
     * What the evidence supports, per category: none, assisted or generated.
     *
     * @return array{text: string, images: string, translations: string}
     */
    public static function suggested(array $evidence): array
    {
        $text = $evidence['text'];

        return [
            'text' => $text['ai_drafts'] > 0 ? 'generated'
                : ($text['accepted_ai_fixes'] + $text['ai_suggestions_applied'] > 0 ? 'assisted' : 'none'),
            'images' => $evidence['images']['ai_cover'] ? 'generated' : 'none',
            'translations' => 'none',
        ];
    }

    /** @return array<string, mixed> the screen's view: evidence, suggestion, confirmation */
    public static function for(Book $book): array
    {
        $evidence = self::evidence($book);
        $suggested = self::suggested($evidence);
        $saved = $book->ai_disclosure ?? null;
        $answers = $saved['answers'] ?? null;

        return [
            'evidence' => $evidence,
            'suggested' => $suggested,
            'answers' => $answers,
            'confirmed_by' => $saved['confirmed_name'] ?? null,
            'confirmed_at' => $saved['confirmed_at'] ?? null,
            // The records now show more AI than the author declared.
            'outdated' => $answers ? self::understated($answers, $suggested) : [],
            'kdp' => self::kdpAnswers($answers ?? $suggested),
            'source' => config('publishing.ai_disclosure.source'),
        ];
    }

    /**
     * Record the author's answers.
     *
     * @param  array{text: string, images: string, translations: string}  $answers
     */
    public static function confirm(Book $book, array $answers, User $user): void
    {
        $evidence = self::evidence($book);

        $book->ai_disclosure = [
            'answers' => $answers,
            'suggested' => self::suggested($evidence),
            'evidence' => $evidence,
            'confirmed_by' => $user->id,
            'confirmed_name' => $user->name,
            'confirmed_at' => now()->toIso8601String(),
        ];
        $book->save();
    }

    public static function confirmed(Book $book): bool
    {
        return isset(($book->ai_disclosure ?? [])['confirmed_at']);
    }

    /** Whether a store this book is going to asks the question (KDP). */
    public static function required(Book $book): bool
    {
        return $book->channels()
            ->whereIn('channel', ['amazon_kdp', 'kdp_paperback'])
            ->whereIn('status', ['not_submitted', 'ready_to_submit'])
            ->exists();
    }

    /**
     * Categories where the answer declares less AI than the records show.
     *
     * @return list<string>
     */
    public static function understated(array $answers, array $suggested): array
    {
        $rank = ['none' => 0, 'assisted' => 1, 'generated' => 2];

        return array_values(array_filter(
            array_keys($suggested),
            fn ($category) => ($rank[$answers[$category] ?? 'none'] ?? 0) < $rank[$suggested[$category]],
        ));
    }

    /**
     * How the answers map to KDP's form: only AI-generated content is
     * disclosed there.
     *
     * @return array<string, bool>
     */
    public static function kdpAnswers(array $answers): array
    {
        return array_map(fn ($answer) => $answer === 'generated', $answers);
    }

    /** @return list<string> */
    public static function categories(): array
    {
        return config('publishing.ai_disclosure.categories');
    }
}
