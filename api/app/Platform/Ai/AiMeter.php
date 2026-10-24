<?php

namespace App\Platform\Ai;

use App\Models\AiUsage;
use App\Models\Book;
use App\Models\User;
use App\Platform\Impersonation;
use Throwable;

/**
 * The AI usage log: one row for every call the app makes to a provider.
 *
 * Written beside the call — editorial reviews, cover concepts, cover
 * artwork, support drafts — whether it succeeded or failed, so the console
 * can say what AI costs, for whom, and how often it breaks. The fake driver
 * is recorded too, flagged as simulated with a cost of zero: it is a call
 * the product made, it just spent nothing.
 *
 * Recording never breaks the call it records (except under test, where a
 * broken write is the regression the suite is for).
 */
final class AiMeter
{
    public static function record(
        string $feature,
        string $provider,
        ?string $model,
        User|int|null $user = null,
        Book|int|null $book = null,
        bool $simulated = false,
        string $status = 'ok',
        ?int $inputTokens = null,
        ?int $outputTokens = null,
        ?int $images = null,
        ?float $cost = null,
        ?int $latencyMs = null,
        ?string $error = null,
    ): ?AiUsage {
        try {
            $cost = match (true) {
                $simulated => 0.0,
                $cost !== null => round($cost, 6),
                $images !== null => AiPricing::images($provider, $model, $images),
                default => AiPricing::tokens($provider, $model, $inputTokens, $outputTokens),
            };

            return AiUsage::create([
                'feature' => $feature,
                'provider' => mb_substr($provider, 0, 40),
                'model' => $model === null ? null : mb_substr($model, 0, 120),
                'user_id' => $user instanceof User ? $user->id : $user,
                'book_id' => $book instanceof Book ? $book->id : $book,
                'simulated' => $simulated,
                'status' => $status,
                'input_tokens' => $inputTokens,
                'output_tokens' => $outputTokens,
                'images' => $images,
                'cost_usd' => $cost,
                'latency_ms' => $latencyMs,
                'error' => $error === null ? null : mb_substr($error, 0, 500),
                'impersonator_id' => Impersonation::impersonatorId(),
            ]);
        } catch (Throwable $e) {
            if (app()->runningUnitTests()) {
                throw $e;
            }

            report($e);

            return null;
        }
    }

    /** Milliseconds since an hrtime(true) reading. */
    public static function since(int|float $start): int
    {
        return (int) round((hrtime(true) - $start) / 1_000_000);
    }
}
