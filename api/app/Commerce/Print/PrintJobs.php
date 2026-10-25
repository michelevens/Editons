<?php

namespace App\Commerce\Print;

use App\Mail\Notify;
use App\Models\BookExport;
use App\Models\Order;
use App\Models\PrintJob;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Paid paperbacks, printed and shipped by Lulu.
 *
 * - open(): with the paid order (Fulfilment, inside its transaction), one
 *   `pending` print job per paperback item, from the files the product's
 *   paperback was offered with.
 * - submit(): sends a pending job to Lulu (POST /print-jobs/) with the
 *   buyer's shipping address. Under a row lock and only while it has no
 *   Lulu id, so a retried queue job never prints a copy twice; Lulu's
 *   `external_id` carries our order number as well.
 * - poll(): reads each open job's status; on SHIPPED it stores the
 *   tracking and emails it to the buyer, once.
 *
 * Failures are recorded on the job (status `failed`, the error, attempts)
 * and reported; nothing is retried for ever (SubmitPrintJob's tries).
 */
class PrintJobs
{
    public function __construct(
        private readonly LuluClient $client,
        private readonly LuluPrinting $printing,
    ) {}

    /** @return list<PrintJob> the jobs opened, to submit after the commit */
    public function open(Order $order): array
    {
        $jobs = [];

        foreach ($order->items()->with('product')->get() as $item) {
            if (! $item->isPaperback() || $item->printJob()->exists()) {
                continue;
            }

            $print = $item->product?->print ?? [];

            $job = new PrintJob;
            $job->forceFill([
                'order_id' => $order->id,
                'order_item_id' => $item->id,
                'book_id' => $item->book_id,
                'provider' => 'lulu',
                'status' => 'pending',
                'pod_package_id' => $print['pod_package_id'] ?? '',
                'page_count' => (int) ($print['page_count'] ?? 0),
                'interior_export_id' => $print['interior_export_id'] ?? null,
                'cover_export_id' => $print['cover_export_id'] ?? null,
            ])->save();

            $jobs[] = $job;
        }

        return $jobs;
    }

    /** Send one job to Lulu. Returns the job as it now stands. */
    public function submit(PrintJob $job): PrintJob
    {
        return DB::transaction(function () use ($job) {
            $job = PrintJob::whereKey($job->id)->lockForUpdate()->first();

            if ($job->external_id || ! in_array($job->status, ['pending', 'failed'], true)) {
                return $job;
            }

            $job->load('order', 'item');

            try {
                $response = $this->client->createPrintJob($this->payload($job));
            } catch (LuluException $e) {
                $job->forceFill([
                    'status' => 'failed',
                    'error' => mb_substr($e->getMessage(), 0, 2000),
                    'attempts' => $job->attempts + 1,
                ])->save();

                Log::error('Lulu print job not created.', ['print_job' => $job->id, 'order' => $job->order?->number, 'error' => $e->getMessage()]);

                return $job;
            }

            $cost = $response['costs']['total_cost_incl_tax'] ?? null;

            $job->forceFill([
                'external_id' => isset($response['id']) ? (string) $response['id'] : null,
                'status' => strtoupper((string) ($response['status']['name'] ?? 'CREATED')),
                'cost_cents' => $cost !== null && $cost !== '' ? (int) round((float) $cost * 100) : null,
                'cost_currency' => isset($response['costs']) ? strtolower((string) ($response['costs']['currency'] ?? 'usd')) : null,
                'error' => null,
                'attempts' => $job->attempts + 1,
                'sent_at' => now(),
            ])->save();

            return $job;
        });
    }

    /**
     * Read the status of every job Lulu has and that is not finished.
     *
     * @return array{checked: int, shipped: int, problems: int}
     */
    public function poll(int $limit = 200): array
    {
        $summary = ['checked' => 0, 'shipped' => 0, 'problems' => 0];

        $jobs = PrintJob::whereNotNull('external_id')
            ->whereNotIn('status', PrintJob::FINAL)
            ->orderByRaw('polled_at asc nulls first')
            ->limit($limit)
            ->get();

        foreach ($jobs as $job) {
            try {
                $status = $this->client->printJobStatus($job->external_id);
            } catch (LuluException $e) {
                report($e);

                continue;
            }

            $summary['checked']++;
            $this->apply($job, $status);

            if ($job->status === 'SHIPPED') {
                $summary['shipped']++;
            } elseif (in_array($job->status, PrintJob::PROBLEM, true)) {
                $summary['problems']++;
            }
        }

        return $summary;
    }

    /** One status reply from Lulu. */
    public function apply(PrintJob $job, array $status): PrintJob
    {
        $name = strtoupper((string) ($status['name'] ?? $job->status));
        $tracking = collect($status['line_item_statuses'] ?? [])
            ->map(fn ($line) => $line['messages'] ?? [])
            ->first(fn ($messages) => ! empty($messages['tracking_id']) || ! empty($messages['tracking_urls']));

        $job->forceFill([
            'status' => $name,
            'polled_at' => now(),
            'tracking' => $tracking ? [
                'id' => $tracking['tracking_id'] ?? null,
                'urls' => array_values((array) ($tracking['tracking_urls'] ?? [])),
                'carrier' => $tracking['carrier_name'] ?? null,
            ] : $job->tracking,
            'shipped_at' => $name === 'SHIPPED' ? ($job->shipped_at ?? now()) : $job->shipped_at,
            'error' => in_array($name, PrintJob::PROBLEM, true) ? ($status['message'] ?? $job->error) : $job->error,
        ])->save();

        if (in_array($name, PrintJob::PROBLEM, true)) {
            Log::error('Lulu print job did not go through.', ['print_job' => $job->id, 'status' => $name, 'message' => $status['message'] ?? null]);
        }

        if ($name === 'SHIPPED' && $job->buyer_notified_at === null) {
            $this->tellBuyer($job->fresh(['order', 'item']));
        }

        return $job;
    }

    // ── Internals ───────────────────────────────────────────────────────

    /** @return array<string, mixed> Lulu's print job request */
    private function payload(PrintJob $job): array
    {
        $order = $job->order;
        $address = $order->shipping_address ?? [];
        $interior = $job->interior_export_id ? BookExport::find($job->interior_export_id) : null;
        $cover = $job->cover_export_id ? BookExport::find($job->cover_export_id) : null;

        if (! $interior || ! $cover || $interior->status !== 'ready' || $cover->status !== 'ready') {
            throw new LuluException('The print files this paperback was sold with are no longer stored.');
        }

        if (empty($address['street1']) || empty($address['country_code'])) {
            throw new LuluException('The order has no shipping address.');
        }

        return [
            'contact_email' => (string) config('services.lulu.contact_email'),
            'external_id' => $order->number,
            'shipping_level' => (string) config('services.lulu.shipping_level', 'MAIL'),
            'line_items' => [[
                'external_id' => $order->number.'-'.$job->order_item_id,
                'title' => mb_substr((string) $job->item?->title, 0, 255),
                'quantity' => 1,
                'printable_normalization' => [
                    'cover' => ['source_url' => $this->printing->link($cover)],
                    'interior' => ['source_url' => $this->printing->link($interior)],
                    'pod_package_id' => $job->pod_package_id,
                ],
            ]],
            'shipping_address' => array_filter([
                'name' => $address['name'] ?? $order->buyer_name,
                'street1' => $address['street1'],
                'street2' => $address['street2'] ?? null,
                'city' => $address['city'] ?? null,
                'state_code' => $address['state_code'] ?? null,
                'postcode' => $address['postcode'] ?? null,
                'country_code' => $address['country_code'],
                'phone_number' => $address['phone_number'] ?? null,
                'email' => $order->buyer_email,
            ], fn ($value) => $value !== null && $value !== ''),
        ];
    }

    private function tellBuyer(PrintJob $job): void
    {
        $order = $job->order;

        if ($order?->buyer_email) {
            Notify::paperbackShipped(
                $order->buyer_email,
                buyerName: $order->buyer_name,
                orderNumber: $order->number,
                title: (string) $job->item?->title,
                carrier: $job->tracking['carrier'] ?? null,
                trackingId: $job->tracking['id'] ?? null,
                trackingUrl: $job->tracking['urls'][0] ?? null,
            );
        }

        $job->forceFill(['buyer_notified_at' => now()])->save();
    }
}
