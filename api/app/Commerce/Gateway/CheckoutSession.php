<?php

namespace App\Commerce\Gateway;

/** A hosted checkout page: its id, and where to send the buyer. */
final class CheckoutSession
{
    public function __construct(
        public readonly string $id,
        public readonly string $url,
    ) {}
}
