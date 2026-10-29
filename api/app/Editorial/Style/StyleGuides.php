<?php

namespace App\Editorial\Style;

use App\Models\Book;
use App\Models\EditorialFinding;
use App\Models\StyleGuide;
use App\Models\StyleGuideVersion;
use App\Models\User;
use App\Platform\House\House;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Style guides: an account's editorial rules, followed by its books and
 * given to the agents that read them (ADR — Style guides and review
 * options).
 *
 * Ownership is ADR-0041's for house styles. A guide belongs to an account
 * (an author's, or a house account's, run by staff); only that account's
 * books follow it, and only someone who may both edit the book and manage
 * the account's guides attaches it. A guide never crosses into another
 * account's catalogue, so its terms never reach another tenant's prompts.
 *
 * Following is explicit. A book follows a guide only when someone chose it,
 * and then reads the guide's latest version at each review. The book keeps
 * its own overrides beside it — words never flagged, rules turned off, its
 * own value for any rule. "Customise for this book" detaches it and copies
 * the guide's rules into the book's own, so nothing the author relied on
 * disappears; deleting a guide does the same for every follower.
 *
 * Every save is a new immutable version; the review records the one it
 * used.
 */
class StyleGuides
{
    /** May $actor create, edit and attach this account's guides? */
    public static function canManage(User $actor, User $account): bool
    {
        return $actor->id === $account->id || House::canEditAccount($actor, $account);
    }

    /** @return \Illuminate\Support\Collection<int, StyleGuide> */
    public function forAccount(User $account)
    {
        return StyleGuide::where('user_id', $account->id)->with('current')->withCount('books')->orderBy('name')->get();
    }

    /**
     * A new guide of $account, version 1.
     *
     * @param  array<string, mixed>  $rules
     */
    public function create(User $account, User $actor, string $name, array $rules): StyleGuide
    {
        $this->authorizeAccount($actor, $account);
        $name = $this->name($account, $name);
        $rules = StyleRules::validate($rules);

        return DB::transaction(function () use ($account, $actor, $name, $rules) {
            $guide = new StyleGuide(['name' => $name]);
            $guide->user_id = $account->id;
            $guide->version = 1;
            $guide->updated_by = $actor->id;
            $guide->save();

            $guide->versions()->create(['version' => 1, 'rules' => $rules, 'created_by' => $actor->id]);

            return $guide->load('current');
        });
    }

    /**
     * Save new rules as the next version. Followers read it at their next
     * review; earlier reviews keep pointing at the version they used.
     *
     * @param  array<string, mixed>  $rules
     */
    public function save(StyleGuide $guide, User $actor, array $rules, ?string $name = null): StyleGuide
    {
        $this->authorizeAccount($actor, $guide->user);
        $rules = StyleRules::validate($rules);

        return DB::transaction(function () use ($guide, $actor, $rules, $name) {
            $guide = StyleGuide::whereKey($guide->id)->lockForUpdate()->firstOrFail();

            if ($name !== null) {
                $guide->name = $this->name($guide->user, $name, $guide->id);
            }

            $current = $guide->current?->rules;

            // Saving what is already there is not a version.
            if ($current !== null && StyleRules::normalise($current) === $rules) {
                $guide->save();

                return $guide->load('current');
            }

            $guide->version++;
            $guide->updated_by = $actor->id;
            $guide->save();
            $guide->versions()->create(['version' => $guide->version, 'rules' => $rules, 'created_by' => $actor->id]);

            return $guide->load('current');
        });
    }

    /** Delete a guide: its followers keep its rules as their own. */
    public function delete(StyleGuide $guide, User $actor): void
    {
        $this->authorizeAccount($actor, $guide->user);

        DB::transaction(function () use ($guide) {
            foreach (Book::where('style_guide_id', $guide->id)->lockForUpdate()->get() as $book) {
                $this->keepRules($book, $guide);
            }

            $guide->delete();
        });
    }

    /** The book follows this guide from its next review. */
    public function follow(StyleGuide $guide, Book $book, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $book);
        $this->authorizeAccount($actor, $guide->user);

        if ($guide->user_id !== $book->user_id) {
            throw ValidationException::withMessages(['guide' => 'A style guide can only be used by books of the account that owns it.']);
        }

