<?php

namespace App\Editorial\Contracts;

use App\Editorial\EditorialAgent;
use App\Editorial\ReviewContext;
use App\Editorial\ReviewResult;

/**
 * A driver that takes the review's options: the book's style guide, the
 * author's focus notes and the sensitivity (ADR — Style guides and review
 * options).
 *
 * Separate from EditorialDriver so a driver written against the original
 * two-argument review() keeps working: the runner calls reviewWith() when
 * the driver has it and review() otherwise. A context that says nothing
 * (no guide, no focus, standard sensitivity) must produce exactly the
 * request review() would.
 */
interface ReadsReviewContext
{
    public function reviewWith(EditorialAgent $agent, string $text, ReviewContext $context): ReviewResult;

    /**
     * What a review of $characters characters would cost, before running it:
     * the model, the tokens in and out, and USD from the gateway's price
     * table (null when the price is not known).
     *
     * @return array{model: ?string, input_tokens: int, output_tokens: int, usd: ?float}
     */
    public function estimate(EditorialAgent $agent, int $characters, ReviewContext $context): array;
}
