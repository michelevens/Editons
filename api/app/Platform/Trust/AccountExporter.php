<?php

namespace App\Platform\Trust;

use App\Import\MarkdownExporter;
use App\Jobs\BuildAccountExport;
use App\Mail\Notify;
use App\Mail\Trust\AccountExportReadyMail;
use App\Models\AccountExport;
use App\Models\Book;
use App\Models\CreditTransaction;
use App\Models\User;
use App\Platform\AdminAudit;
use App\Publishing\PortableBook;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * A copy of everything an account holds, for the person it belongs to.
 *
 * One zip, built on the worker (BuildAccountExport): every book — in the bin
 * too — as Markdown, as a .ecrivain file that imports back with nothing
 * lost, and the newest EPUB the author generated if its file is still
 * there; each book's metadata as JSON; the account itself; and the credit
 * ledger, purchases and sales as CSV. Sales carry no buyer's name or
 * address: those belong to the buyer.
 *
 * Asked for by the owner (it contains manuscripts, and support never takes
 * a copy of a book away), audited when asked for and when downloaded.
 * Kept for EXPIRES_DAYS; the link emailed to the account is signed and
 * expires with it.
 */
final class AccountExporter
{
    public const EXPIRES_DAYS = 7;

    public function __construct(
        private readonly MarkdownExporter $markdown,
        private readonly PortableBook $portable,
    ) {}

    public function request(User $target, User $actor, bool $emailAccount, string $reason): AccountExport
    {
        if ($target->offboarded_at) {
            throw ValidationException::withMessages(['reason' => 'This account has been deleted; there is nothing left to export.']);
        }

        if (AccountExport::where('user_id', $target->id)->where('status', 'queued')->where('created_at', '>', now()->subHour())->exists()) {
            throw ValidationException::withMessages(['reason' => 'An export for this account is already being built.']);
        }

        $export = AdminAudit::transaction(function () use ($target, $actor, $emailAccount, $reason) {
            $export = AccountExport::create([
                'user_id' => $target->id,
                'requested_by' => $actor->id,
                'status' => 'queued',
                'email_account' => $emailAccount,
            ]);

            AdminAudit::record('account.export_requested', $target, null, ['export_id' => $export->id, 'email_account' => $emailAccount], $reason, $actor);

            return $export;
        });

        BuildAccountExport::dispatch($export->id);

        return $export;
    }

