<?php

namespace App\Research\Assistant;

use App\Editorial\ProviderUnavailable;
use App\Platform\Ai\AiPricing;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * A minimal Messages API client for the research, fact-checking and store
 * copy features (ADR — AI research, source-backed fact-checking, cover
 * templates).
 *
 * Deliberately small and in this namespace: the AI book writer is building
 * the shared generation gateway (App\Ai\Writer) in parallel, and this is
 * folded into it when both land. It does what AnthropicDriver does at the
 * HTTP boundary and nothing more: the same headers, the same provider
 * failure classification (a failure is "unavailable", never charged), the
 * same refusal handling.
 */
class ClaudeMessages
{
    public function __construct(
        private readonly string $apiKey,
        private readonly int $timeout = 120,
    ) {}

    /**
     * Send one request and return the decoded response.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     *
     * @throws ProviderUnavailable when the provider cannot answer
     */
    public function send(array $payload): array
    {
        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => '2023-06-01',
                'content-type' => 'application/json',
            ])
                ->timeout($this->timeout)
                ->retry(2, 1000, throw: false)
                ->post('https://api.anthropic.com/v1/messages', $payload);
        } catch (ConnectionException) {
            // The exception's text is not kept: it can name the request.
            throw new ProviderUnavailable('timeout', 'anthropic', 'connection', 'No response from the provider.');
        }

        if ($response->failed()) {
            throw ProviderUnavailable::fromHttp(
                'anthropic',
                $response->status(),
                $response->json('error.type'),
                $response->json('error.message') ?? (string) $response->status(),
            );
        }

        $data = (array) $response->json();

        if (($data['stop_reason'] ?? null) === 'refusal') {
            throw new RuntimeException('The model declined this request. Nothing was charged.');
        }

        return $data;
    }

    /** The token price of a response's usage, or null for an unpriced model. */
    public static function tokenCost(?string $model, int $input, int $output): ?float
    {
        return AiPricing::tokens('anthropic', $model, $input, $output);
    }

    /** The first text block of a response, decoded as JSON when asked. */
    public static function text(array $response): string
    {
        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? null) === 'text') {
                return (string) ($block['text'] ?? '');
            }
        }

        return '';
    }
}