        $book->forceFill(['style_guide_id' => $guide->id])->save();
    }

    /**
     * "Customise for this book": stop following, keeping the guide's rules
     * as the book's own (the book's existing overrides still win).
     */
    public function detach(Book $book, User $actor): void
    {
        Gate::forUser($actor)->authorize('update', $book);

        DB::transaction(function () use ($book) {
            if ($book->styleGuide) {
                $this->keepRules($book, $book->styleGuide);
            }
        });
    }

    /**
     * Change the book's own rules (only the keys given; null clears one).
     *
     * @param  array<string, mixed>  $rules
     */
    public function saveOverrides(Book $book, User $actor, array $rules): void
    {
        Gate::forUser($actor)->authorize('update', $book);
        $own = $this->overrides($book);
        $merged = array_merge($own['rules'], array_intersect_key($rules, StyleRules::EMPTY));
        $own['rules'] = StyleRules::validate($merged);
        $this->writeOverrides($book, $own);
    }

    /**
     * The rules a review of this book uses, and where they came from.
     *
     * @return array{rules: array<string, mixed>, disabled_rules: list<string>, version: ?StyleGuideVersion, configured: bool}
     */
    public function effective(Book $book): array
    {
        $version = $book->style_guide_id ? $book->styleGuide?->current : null;

        // A guide can only ever be its owner's: a book moved to another
        // account (a house transfer) stops reading its old owner's guide.
        if ($version && $book->styleGuide->user_id !== $book->user_id) {
            $version = null;
        }

        $own = $this->overrides($book);
        $rules = StyleRules::merge($version?->rules, $own['rules']);

        return [
            'rules' => $rules,
            'disabled_rules' => $own['disabled_rules'],
            'version' => $version,
            'configured' => $version !== null || ! StyleRules::isEmpty($rules),
        ];
    }

    /**
     * "Never flag this word": into the book's own dictionary, and its open
     * findings about the word dismissed (reopened by the undo).
     *
     * @return int the findings dismissed
     */
    public function neverFlagWord(Book $book, User $actor, string $word): int
    {
        Gate::forUser($actor)->authorize('update', $book);
        $word = trim(StyleRules::nfc($word));

        if ($word === '' || mb_strlen($word) > 60) {
            throw ValidationException::withMessages(['word' => 'Choose a word or short phrase of up to 60 characters.']);
        }

        return DB::transaction(function () use ($book, $actor, $word) {
            $own = $this->overrides($book);
            $own['rules']['dictionary'][] = $word;
            $own['rules'] = StyleRules::normalise($own['rules']);

            if (count($own['rules']['dictionary']) > StyleRules::MAX_DICTIONARY) {
                throw ValidationException::withMessages(['word' => 'This book’s dictionary is full ('.StyleRules::MAX_DICTIONARY.' words).']);
            }

            $this->writeOverrides($book, $own);

            return $this->openFindings($book)
                ->get()
                ->filter(fn (EditorialFinding $f) => $f->term !== null && Suppression::sameWord($f->term, $word))
                ->each(fn (EditorialFinding $f) => $f->update(['status' => 'dismissed', 'dismissal' => 'dictionary', 'resolved_by' => $actor->id, 'resolved_at' => now()]))
                ->count();
        });
    }

    /** Undo "never flag this word": out of the book's dictionary, its findings reopened. */
    public function unflagWord(Book $book, User $actor, string $word): int
    {
        Gate::forUser($actor)->authorize('update', $book);

        return DB::transaction(function () use ($book, $word) {
            $own = $this->overrides($book);
            $own['rules']['dictionary'] = array_values(array_filter($own['rules']['dictionary'], fn ($w) => ! Suppression::sameWord($w, $word)));
            $this->writeOverrides($book, $own);

            return $this->dismissedBy($book, 'dictionary')
                ->get()
                ->filter(fn (EditorialFinding $f) => $f->term !== null && Suppression::sameWord($f->term, $word))
                ->each(fn (EditorialFinding $f) => $f->update(['status' => 'open', 'dismissal' => null, 'resolved_by' => null, 'resolved_at' => null]))
                ->count();
        });
    }

    /** "Turn this rule off for the book": its open findings dismissed. */
    public function disableRule(Book $book, User $actor, string $rule): int
    {
        Gate::forUser($actor)->authorize('update', $book);
        $rule = trim($rule);

        if ($rule === '' || mb_strlen($rule) > 120) {
            throw ValidationException::withMessages(['rule' => 'Unknown rule.']);
        }

        return DB::transaction(function () use ($book, $actor, $rule) {
            $own = $this->overrides($book);

            if (! in_array($rule, $own['disabled_rules'], true)) {
                if (count($own['disabled_rules']) >= 500) {
                    throw ValidationException::withMessages(['rule' => 'Too many rules are turned off for this book.']);
                }

                $own['disabled_rules'][] = $rule;
            }

            $this->writeOverrides($book, $own);

            return $this->openFindings($book)->where('rule', $rule)
                ->update(['status' => 'dismissed', 'dismissal' => 'rule_off', 'resolved_by' => $actor->id, 'resolved_at' => now()]);
        });
    }

    /** Undo: the rule back on, its findings reopened. */
    public function enableRule(Book $book, User $actor, string $rule): int
    {
        Gate::forUser($actor)->authorize('update', $book);

        return DB::transaction(function () use ($book, $rule) {
            $own = $this->overrides($book);
            $own['disabled_rules'] = array_values(array_diff($own['disabled_rules'], [$rule]));
            $this->writeOverrides($book, $own);

            return $this->dismissedBy($book, 'rule_off')->where('rule', $rule)
                ->update(['status' => 'open', 'dismissal' => null, 'resolved_by' => null, 'resolved_at' => null]);
        });
    }

    /**
     * What the studio shows about the book's style guide.
     *
     * @return array<string, mixed>
     */
    public function summary(Book $book, ?User $actor): array
    {
        $effective = $this->effective($book);
        $guide = $effective['version'] ? $book->styleGuide : null;
        $own = $this->overrides($book);
        $manage = $actor && $book->user && self::canManage($actor, $book->user);

        return [
            'following' => $guide ? ['id' => $guide->id, 'name' => $guide->name, 'version' => $guide->version, 'version_id' => $effective['version']->id] : null,
            'rules' => $effective['rules'],
            'own' => $own['rules'],
            'disabled_rules' => $own['disabled_rules'],
            'configured' => $effective['configured'],
            'can_edit' => $actor ? Gate::forUser($actor)->allows('update', $book) : false,
            'can_manage' => $manage,
            'guides' => $manage
                ? $this->forAccount($book->user)->map(fn (StyleGuide $g) => self::present($g))->all()
                : [],
            'options' => self::options(),
        ];
    }

    /** @return array<string, mixed> */
    public static function present(StyleGuide $guide): array
    {
        return [
            'id' => $guide->id,
            'name' => $guide->name,
            'version' => $guide->version,
            'books' => $guide->books_count ?? $guide->books()->count(),
            'rules' => StyleRules::normalise($guide->current?->rules),
            'updated_at' => $guide->updated_at?->toIso8601String(),
        ];
    }

    /** The choices the guide editor offers. */
    public static function options(): array
    {
        return [
            'languages' => [
                ['en-US', 'English (US)'], ['en-GB', 'English (UK)'],
                ['fr-FR', 'Français (France)'], ['fr-CA', 'Français (Canada)'], ['ht', 'Kreyòl ayisyen'],
            ],
            'quotes' => collect(StyleRules::LABELS['quotes'])->map(fn ($l, $k) => [$k, $l])->values(),
            'dashes' => collect(StyleRules::LABELS['dashes'])->map(fn ($l, $k) => [$k, $l])->values(),
            'heading_case' => collect(StyleRules::LABELS['heading_case'])->map(fn ($l, $k) => [$k, $l])->values(),
            'date_format' => collect(StyleRules::LABELS['date_format'])->map(fn ($l, $k) => [$k, $l])->values(),
            'spell_out' => StyleRules::SPELL_OUT,
            'limits' => ['terms' => StyleRules::MAX_TERMS, 'dictionary' => StyleRules::MAX_DICTIONARY, 'notes' => StyleRules::MAX_NOTES],
        ];
    }

    /** @return array{rules: array<string, mixed>, disabled_rules: list<string>} */
    public function overrides(Book $book): array
    {
        $stored = (array) ($book->style_overrides ?? []);

        return [
            'rules' => StyleRules::normalise($stored['rules'] ?? []),
            'disabled_rules' => array_values(array_unique(array_filter((array) ($stored['disabled_rules'] ?? []), 'is_string'))),
        ];
    }

    private function writeOverrides(Book $book, array $own): void
    {
        $empty = StyleRules::isEmpty($own['rules']) && $own['disabled_rules'] === [];
        $book->forceFill(['style_overrides' => $empty ? null : $own])->save();
    }

    /** Copy the guide's rules under the book's own and stop following. */
    private function keepRules(Book $book, StyleGuide $guide): void
    {
        $own = $this->overrides($book);
        $own['rules'] = StyleRules::merge($guide->current?->rules, $own['rules']);
        $book->style_guide_id = null;
        $this->writeOverrides($book, $own);
    }

    private function openFindings(Book $book)
    {
        return EditorialFinding::whereIn('manuscript_section_id', $book->sections()->select('id'))->where('status', 'open');
    }

    private function dismissedBy(Book $book, string $why)
    {
        return EditorialFinding::whereIn('manuscript_section_id', $book->sections()->select('id'))
            ->where('status', 'dismissed')->where('dismissal', $why);
    }

    private function name(User $account, string $name, ?int $except = null): string
    {
        $name = trim($name);

        if (mb_strlen($name) < 2 || mb_strlen($name) > 80) {
            throw ValidationException::withMessages(['name' => 'Give the guide a name of 2 to 80 characters.']);
        }

        if (StyleGuide::where('user_id', $account->id)->where('name', $name)->when($except, fn ($q) => $q->whereKeyNot($except))->exists()) {
            throw ValidationException::withMessages(['name' => "There is already a style guide called “{$name}”."]);
        }

        return $name;
    }

    private function authorizeAccount(User $actor, ?User $account): void
    {
        if (! $account || ! self::canManage($actor, $account)) {
            throw new AuthorizationException('You cannot manage this account’s style guides.');
        }
    }
}
