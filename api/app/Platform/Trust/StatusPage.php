<?php

namespace App\Platform\Trust;

use App\Commerce\Commerce;
use App\Models\BookExport;
use App\Models\MailLog;
use App\Models\StatusIncident;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Platform\Features;
use App\Platform\Ops\Health;
use App\Platform\PlatformSettings;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * The public status page: each component's state, from real signals, and
 * the incidents staff post.
 *
 * Signals come from App\Platform\Ops\Health (database, worker and scheduler
 * heartbeats, mail transport, Stripe configuration) plus what the tables
 * say (stuck exports, mail failures in the last hour). A component that
 * cannot be measured from here says "unknown", never "operational": the API
 * runs as its own service and is only checked when PLATFORM_STATUS_API_URL
 * points at it. An open incident on a component makes it at least as bad as
 * the incident says, whatever the signals.
 *
 * Signals are cached for a minute: the page is public, and the files-disk
 * probe writes to storage.
 */
final class StatusPage
{
    public const COMPONENTS = [
        'app' => 'Writing studio',
        'api' => 'API',
        'exports' => 'Exports (PDF, EPUB)',
        'email' => 'Email',
        'storefront' => 'Storefront',
    ];

    /** Worst last. 'unavailable': not offered here (no storefront configured). */
    public const STATES = ['operational', 'unavailable', 'unknown', 'maintenance', 'degraded', 'partial_outage', 'major_outage'];

    private const SEVERITY_STATE = [
        'minor' => 'degraded',
        'major' => 'partial_outage',
        'critical' => 'major_outage',
        'maintenance' => 'maintenance',
    ];

    private const BANNER_CACHE = 'status-page.banner';

    public function __construct(private readonly Health $health, private readonly PlatformSettings $settings) {}

    /** @return array<string, mixed> what /status and /status.json show */
    public function snapshot(): array
    {
        $maintenance = $this->settings->maintenance();
        $signals = $this->signals();
        $open = StatusIncident::open()->with('updates')->orderByDesc('started_at')->get();

        $components = [];

        foreach (self::COMPONENTS as $key => $label) {
            $state = $maintenance ? 'maintenance' : $signals[$key]['status'];
            $note = $maintenance ? 'Écrivain is down for maintenance.' : $signals[$key]['note'];

            foreach ($open as $incident) {
                if (in_array($key, $incident->components ?? [], true)) {
                    $state = self::worse($state, self::SEVERITY_STATE[$incident->severity] ?? 'degraded');
                    $note = $incident->title;
                }
            }

            $components[] = ['key' => $key, 'label' => $label, 'status' => $state, 'note' => $note];
        }

        $states = array_column($components, 'status');
        $worst = array_reduce($states, fn ($carry, $s) => self::worse($carry, $s), 'operational');

        return [
            // Kept from the first /status: monitors read these two.
            'status' => $maintenance ? 'maintenance' : 'ok',
            'message' => $maintenance ? (trim((string) $this->settings->get('maintenance_message')) ?: null) : null,
            'overall' => $worst,
            'headline' => match (true) {
                $maintenance => 'Down for maintenance',
                in_array($worst, ['major_outage', 'partial_outage'], true) => 'Some things are not working',
                $worst === 'degraded' => 'Some things are slower or failing more than usual',
                $worst === 'maintenance' => 'Maintenance in progress',
                in_array('unknown', $states, true) => 'Running, but not every part can be measured from here',
                default => 'Everything is working',
            },
            'components' => $components,
            'incidents' => $open->map(fn (StatusIncident $i) => self::present($i))->values()->all(),
            'recent' => StatusIncident::whereNotNull('resolved_at')
                ->where('resolved_at', '>=', now()->subDays(14))
                ->with('updates')
                ->orderByDesc('resolved_at')
                ->limit(10)
                ->get()
                ->map(fn (StatusIncident $i) => self::present($i))
                ->values()
                ->all(),
            'checked_at' => $signals['checked_at'],
        ];
    }

    /**
     * Each component from what can be measured.
     *
     * @return array<string, mixed>
     */
    public function signals(): array
    {
        return Cache::remember('status-page.signals', 60, function () {
            $checks = collect($this->health->checks())->keyBy('key');
            $state = fn (string $s) => ['ok' => 'operational', 'warning' => 'degraded', 'failing' => 'major_outage'][$s] ?? 'unknown';

            return [
                'app' => ($checks['database']['status'] ?? 'unknown') === 'ok'
                    ? ['status' => 'operational', 'note' => null]
                    : ['status' => 'major_outage', 'note' => 'The database is not answering.'],
                'api' => $this->api(),
                'exports' => $this->exports($checks->all()),
                'email' => $this->email($checks['mail'] ?? null, $state),
                'storefront' => $this->storefront($checks['stripe'] ?? null),
                'checked_at' => now()->toIso8601String(),
            ];
        });
    }

