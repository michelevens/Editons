<?php

namespace App\Mail;

use App\Mail\Support\Letter;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\URL;

/**
 * Every email Écrivain sends.
 *
 * A subclass says what the email is — its Letter — and which preference
 * category it belongs to. Everything else is decided here once: the sender,
 * the HTML and plain-text views, the footer links and the unsubscribe
 * headers. Subclasses that should be queued implement ShouldQueue; the two
 * that must arrive while somebody is waiting for them (password reset,
 * email verification) do not.
 *
 * Copy goes through __() so the strings can be translated without touching
 * a template, and the Letter is built inside envelope()/content(), which
 * Laravel calls inside the Mailable's locale — so ->locale('fr') works.
 *
 * Send these through App\Mail\Notify, never Mail:: directly: Notify is where
 * the recipient's preferences are consulted.
 */
abstract class EcrivainMail extends Mailable
{
    use Queueable, SerializesModels;

    /** A key of EmailPreferences::CATEGORIES. */
    public const CATEGORY = 'account';

    /** What this email says. */
    abstract public function letter(): Letter;

    /**
     * The account the email is addressed to, when there is one.
     *
     * Drives the footer: "Manage email preferences" and the unsubscribe link
     * only make sense for somebody who can sign in and change them.
     */
    public function account(): ?User
    {
        return null;
    }

    public function category(): string
    {
        return static::CATEGORY;
    }

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(
                config('mail.ecrivain.from_address'),
                config('mail.ecrivain.from_name'),
            ),
            replyTo: [new Address(
                config('mail.ecrivain.support_address'),
                config('mail.ecrivain.brand'),
            )],
            subject: $this->letter()->subject,
            tags: [$this->category()],
            metadata: ['email' => static::slug()],
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.standard',
            text: 'emails.text.standard',
            with: $this->viewData(),
        );
    }

    public function headers(): Headers
    {
        $unsubscribe = $this->unsubscribeUrl();

        return new Headers(text: $unsubscribe ? [
            // RFC 8058 one-click: Gmail and Apple Mail show their own
            // unsubscribe button, and POST to this URL without a page.
            'List-Unsubscribe' => '<'.$unsubscribe.'>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ] : []);
    }

    /**
     * Everything the two layouts need.
     *
     * @return array<string, mixed>
     */
    public function viewData(): array
    {
        $account = $this->account();

        return [
            'letter' => $this->letter(),
            'brand' => config('mail.ecrivain.brand'),
            'supportAddress' => config('mail.ecrivain.support_address'),
            'preferencesUrl' => $account ? route('profile.edit').'#email-preferences' : null,
            'unsubscribeUrl' => $this->unsubscribeUrl(),
            'locale' => str_replace('_', '-', $this->locale ?? app()->getLocale()),
        ];
    }

    /** A signed link that switches this category off, for marketing mail only. */
    public function unsubscribeUrl(): ?string
    {
        $account = $this->account();

        if (! $account || ! EmailPreferences::isMarketing($this->category())) {
            return null;
        }

        return URL::signedRoute('email.unsubscribe', [
            'user' => $account->id,
            'category' => $this->category(),
        ]);
    }

    /** The name used in the preview gallery and in tags: welcome, export-ready… */
    public static function slug(): string
    {
        $base = class_basename(static::class);
        $base = preg_replace('/Mail$/', '', $base);

        return strtolower(preg_replace('/(?<!^)[A-Z]/', '-$0', $base));
    }

    /** First name, for greetings. One form, resolved here, every email. */
    protected static function firstName(?string $name): ?string
    {
        $name = trim((string) $name);

        return $name === '' ? null : explode(' ', $name)[0];
    }

    /** "Hi Ada," — or no greeting at all, never "Hi there,". */
    protected static function greet(?string $name, string $sentence): string
    {
        $first = self::firstName($name);

        return $first
            ? __('Hi :name, :rest', ['name' => $first, 'rest' => $sentence])
            : ucfirst($sentence);
    }
}
