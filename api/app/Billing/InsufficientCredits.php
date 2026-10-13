<?php

namespace App\Billing;

use RuntimeException;

/**
 * Not enough credits for an action.
 *
 * Carries both numbers so the UI can say "this costs 10, you have 3" rather
 * than a bare refusal — the prototype's upgrade modal needs exactly that.
 */
class InsufficientCredits extends RuntimeException
{
    public function __construct(
        public readonly int $required,
        public readonly int $available,
    ) {
        parent::__construct(
            "This action costs {$required} credits; the account has {$available}.",
        );
    }

    /** A 402 is the honest status: the request is fine, the balance is not. */
    public function render(): \Illuminate\Http\JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'required' => $this->required,
            'available' => $this->available,
        ], 402);
    }
}