    /**
     * The in-app banner for an open incident that asked for one, when the
     * owner's own announcement is not showing.
     *
     * @return array{message: string, tone: string, id: string, url: string}|null
     */
    public static function banner(): ?array
    {
        // Asked on every page, so cached briefly; posting forgets it.
        try {
            return Cache::remember(self::BANNER_CACHE, 30, function () {
                $incident = StatusIncident::open()->where('show_in_banner', true)->latest('started_at')->first();

                return $incident ? self::bannerFor($incident) : null;
            });
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** @return array{message: string, tone: string, id: string, url: string} */
    private static function bannerFor(StatusIncident $incident): array
    {
        return [
            'message' => $incident->title.' — '.self::statusLabel($incident->status).'.',
            'tone' => match ($incident->severity) {
                'critical', 'major' => 'danger',
                'maintenance' => 'info',
                default => 'warning',
            },
            // A new update brings a dismissed banner back.
            'id' => 'incident-'.$incident->id.'-'.$incident->updated_at?->getTimestamp(),
            'url' => route('platform.status'),
        ];
    }

    // ── Incidents (staff, audited) ──────────────────────────────────────

    /** @param  array{title: string, severity: string, status: string, components: list<string>, body: string, show_in_banner?: bool}  $data */
    public function open(array $data, User $actor): StatusIncident
    {
        $data = self::validated($data);

        $incident = AdminAudit::transaction(function () use ($data, $actor) {
            $incident = StatusIncident::create([
                'title' => $data['title'],
                'severity' => $data['severity'],
                'status' => $data['status'],
                'components' => $data['components'],
                'show_in_banner' => (bool) ($data['show_in_banner'] ?? false),
                'started_at' => now(),
                'resolved_at' => $data['status'] === 'resolved' ? now() : null,
                'created_by' => $actor->id,
            ]);

            $incident->updates()->create(['status' => $data['status'], 'body' => $data['body'], 'created_by' => $actor->id]);

            AdminAudit::record('incident.opened', $incident, null, [
                'title' => $incident->title, 'severity' => $incident->severity, 'components' => $incident->components,
            ], null, $actor);

            return $incident;
        });

        Cache::forget('status-page.signals');
        Cache::forget(self::BANNER_CACHE);

        return $incident;
    }

    /** A timeline entry; "resolved" closes the incident, anything else reopens it. */
    public function post(StatusIncident $incident, string $status, string $body, User $actor, array $changes = []): void
    {
        if (! in_array($status, StatusIncident::STATUSES, true)) {
            throw ValidationException::withMessages(['status' => 'Choose one of: '.implode(', ', StatusIncident::STATUSES).'.']);
        }

        if (mb_strlen(trim($body)) < 4) {
            throw ValidationException::withMessages(['body' => 'Say what changed.']);
        }

        AdminAudit::transaction(function () use ($incident, $status, $body, $actor, $changes) {
            $before = $incident->only(['status', 'severity', 'components', 'show_in_banner']);

            $values = ['status' => $status, 'resolved_at' => $status === 'resolved' ? ($incident->resolved_at ?? now()) : null];

            if (isset($changes['severity']) && in_array($changes['severity'], StatusIncident::SEVERITIES, true)) {
                $values['severity'] = $changes['severity'];
            }

            if (isset($changes['components'])) {
                $values['components'] = self::components($changes['components']);
            }

            if (array_key_exists('show_in_banner', $changes)) {
                $values['show_in_banner'] = (bool) $changes['show_in_banner'];
            }

            $incident->forceFill($values)->save();
            $incident->updates()->create(['status' => $status, 'body' => trim($body), 'created_by' => $actor->id]);

            [$from, $to] = AdminAudit::diff($before, $incident->only(['status', 'severity', 'components', 'show_in_banner']));
            AdminAudit::record($status === 'resolved' ? 'incident.resolved' : 'incident.updated', $incident, $from, $to, null, $actor);
        });

        Cache::forget(self::BANNER_CACHE);
    }

    /** @return array<string, mixed> */
    public static function present(StatusIncident $incident): array
    {
        return [
            'id' => $incident->id,
            'title' => $incident->title,
            'severity' => $incident->severity,
            'status' => $incident->status,
            'status_label' => self::statusLabel($incident->status),
            'components' => array_values(array_intersect_key(self::COMPONENTS, array_flip($incident->components ?? []))),
            'component_keys' => $incident->components ?? [],
            'show_in_banner' => $incident->show_in_banner,
            'started_at' => $incident->started_at?->toIso8601String(),
            'resolved_at' => $incident->resolved_at?->toIso8601String(),
            'updates' => $incident->updates->map(fn ($u) => [
                'status' => $u->status,
                'status_label' => self::statusLabel($u->status),
                'body' => $u->body,
                'at' => $u->created_at?->toIso8601String(),
            ])->values()->all(),
        ];
    }

    public static function statusLabel(string $status): string
    {
        return ['investigating' => 'Investigating', 'identified' => 'Cause identified', 'monitoring' => 'Fix in place, monitoring', 'resolved' => 'Resolved'][$status] ?? $status;
    }

    public static function worse(string $a, string $b): string
    {
        return array_search($a, self::STATES, true) >= array_search($b, self::STATES, true) ? $a : $b;
    }

    // ── Signals ─────────────────────────────────────────────────────────

    private function api(): array
    {
        $url = rtrim((string) config('platform.status.api_url'), '/');

        if ($url === '') {
            return ['status' => 'unknown', 'note' => 'Not measured from here.'];
        }

        try {
            $response = Http::timeout(3)->connectTimeout(2)->get($url.'/up');

            return $response->successful()
                ? ['status' => 'operational', 'note' => null]
                : ['status' => 'major_outage', 'note' => 'The API answered with an error.'];
        } catch (Throwable) {
            return ['status' => 'major_outage', 'note' => 'The API did not answer.'];
        }
    }

    /** @param  array<string, array<string, mixed>>  $checks */
    private function exports(array $checks): array
    {
        $worker = $checks['worker']['status'] ?? 'unknown';

        if ($worker === 'failing') {
            return ['status' => 'major_outage', 'note' => 'The export worker is not running: PDF and EPUB files wait in the queue.'];
        }

        $stuck = BookExport::where('status', 'generating')->where('updated_at', '<', now()->subSeconds(BookExport::STALE_AFTER))->count();
        $hour = BookExport::where('created_at', '>=', now()->subHour());
        $failed = (clone $hour)->where('status', 'failed')->count();
        $made = (clone $hour)->where('status', 'ready')->count();

        if ($failed >= 3 && $failed > $made) {
            return ['status' => 'partial_outage', 'note' => 'Most exports in the last hour failed.'];
        }

        if ($stuck > 0) {
            return ['status' => 'degraded', 'note' => 'Some exports are taking longer than they should.'];
        }

        return $worker === 'unknown'
            ? ['status' => 'unknown', 'note' => 'The worker has not reported yet.']
            : ['status' => 'operational', 'note' => null];
    }

    private function email(?array $check, \Closure $state): array
    {
        $failed = MailLog::where('created_at', '>=', now()->subHour())->where('status', 'failed')->count();
        $sent = MailLog::where('created_at', '>=', now()->subHour())->where('status', 'sent')->count();

        if ($failed >= 3 && $failed > $sent) {
            return ['status' => 'partial_outage', 'note' => 'Most emails in the last hour could not be sent.'];
        }

        $status = $state($check['status'] ?? 'unknown');

        return ['status' => $status, 'note' => match ($status) {
            'operational' => null,
            'degraded' => 'Email is not reaching inboxes from this environment.',
            'major_outage' => 'Email is not configured.',
            default => 'Not measured from here.',
        }];
    }

    private function storefront(?array $stripe): array
    {
        if (! Commerce::enabled()) {
            return ['status' => 'unavailable', 'note' => 'Direct sales are not offered.'];
        }

        $global = app(Features::class)->globals()['storefront'] ?? null;

        if ($global !== null && ! $global['enabled']) {
            return ['status' => 'maintenance', 'note' => 'Direct sales are switched off for now.'];
        }

        if (config('commerce.gateway') === 'stripe' && ($stripe['status'] ?? null) !== 'ok') {
            return ['status' => 'degraded', 'note' => 'Payments may not be confirmed.'];
        }

        return ['status' => 'operational', 'note' => null];
    }

    /** @return array{title: string, severity: string, status: string, components: list<string>, body: string, show_in_banner?: bool} */
    private static function validated(array $data): array
    {
        $errors = [];

        if (mb_strlen(trim((string) ($data['title'] ?? ''))) < 4) {
            $errors['title'] = 'Give it a title readers will understand.';
        }

        if (! in_array($data['severity'] ?? null, StatusIncident::SEVERITIES, true)) {
            $errors['severity'] = 'Choose a severity.';
        }

        if (! in_array($data['status'] ?? null, StatusIncident::STATUSES, true)) {
            $errors['status'] = 'Choose a status.';
        }

        $components = self::components($data['components'] ?? []);

        if ($components === []) {
            $errors['components'] = 'Choose at least one affected component.';
        }

        if (mb_strlen(trim((string) ($data['body'] ?? ''))) < 4) {
            $errors['body'] = 'Write the first update.';
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return ['title' => trim($data['title']), 'components' => $components, 'body' => trim($data['body'])] + $data;
    }

    /** @return list<string> */
    private static function components(mixed $keys): array
    {
        return array_values(array_intersect(array_keys(self::COMPONENTS), (array) $keys));
    }
}
