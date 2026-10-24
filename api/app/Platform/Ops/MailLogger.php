<?php

namespace App\Platform\Ops;

use App\Mail\EcrivainMail;
use App\Mail\EmailPreferences;
use App\Mail\Support\ContainsSecrets;
use App\Models\MailLog;
use App\Models\User;
use App\Platform\AdminAudit;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\SendQueuedMailable;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use ReflectionClass;
use Symfony\Component\Mailer\Header\MetadataHeader;
use Symfony\Component\Mime\Email;
use Throwable;

/**
 * The email log: every message the mailer handed to the transport, and
 * every one that failed on the way.
 *
 * Written from Laravel's MessageSent event, so it sees mail however it was
 * sent — Notify, a notification, a queued job — and records what actually
 * left, not what was meant to. Metadata always: to, mailable, subject,
 * category, the provider's message id. The plain-text body too, unless the
 * mailable says it ContainsSecrets (password reset, verification,
 * invitations), and with signed links blanked out in any case.
 *
 * "Sent" means handed to the provider. Delivery, bounces and complaints
 * need the provider's webhook, which is not connected; the console says so.
 */
final class MailLogger
{
    private const BODY_LIMIT = 20000;

    public static function listen(): void
    {
        Event::listen(MessageSent::class, fn (MessageSent $event) => self::sent($event));
        Event::listen(JobFailed::class, fn (JobFailed $event) => self::jobFailed($event));
    }

