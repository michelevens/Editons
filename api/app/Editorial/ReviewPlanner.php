<?php

namespace App\Editorial;

use App\Billing\CreditLedger;
use App\Billing\Entitlements;
use App\Editorial\Contracts\ReadsReviewContext;
use App\Models\Book;
use App\Models\ManuscriptSection;
use App\Models\User;
use App\Platform\Ai\AiControls;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

/**
 * What a review run covers, what it will cost, and whether it may start
 * (ADR — Style guides and review options).
 *
 * Scope is a selection, one chapter, a range of chapters in reading order,
 * or the whole book. The estimate is the driver's own (token counts × the
 * gateway's price table, AiPricing), plus the credits the runs will spend;
 * it is shown before running and enforced when the run starts: the credit
 * balance must cover it, the account's monthly AI cap must have room for
 * it, and a whole-book run above the approval threshold needs the author
 * to approve that estimate explicitly.
 */
class ReviewPlanner
{
    public const SCOPES = ['selection', 'chapter', 'range', 'book'];

    public const MAX_SELECTION = 20000;

    public function __construct(
        private readonly ReviewRunner $runner,
        private readonly CreditLedger $ledger,
        private readonly Entitlements $entitlements,
        private readonly AiControls $controls,
    ) {}

    /** @return array<string, mixed> */
    public static function rules(): array
    {
        return [
            'agent' => ['required', 'string'],
            'scope' => ['required', 'string', 'in:'.implode(',', self::SCOPES)],
            'section_id' => ['required_if:scope,selection,chapter', 'nullable', 'integer'],
            'from_id' => ['required_if:scope,range', 'nullable', 'integer'],
            'to_id' => ['required_if:scope,range', 'nullable', 'integer'],
            'selection' => ['required_if:scope,selection', 'nullable', 'string', 'max:'.self::MAX_SELECTION],
            'focus' => ['nullable', 'string', 'max:'.ReviewContext::MAX_FOCUS],
            'sensitivity' => ['nullable', 'string', 'in:'.implode(',', ReviewContext::SENSITIVITIES)],
            'approved' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Resolve a request into the sections to review.
     *
     * @param  array<string, mixed>  $data  validated against rules()
     * @return array{agent: EditorialAgent, scope: string, context: ReviewContext, items: list<array{section: ManuscriptSection, selection: ?array{offset: int, length: int}, characters: int}>}
     */
    public function plan(Book $book, array $data): array
    {
        $agent = EditorialAgent::tryFrom((string) $data['agent'])
            ?? throw ValidationException::withMessages(['agent' => 'Unknown editorial agent.']);

        $order = $this->readingOrder($book);
        $ids = $order->pluck('id')->all();
        $scope = $data['scope'];

        $pick = match ($scope) {
            'selection', 'chapter' => [$this->member($ids, $data['section_id'] ?? null, 'section_id')],
            'range' => $this->range($ids, $data['from_id'] ?? null, $data['to_id'] ?? null),
            'book' => $ids,
        };

        if (count($pick) > config('editorial.max_sections', 200)) {
            throw ValidationException::withMessages(['scope' => 'That is more than '.config('editorial.max_sections', 200).' sections. Review the book in ranges.']);
        }

        $sections = ManuscriptSection::whereIn('id', $pick)->get()->keyBy('id');
        $items = [];

        foreach ($pick as $id) {
            $section = $sections[$id];
            $text = $section->plainText();
            $selection = null;

            if ($scope === 'selection') {
                $selection = $this->locate($text, (string) $data['selection']);
            }

            $items[] = ['section' => $section, 'selection' => $selection, 'characters' => $selection['length'] ?? mb_strlen(trim($text) === '' ? '' : $text)];
        }

        $focus = isset($data['focus']) ? trim((string) $data['focus']) : '';
        $first = $items[0]['section'] ?? $book->sections()->first();
        $context = $first
            ? $this->runner->contextFor($first, $focus === '' ? null : $focus, $data['sensitivity'] ?? 'standard')
            : new ReviewContext(focus: $focus === '' ? null : $focus, sensitivity: $data['sensitivity'] ?? 'standard');

        return ['agent' => $agent, 'scope' => $scope, 'context' => $context, 'items' => $items];
    }

    /**
     * What the plan will cost, and whether it can be afforded.
     *
     * @return array<string, mixed>
     */
    public function estimate(array $plan, User $user): array
    {
        /** @var EditorialAgent $agent */
        $agent = $plan['agent'];
        $driver = $this->runner->driver();
        $simulated = $this->runner->simulated();
        $reviewable = array_values(array_filter($plan['items'], fn ($i) => $i['characters'] > 0));

        $input = 0;
        $output = 0;
        $usd = $simulated ? 0.0 : null;
        $model = null;
        $known = true;

        if (! $simulated && $driver instanceof ReadsReviewContext) {
            $usd = 0.0;

            foreach ($reviewable as $item) {
                $e = $driver->estimate($agent, $item['characters'], $plan['context']);
                $input += $e['input_tokens'];
                $output += $e['output_tokens'];
                $model = $e['model'];

                if ($e['usd'] === null) {
                    $known = false;
                } else {
                    $usd += $e['usd'];
                }
            }

            $usd = $known ? round($usd, 4) : null;
        }

        $credits = $simulated ? 0 : count($reviewable) * CreditLedger::costOf($agent->costLabel());
        $this->entitlements->summaryFor($user); // grants a pending allowance first
        $balance = $this->ledger->balanceFor($user);
        $cap = $this->controls->capFor($user);
        $spent = $cap === null ? null : $this->controls->spentThisMonth($user);
        $approval = config('editorial.approval');

        return [
            'agent' => $agent->value,
            'scope' => $plan['scope'],
            'sections' => count($plan['items']),
            'reviewable' => count($reviewable),
            'characters' => array_sum(array_column($plan['items'], 'characters')),
            'model' => $model,
            'input_tokens' => $input,
            'output_tokens' => $output,
            'usd' => $usd,
            'credits' => $credits,
            'balance' => $balance,
            'simulated' => $simulated,
            'affordable' => $credits <= $balance,
            'within_cap' => $cap === null || $usd === null || $spent + $usd <= $cap,
            'needs_approval' => $plan['scope'] === 'book'
                && ($credits > $approval['credits'] || ($usd ?? 0) > $approval['usd']),
            'style_guide' => $plan['context']->style !== null,
            'sensitivity' => $plan['context']->sensitivity,
        ];
    }

    /**
     * Refuse a run the account cannot pay for, or a large one nobody
     * approved. Nothing has been spent or sent when this throws.
     *
     * @throws ValidationException
     */
    public function enforce(array $estimate, bool $approved): void
    {
        if (! $estimate['affordable']) {
            throw ValidationException::withMessages(['credits' => "That review costs {$estimate['credits']} credits and you have {$estimate['balance']}. Narrow the scope, top up, or upgrade for a larger monthly allowance."]);
        }

        if (! $estimate['within_cap']) {
            throw ValidationException::withMessages(['budget' => 'That review would take the account past its AI limit for this month. Narrow the scope, or write to support.']);
        }

        if ($estimate['needs_approval'] && ! $approved) {
            throw ValidationException::withMessages(['approved' => 'A whole-book review of this size needs your approval of the estimate first.']);
        }
    }

    /**
     * Every reviewable section, in reading order: the binder's tree, parts
     * themselves left out (they hold chapters, not prose to review).
     *
     * @return Collection<int, object{id: int, title: string}>
     */
    public function readingOrder(Book $book): Collection
    {
        $all = $book->sections()->orderBy('position')->orderBy('id')->get(['id', 'parent_id', 'type', 'title', 'position']);
        $children = $all->groupBy(fn ($s) => $s->parent_id ?? 0);
        $out = collect();

        $walk = function (int $parent) use (&$walk, $children, $out) {
            foreach ($children[$parent] ?? [] as $section) {
                if ($section->type !== 'part') {
                    $out->push((object) ['id' => $section->id, 'title' => $section->title]);
                }

                $walk($section->id);
            }
        };
        $walk(0);

        return $out;
    }

    private function member(array $ids, mixed $id, string $field): int
    {
        if (! in_array((int) $id, $ids, true)) {
            throw ValidationException::withMessages([$field => 'That chapter is not in this book.']);
        }

        return (int) $id;
    }

    /** @return list<int> */
    private function range(array $ids, mixed $from, mixed $to): array
    {
        $a = array_search($this->member($ids, $from, 'from_id'), $ids, true);
        $b = array_search($this->member($ids, $to, 'to_id'), $ids, true);

        return array_slice($ids, min($a, $b), abs($b - $a) + 1);
    }

    /**
     * Where the selected text sits in the chapter's plain text. The editor
     * sends the text, not positions: ProseMirror positions are not plain
     * text offsets. The first occurrence is taken.
     *
     * @return array{offset: int, length: int}
     */
    private function locate(string $text, string $selected): array
    {
        $words = preg_split('/\s+/u', trim($selected), -1, PREG_SPLIT_NO_EMPTY);
        $pattern = '/'.implode('\s+', array_map(fn ($w) => preg_quote($w, '/'), $words)).'/u';

        if ($words === [] || ! preg_match($pattern, $text, $m, PREG_OFFSET_CAPTURE)) {
            throw ValidationException::withMessages(['selection' => 'The selected text is not in the chapter as saved. Save, then select it again.']);
        }

        return ['offset' => mb_strlen(substr($text, 0, $m[0][1])), 'length' => mb_strlen($m[0][0])];
    }
}
