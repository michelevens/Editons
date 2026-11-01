<?php

namespace App\Editorial;

use RuntimeException;

/**
 * The AI provider could not do the review: billing or quota, auth, rate
 * limit, timeout, or its own fault (ADR — Author walkthrough fixes).
 *
 * The author is told "AI review is unavailable right now" in their own
 * language and is not charged; staff get the provider's code and its
 * message, scrubbed of anything that looks like a key. The message never
 * carries the request's headers.
 */
class ProviderUnavailable extends RuntimeException
{
    public const KINDS = ['billing', 'auth', 'rate_limit', 'timeout', 'server', 'other'];

    public function __construct(
        public readonly string $kind,
        public readonly string $provider,
        public readonly string $providerCode,
        string $detail = '',
    ) {
        parent::__construct(self::scrub("{$provider} review failed ({$providerCode}): {$detail}"));
    }

    /** Classify an HTTP failure from its status and the provider's error type and text. */
    public static function fromHttp(string $provider, int $status, ?string $type, ?string $message): self
    {
        $text = strtolower((string) $message);

        $kind = match (true) {
            str_contains($text, 'credit balance') || str_contains($text, 'billing') || str_contains($text, 'quota') => 'billing',
            in_array($status, [401, 403], true) || in_array($type, ['authentication_error', 'permission_error'], true) => 'auth',
            $status === 429 || $type === 'rate_limit_error' => 'rate_limit',
            $status === 408 || $status === 504 => 'timeout',
            $status >= 500 || $type === 'overloaded_error' || $type === 'api_error' => 'server',
            default => 'other',
        };

        return new self($kind, $provider, trim($status.' '.($type ?? '')), (string) ($message ?? ''));
    }

    /** Anything shaped like an API key is replaced before it is logged or shown. */
    public static function scrub(string $text): string
    {
        return preg_replace('/sk-(?:ant-)?[A-Za-z0-9_\-]{6,}/', 'sk-[redacted]', $text) ?? '';
    }
}
