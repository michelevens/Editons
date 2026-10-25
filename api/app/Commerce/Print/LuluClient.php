<?php

namespace App\Commerce\Print;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * The Lulu Print API, as its reference documents it
 * (https://api.lulu.com/api-docs/openapi-specs/openapi_public.yml, read
 * 2026-10-25).
 *
 * - Auth: OpenID Connect client credentials. POST
 *   /auth/realms/glasstree/protocol/openid-connect/token with
 *   `grant_type=client_credentials` and `Authorization: Basic
 *   base64(key:secret)`; the `access_token` (expires_in 3600) goes on every
 *   other call as `Authorization: Bearer`.
 * - File validation, asynchronous: POST /validate-interior/ {source_url,
 *   pod_package_id} and POST /validate-cover/ {source_url, pod_package_id,
 *   interior_page_count}, then GET /validate-interior/{id}/ and
 *   /validate-cover/{id}/ until the status is final (VALIDATED or
 *   NORMALIZED, or ERROR with `errors`). Lulu downloads the files itself,
 *   so source_url must be publicly reachable.
 * - POST /print-job-cost-calculations/ {line_items [{page_count,
 *   pod_package_id, quantity}], shipping_address, shipping_option}.
 * - POST /print-jobs/ {contact_email, external_id, line_items [{external_id,
 *   title, quantity, printable_normalization {cover {source_url}, interior
 *   {source_url}, pod_package_id}}], shipping_address, shipping_level}.
 * - GET /print-jobs/{id}/status/ → {name, message, changed,
 *   line_item_statuses [{name, messages {tracking_id, tracking_urls,
 *   carrier_name}}]}.
 *
 * Sandbox (api.sandbox.lulu.com) or production (api.lulu.com) by
 * LULU_ENV. Tests fake the HTTP layer (Http::fake); nothing here is ever
 * called without keys.
 */
class LuluClient
{
    public function connected(): bool
    {
        return filled(config('services.lulu.client_key')) && filled(config('services.lulu.client_secret'));
    }

    public function environment(): string
    {
        return config('services.lulu.env') === 'production' ? 'production' : 'sandbox';
    }

    public function baseUrl(): string
    {
        return rtrim((string) config('services.lulu.urls.'.$this->environment()), '/');
    }

    /** An access token, cached until shortly before it expires. */
    public function token(): string
    {
        $this->assertConnected();

        $key = 'lulu:token:'.$this->environment().':'.md5((string) config('services.lulu.client_key'));

        if ($token = Cache::get($key)) {
            return $token;
        }

        $response = Http::asForm()
            ->withBasicAuth((string) config('services.lulu.client_key'), (string) config('services.lulu.client_secret'))
            ->acceptJson()
            ->timeout(20)
            ->post($this->baseUrl().'/auth/realms/glasstree/protocol/openid-connect/token', ['grant_type' => 'client_credentials']);

        if (! $response->successful() || ! is_string($response->json('access_token'))) {
            throw new LuluException('Lulu refused the API keys (HTTP '.$response->status().'). Check LULU_CLIENT_KEY, LULU_CLIENT_SECRET and LULU_ENV.', $response->status());
        }

        $ttl = max(60, (int) ($response->json('expires_in') ?? 3600) - 120);
        Cache::put($key, $token = $response->json('access_token'), $ttl);

        return $token;
    }

    /** @return array<string, mixed> */
    public function validateInterior(string $url, ?string $podPackageId): array
    {
        return $this->post('/validate-interior/', array_filter(['source_url' => $url, 'pod_package_id' => $podPackageId]));
    }

    /** @return array<string, mixed> */
    public function interiorValidation(int|string $id): array
    {
        return $this->get('/validate-interior/'.rawurlencode((string) $id).'/');
    }

    /** @return array<string, mixed> */
    public function validateCover(string $url, string $podPackageId, int $pages): array
    {
        return $this->post('/validate-cover/', ['source_url' => $url, 'pod_package_id' => $podPackageId, 'interior_page_count' => $pages]);
    }

    /** @return array<string, mixed> */
    public function coverValidation(int|string $id): array
    {
        return $this->get('/validate-cover/'.rawurlencode((string) $id).'/');
    }

    /**
     * @param  array<string, mixed>  $address
     * @return array<string, mixed>
     */
    public function cost(string $podPackageId, int $pages, array $address, string $shipping): array
    {
        return $this->post('/print-job-cost-calculations/', [
            'line_items' => [['page_count' => $pages, 'pod_package_id' => $podPackageId, 'quantity' => 1]],
            'shipping_address' => $address,
            'shipping_option' => $shipping,
        ]);
    }

    /**
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>
     */
    public function createPrintJob(array $job): array
    {
        return $this->post('/print-jobs/', $job);
    }

    /** @return array<string, mixed> */
    public function printJobStatus(int|string $id): array
    {
        return $this->get('/print-jobs/'.rawurlencode((string) $id).'/status/');
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function post(string $path, array $body): array
    {
        return $this->send(fn (PendingRequest $http) => $http->post($this->baseUrl().$path, $body));
    }

    /** @return array<string, mixed> */
    private function get(string $path): array
    {
        return $this->send(fn (PendingRequest $http) => $http->get($this->baseUrl().$path));
    }

    /** @return array<string, mixed> */
    private function send(callable $call): array
    {
        $http = Http::withToken($this->token())->acceptJson()->asJson()->timeout(30);

        try {
            $response = $call($http);
        } catch (ConnectionException $e) {
            throw new LuluException('Lulu could not be reached: '.$e->getMessage(), 0, $e);
        }

        if ($response->status() === 401) {
            // A token revoked early: forget it so the next call asks again.
            Cache::forget('lulu:token:'.$this->environment().':'.md5((string) config('services.lulu.client_key')));
        }

        if (! $response->successful()) {
            throw new LuluException('Lulu answered HTTP '.$response->status().': '.self::summary($response->json() ?? $response->body()), $response->status());
        }

        return (array) $response->json();
    }

    /** A short, readable account of what Lulu said was wrong. */
    private static function summary(mixed $body): string
    {
        if (is_string($body)) {
            return mb_substr(trim(strip_tags($body)), 0, 300);
        }

        $messages = [];
        array_walk_recursive($body, function ($value, $key) use (&$messages) {
            if (is_string($value) && $value !== '') {
                $messages[] = (is_string($key) ? $key.': ' : '').$value;
            }
        });

        return mb_substr(implode('; ', $messages), 0, 300) ?: 'no details';
    }

    private function assertConnected(): void
    {
        if (! $this->connected()) {
            throw new LuluException('Lulu is not connected: set LULU_CLIENT_KEY and LULU_CLIENT_SECRET.');
        }
    }
}
