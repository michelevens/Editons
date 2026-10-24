<?php

namespace App\Platform\Ai;

use App\Models\AiUsage;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * What the AI usage log says, for the console.
 *
 * Every total is of known costs, beside how many calls had no price: an
 * unpriced model shows up as "cost unknown", never as free. Simulated calls
 * are counted apart, because sample output is not AI spend.
 */
final class AiUsageReport
{
    /** @return array{today: array, d7: array, d30: array} */
    public function windows(?User $user = null): array
    {
        return [
            'today' => $this->window(now()->startOfDay(), $user),
            'd7' => $this->window(now()->subDays(7), $user),
            'd30' => $this->window(now()->subDays(30), $user),
        ];
    }

    /** @return array{calls: int, real: int, simulated: int, failed: int, cost_usd: float, unknown_cost: int} */
    public function window(\DateTimeInterface $since, ?User $user = null): array
    {
        $row = AiUsage::query()
            ->where('created_at', '>=', $since)
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->selectRaw('count(*) as calls')
            ->selectRaw('count(*) filter (where not simulated) as real_calls')
            ->selectRaw('count(*) filter (where simulated) as simulated')
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->selectRaw('coalesce(sum(cost_usd), 0) as cost')
            ->selectRaw('count(*) filter (where cost_usd is null and not simulated) as unknown_cost')
            ->first();

        return [
            'calls' => (int) $row->calls,
            'real' => (int) $row->real_calls,
            'simulated' => (int) $row->simulated,
            'failed' => (int) $row->failed,
            'cost_usd' => round((float) $row->cost, 4),
            'unknown_cost' => (int) $row->unknown_cost,
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function by(string $column, int $days = 30, ?User $user = null): array
    {
        abort_unless(in_array($column, ['feature', 'model', 'provider'], true), 500);

        return AiUsage::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->select(DB::raw("coalesce({$column}, 'unknown') as name"))
            ->selectRaw('count(*) as calls')
            ->selectRaw("count(*) filter (where status = 'failed') as failed")
            ->selectRaw('count(*) filter (where simulated) as simulated')
            ->selectRaw('coalesce(sum(cost_usd), 0) as cost')
            ->selectRaw('count(*) filter (where cost_usd is null and not simulated) as unknown_cost')
            ->selectRaw('coalesce(sum(input_tokens), 0) as input_tokens')
            ->selectRaw('coalesce(sum(output_tokens), 0) as output_tokens')
            ->selectRaw('avg(latency_ms) as latency')
            ->groupBy('name')
            ->orderByDesc('cost')
            ->orderByDesc('calls')
            ->get()
            ->map(fn ($row) => [
                'name' => $row->name,
                'label' => $column === 'feature' ? AiControls::label((string) $row->name) : $row->name,
                'calls' => (int) $row->calls,
                'failed' => (int) $row->failed,
                'simulated' => (int) $row->simulated,
                'cost_usd' => round((float) $row->cost, 4),
                'unknown_cost' => (int) $row->unknown_cost,
                'input_tokens' => (int) $row->input_tokens,
                'output_tokens' => (int) $row->output_tokens,
                'latency_ms' => $row->latency === null ? null : (int) round((float) $row->latency),
            ])
            ->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function topAccounts(int $days = 30, int $limit = 10): array
    {
        $rows = AiUsage::query()
            ->where('created_at', '>=', now()->subDays($days))
            ->whereNotNull('user_id')
            ->select('user_id')
            ->selectRaw('count(*) as calls')
            ->selectRaw('coalesce(sum(cost_usd), 0) as cost')
            ->selectRaw('count(*) filter (where cost_usd is null and not simulated) as unknown_cost')
            ->groupBy('user_id')
            ->orderByDesc('cost')
            ->orderByDesc('calls')
            ->limit($limit)
            ->get();

        $users = User::whereIn('id', $rows->pluck('user_id'))->get(['id', 'name', 'email'])->keyBy('id');

        return $rows->map(fn ($row) => [
            'id' => (int) $row->user_id,
            'name' => $users[$row->user_id]->name ?? '(deleted)',
            'email' => $users[$row->user_id]->email ?? null,
            'calls' => (int) $row->calls,
            'cost_usd' => round((float) $row->cost, 4),
            'unknown_cost' => (int) $row->unknown_cost,
        ])->all();
    }

    /** @return array<int, array<string, mixed>> */
    public function recentFailures(int $limit = 20, ?User $user = null): array
    {
        return AiUsage::where('status', 'failed')
            ->when($user, fn ($q) => $q->where('user_id', $user->id))
            ->with('user:id,name,email')
            ->latest('id')
            ->limit($limit)
            ->get()
            ->map(fn (AiUsage $u) => [
                'id' => $u->id,
                'feature' => AiControls::label($u->feature),
                'provider' => $u->provider,
                'model' => $u->model,
                'user' => $u->user ? ['id' => $u->user->id, 'name' => $u->user->name, 'email' => $u->user->email] : null,
                'error' => $u->error,
                'simulated' => $u->simulated,
                'at' => $u->created_at?->toIso8601String(),
            ])
            ->all();
    }
}