    /** Build the zip. Run by the job; settles the row either way. */
    public function build(AccountExport $export): AccountExport
    {
        $user = $export->user;

        if (! $user || $export->status !== 'queued') {
            return $export;
        }

        $temp = [];

        try {
            $zipPath = tempnam(sys_get_temp_dir(), 'acx').'.zip';
            $temp[] = $zipPath;
            $zip = new ZipArchive;

            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('Could not create the zip.');
            }

            $contents = ['books' => 0, 'epubs' => 0, 'ledger_rows' => 0, 'purchases' => 0, 'sales' => 0];
            $disk = Storage::disk('files');

            $zip->addFromString('account.json', self::json([
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'author_name' => $user->author_name,
                'bio' => $user->bio,
                'website' => $user->website,
                'plan' => $user->plan,
                'store_handle' => $user->store_handle,
                'created_at' => $user->created_at?->toIso8601String(),
                'exported_at' => now()->toIso8601String(),
            ]));

            $books = Book::withTrashed()->where('user_id', $user->id)->orderBy('id')->get();

            foreach ($books as $i => $book) {
                $dir = sprintf('books/%02d-%s', $i + 1, Str::slug($book->title ?: 'book') ?: 'book');

                $zip->addFromString("{$dir}/manuscript.md", $this->markdown->book($book));

                [$portable] = $this->portable->export($book);
                $temp[] = $portable;
                $zip->addFile($portable, "{$dir}/book.ecrivain");

                $zip->addFromString("{$dir}/metadata.json", self::json([
                    'id' => $book->id,
                    'title' => $book->title,
                    'subtitle' => $book->subtitle,
                    'author_name' => $book->author_name,
                    'genre' => $book->genre,
                    'book_type' => $book->book_type,
                    'language' => $book->language,
                    'status' => $book->status,
                    'in_bin' => $book->trashed(),
                    'word_count' => (int) $book->word_count,
                    'series' => $book->series_name ? ['name' => $book->series_name, 'number' => $book->series_number] : null,
                    'isbn' => $book->isbn,
                    'publication' => $book->publication,
                    'created_at' => $book->created_at?->toIso8601String(),
                    'updated_at' => $book->updated_at?->toIso8601String(),
                ]));

                $epub = $book->exports()->where('format', 'epub')->where('status', 'ready')->latest('id')->first();

                if ($epub && $epub->path && $disk->exists($epub->path)) {
                    $local = tempnam(sys_get_temp_dir(), 'acxe');
                    $temp[] = $local;
                    file_put_contents($local, $disk->get($epub->path));
                    $zip->addFile($local, "{$dir}/".(Str::slug($book->title ?: 'book') ?: 'book').'.epub');
                    $contents['epubs']++;
                }

                $contents['books']++;
            }

            $ledger = CreditTransaction::where('user_id', $user->id)->orderBy('id')->get(['id', 'created_at', 'kind', 'label', 'amount']);
            $zip->addFromString('credit-ledger.csv', self::csv(['id', 'date', 'kind', 'label', 'credits'], $ledger->map(fn ($r) => [
                $r->id, $r->created_at?->toIso8601String(), $r->kind, $r->label, $r->amount,
            ])->all()));
            $contents['ledger_rows'] = $ledger->count();

            $purchases = DB::table('orders')->where('buyer_user_id', $user->id)->orderBy('id')
                ->get(['number', 'created_at', 'paid_at', 'status', 'currency', 'total_cents', 'refunded_cents']);
            $zip->addFromString('purchases.csv', self::csv(['order', 'created', 'paid', 'status', 'currency', 'total', 'refunded'], $purchases->map(fn ($o) => [
                $o->number, $o->created_at, $o->paid_at, $o->status, strtoupper($o->currency), $o->total_cents / 100, $o->refunded_cents / 100,
            ])->all()));
            $contents['purchases'] = $purchases->count();

            $sales = DB::table('order_items as i')->join('orders as o', 'o.id', '=', 'i.order_id')
                ->where('i.seller_id', $user->id)->whereNotNull('o.paid_at')->orderBy('i.id')
                ->get(['o.number', 'o.paid_at', 'o.status', 'o.currency', 'i.title', 'i.price_cents', 'i.fee_cents', 'i.royalty_cents', 'i.refunded_cents']);
            $zip->addFromString('sales.csv', self::csv(['order', 'paid', 'status', 'currency', 'title', 'price', 'fee', 'royalty', 'refunded'], $sales->map(fn ($s) => [
                $s->number, $s->paid_at, $s->status, strtoupper($s->currency), $s->title, $s->price_cents / 100, $s->fee_cents / 100, $s->royalty_cents / 100, $s->refunded_cents / 100,
            ])->all()));
            $contents['sales'] = $sales->count();

            $zip->addFromString('README.txt', implode("\n", [
                'Your Écrivain account, exported '.now()->toDayDateTimeString().'.',
                '',
                'books/          one folder per book: manuscript.md (Markdown), book.ecrivain',
                '                (imports back into Écrivain with nothing lost), metadata.json,',
                '                and the newest EPUB you generated, if it was still stored.',
                'account.json    your profile.',
                'credit-ledger.csv, purchases.csv, sales.csv: your records.',
                '                Sales list what sold and what you earned, not who bought it.',
                '',
            ]));

            $zip->close();

            $path = "account-exports/user-{$user->id}/export-{$export->id}-".Str::lower(Str::random(12)).'.zip';
            $stream = fopen($zipPath, 'rb');
            $disk->put($path, $stream);
            is_resource($stream) && fclose($stream);

            $export->forceFill([
                'status' => 'ready',
                'path' => $path,
                'byte_size' => filesize($zipPath),
                'sha256' => hash_file('sha256', $zipPath),
                'contents' => $contents,
                'expires_at' => now()->addDays(self::EXPIRES_DAYS),
                'error' => null,
            ])->save();

            if ($export->email_account) {
                Notify::send($user, new AccountExportReadyMail($user, self::signedUrl($export), $export->expires_at, $contents['books']));
                $export->forceFill(['emailed_at' => now()])->save();
            }
        } catch (Throwable $e) {
            report($e);
            $export->forceFill(['status' => 'failed', 'error' => mb_substr(class_basename($e).': '.$e->getMessage(), 0, 500)])->save();
        } finally {
            foreach ($temp as $file) {
                @unlink($file);
            }
        }

        return $export->fresh();
    }

    /** The link in the email: signed, and no longer than the file is kept. */
    public static function signedUrl(AccountExport $export): string
    {
        return URL::temporarySignedRoute('account-exports.download', $export->expires_at ?? now()->addDays(self::EXPIRES_DAYS), ['export' => $export->id]);
    }

    /** Expired files are deleted; the row stays as history. */
    public static function purgeExpired(): int
    {
        $n = 0;

        foreach (AccountExport::where('status', 'ready')->where('expires_at', '<', now())->get() as $export) {
            try {
                $export->path && Storage::disk('files')->delete($export->path);
            } catch (Throwable $e) {
                report($e);

                continue;
            }

            $export->forceFill(['status' => 'expired', 'path' => null])->save();
            $n++;
        }

        return $n;
    }

    private static function json(array $data): string
    {
        return json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /** CSV, with cells a spreadsheet would run as formulas quoted (as the audit export does). */
    private static function csv(array $head, array $rows): string
    {
        $out = fopen('php://temp', 'r+');
        fputcsv($out, $head, escape: '');

        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($cell) => is_string($cell) && preg_match('/^[=+\-@\t\r]/', $cell) ? "'".$cell : $cell, $row), escape: '');
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