    public static function sent(MessageSent $event): ?MailLog
    {
        try {
            $email = $event->sent->getOriginalMessage();
            $class = $event->data['__laravel_mailable'] ?? null;
            $sensitive = $class !== null && is_subclass_of($class, ContainsSecrets::class);
            $to = $email instanceof Email ? array_map(fn ($a) => $a->getAddress(), $email->getTo()) : [];
            $address = mb_substr(implode(', ', $to), 0, 320);
            $headers = $email->getHeaders();

            $resentFrom = null;
            foreach ($headers->all() as $header) {
                if ($header instanceof MetadataHeader && $header->getKey() === 'resent_from') {
                    $resentFrom = (int) $header->getValue() ?: null;
                }
            }

            return MailLog::create([
                'user_id' => $to ? User::where('email', $to[0])->value('id') : null,
                'to' => $address,
                'mailable' => $class,
                'subject' => $email instanceof Email ? mb_substr((string) $email->getSubject(), 0, 500) : null,
                'category' => self::category($class),
                'status' => 'sent',
                'provider_message_id' => $headers->get('X-Resend-Email-ID')?->getBodyAsString() ?: $event->sent->getMessageId(),
                'sensitive' => $sensitive,
                'body_text' => $sensitive || ! $email instanceof Email ? null : self::cleanBody((string) $email->getTextBody()),
                'resend_args' => $class ? self::encodeArgs($class, $event->data, $sensitive) : null,
                'resent_from_id' => $resentFrom && MailLog::whereKey($resentFrom)->exists() ? $resentFrom : null,
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** A mail that threw before it reached the transport (Notify catches it). */
    public static function failed(Mailable $mail, string $address, Throwable $error): ?MailLog
    {
        try {
            return MailLog::create([
                'user_id' => User::where('email', $address)->value('id'),
                'to' => mb_substr($address, 0, 320),
                'mailable' => $mail::class,
                'subject' => $mail instanceof EcrivainMail ? mb_substr((string) rescue(fn () => $mail->letter()->subject, null, false), 0, 500) : null,
                'category' => self::category($mail::class),
                'status' => 'failed',
                'sensitive' => $mail instanceof ContainsSecrets,
                'error' => mb_substr(class_basename($error).': '.$error->getMessage(), 0, 1000),
            ]);
        } catch (Throwable $e) {
            report($e);

            return null;
        }
    }

    /** A queued mailable whose job failed for good. */
    public static function jobFailed(JobFailed $event): void
    {
        try {
            $payload = $event->job->payload();

            if (($payload['data']['commandName'] ?? null) !== SendQueuedMailable::class) {
                return;
            }

            $command = unserialize((string) $payload['data']['command']);
            $mail = $command instanceof SendQueuedMailable ? $command->mailable : null;
            $address = $mail?->to[0]['address'] ?? null;

            if ($mail && $address) {
                self::failed($mail, $address, $event->exception);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    /**
     * Build the same mailable again and send it to the same address,
     * audited. Preferences still apply: a resend is not a way round an
     * unsubscribe.
     *
     * @throws ValidationException
     */
    public static function resend(MailLog $log, User $actor, string $reason): void
    {
        if (! $log->resendable()) {
            throw ValidationException::withMessages(['mail' => $log->sensitive
                ? 'This email carried a one-time link. Send a new one instead (a password reset from the account page).'
                : 'This email cannot be rebuilt from the log.']);
        }

        $args = self::decodeArgs($log->resend_args)
            ?? throw ValidationException::withMessages(['mail' => 'What this email was about no longer exists.']);

        $class = $log->mailable;
        /** @var Mailable $mail */
        $mail = new $class(...$args);
        $mail->metadata('resent_from', (string) $log->id);

        $account = $log->user_id ? User::find($log->user_id) : null;

        if ($mail instanceof EcrivainMail && ! EmailPreferences::allows($account, $mail->category())) {
            throw ValidationException::withMessages(['mail' => 'The recipient has turned this kind of email off.']);
        }

        AdminAudit::transaction(function () use ($log, $mail, $actor, $reason) {
            AdminAudit::record('mail.resent', $log, null, ['to' => $log->to, 'mailable' => $log->mailable], $reason, $actor);

            Mail::to(explode(', ', $log->to)[0])->send($mail);
        });
    }

    private static function category(?string $class): ?string
    {
        return $class && is_subclass_of($class, EcrivainMail::class) ? $class::CATEGORY : null;
    }

    /** The text body with signed links (unsubscribe, invitations) blanked out. */
    private static function cleanBody(string $text): ?string
    {
        if (trim($text) === '') {
            return null;
        }

        $text = preg_replace('~https?://\S*(signature=|/invitations/|/reset-password/|/verify-email/)\S*~i', '[link removed]', $text) ?? '';

        return mb_substr($text, 0, self::BODY_LIMIT);
    }

    /**
     * The mailable's constructor arguments, from the data the mailer was
     * given (a mailable's public properties): models as references, dates
     * as ISO strings. Null when any of them cannot be stored that way — or,
     * for a mail with a secret, when one is a string (it may be the link).
     *
     * @return array<string, mixed>|null
     */
    private static function encodeArgs(string $class, array $data, bool $sensitive): ?array
    {
        if (! is_subclass_of($class, Mailable::class)) {
            return null;
        }

        $constructor = (new ReflectionClass($class))->getConstructor();
        $args = [];

        foreach ($constructor?->getParameters() ?? [] as $param) {
            $name = $param->getName();

            if (! array_key_exists($name, $data)) {
                if ($param->isOptional()) {
                    continue;
                }

                return null;
            }

            $value = $data[$name];

            $encoded = match (true) {
                $value instanceof Model => ['__model' => $value::class, 'id' => $value->getKey()],
                $value instanceof DateTimeInterface => ['__date' => Carbon::instance($value)->toIso8601String()],
                is_string($value) && $sensitive => false,
                $value === null, is_scalar($value) => $value,
                is_array($value) && self::plain($value) => $value,
                default => false,
            };

            if ($encoded === false) {
                return null;
            }

            $args[$name] = $encoded;
        }

        return $args;
    }

    /** @return array<string, mixed>|null */
    private static function decodeArgs(array $args): ?array
    {
        $out = [];

        foreach ($args as $name => $value) {
            if (is_array($value) && isset($value['__model'])) {
                $model = class_exists($value['__model']) ? $value['__model']::find($value['id']) : null;

                if (! $model) {
                    return null;
                }

                $value = $model;
            } elseif (is_array($value) && isset($value['__date'])) {
                $value = Carbon::parse($value['__date']);
            }

            $out[$name] = $value;
        }

        return $out;
    }

    private static function plain(array $value): bool
    {
        foreach ($value as $item) {
            if (is_array($item) ? ! self::plain($item) : ! ($item === null || is_scalar($item))) {
                return false;
            }
        }

        return true;
    }
}
