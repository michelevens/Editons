<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Billing\PlanCatalog;
use App\I18n\Locales;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Str;
use Laravel\Cashier\Billable;

/*
 * `plan`, `credits_granted_at` and Cashier's columns are deliberately absent:
 * what somebody is subscribed to and when their allowance was granted are
 * decided by the billing code, never by a profile form.
 */
#[Fillable([
    'name', 'email', 'password',
    'author_name', 'bio', 'website', 'default_book_type', 'daily_word_goal',
])]
#[Hidden([
    'password', 'remember_token',
    // Shared with every Inertia page as auth.user, so anything secret about
    // the account must be listed here or it reaches the browser.
    'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step',
    'suspended_reason', 'sessions_revoked_at',
    // Set by the owner in the console; the author is told when it is reached.
    'ai_monthly_cap_usd',
    // Where the account came from (App\Growth\Attribution): the console's.
    'signup_source', 'signup_medium', 'signup_campaign', 'signup_referrer', 'signup_landing',
    // The studio layout: sent to the editor on its own, not to every page.
    'workspace',
])]
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable;

    /** The appearance choices; 'system' follows prefers-color-scheme. */
    public const THEMES = ['system', 'light', 'dark'];

    /**
     * Platform roles: the people who run Écrivain, not authors on it.
     *
     * Granted only from the shell (`php artisan platform:admin`), never by a
     * form an author can reach and never by a seeder. Owner can change plans,
     * settings and roles; support can look, take notes and "View as".
     */
    public const PLATFORM_ROLES = ['owner', 'support'];

    /**
     * The account's interface language, which its email uses too (Laravel
     * reads this for notifications; App\Mail\Notify passes it on). Null
     * until the first visit stores one: the application's default.
     */
    public function preferredLocale(): ?string
    {
        return Locales::supported($this->locale) ? $this->locale : null;
    }

    public function isPlatformAdmin(): bool
    {
        return in_array($this->platform_role, self::PLATFORM_ROLES, true);
    }

    public function isPlatformOwner(): bool
    {
        return $this->platform_role === 'owner';
    }

    /** Has this account finished turning on two-factor sign-in? */
    public function hasTwoFactor(): bool
    {
        return $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null;
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }

    /** account_kind: every account is an author's, except the platform's own publishers. */
    public const KINDS = ['author', 'house'];

    /**
     * A house publisher account (ADR — house catalog): the platform's own
     * imprint, e.g. "Écrivain Prépa". Nobody signs in to it and no email
     * goes to it; staff run its books from the console's House catalog.
     */
    public function isHouse(): bool
    {
        return $this->account_kind === 'house';
    }

    /**
     * The zone this account's sales reports cut days in: its own, or the
     * platform's reporting zone (config commerce.report_timezone).
     */
    public function reportTimezone(): string
    {
        $zone = $this->timezone;

        return $zone && in_array($zone, \DateTimeZone::listIdentifiers(), true)
            ? $zone
            : (string) config('commerce.report_timezone', 'America/New_York');
    }

    /**
     * Everybody but the house accounts: what growth, broadcasts, lifecycle
     * mail and the "real author" figures count.
     */
    public function scopeExceptHouse($query)
    {
        return $query->where('users.account_kind', '!=', 'house');
    }

    /**
     * The plan the limits come from.
     *
     * A complimentary plan past its end date counts as free from that
     * moment, even before platform:expire-complimentary has written it back:
     * a scheduler that is late must not leave a gift running.
     */
    public function effectivePlan(): string
    {
        if ($this->complimentary_until !== null && $this->complimentary_until->isPast()) {
            return 'free';
        }

        return (string) $this->plan;
    }

    /**
     * The plan's limits, from the plan catalogue (App\Billing\PlanCatalog:
     * the plans table, with config/billing.php as its fallback), falling
     * back to free if the plan is unknown.
     */
    public function planLimits(): array
    {
        $catalog = app(PlanCatalog::class);

        return $catalog->get($this->effectivePlan())
            ?? $catalog->get('free')
            ?? config('billing.plans.free');
    }

    /**
     * May this account create another book?
     *
     * Enforced on the server. The prototype gates limits client-side, which
     * tells an honest user what to expect but stops nobody.
     *
     * The limit is on active books: every book the account owns that is not
     * archived, editions included (a translation or workbook is a book on
     * the shelf like any other). Archived books do not count, which is what
     * the shelf's "Archive one, or move to a larger plan" has always told
     * the author; restoring one is held to the same limit (BookController).
     */
    public function canCreateBook(): bool
    {
        $limit = $this->planLimits()['books'];

        return $limit === null || $this->activeBookCount() < $limit;
    }

    /** Owned books that count toward the plan's limit. */
    public function activeBookCount(): int
    {
        return $this->books()->where('status', '!=', 'archived')->count();
    }

    public function canExportFormat(string $format): bool
    {
        return in_array($format, $this->planLimits()['formats'], true);
    }

    /** The credit ledger. The balance is the sum of these rows. */
    public function creditTransactions(): HasMany
    {
        return $this->hasMany(CreditTransaction::class);
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // Without this it comes back as a string and every date method
            // on it fails at runtime, which is how the monthly allowance
            // broke the moment anything read it.
            'credits_granted_at' => 'datetime',
            'credits_low_notified_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted:array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
            'suspended_at' => 'datetime',
            'sessions_revoked_at' => 'datetime',
            'complimentary_since' => 'datetime',
            'complimentary_until' => 'datetime',
            'ai_monthly_cap_usd' => 'decimal:2',
            'offboarded_at' => 'datetime',
            // Overrides only; App\Mail\EmailPreferences resolves defaults.
            'email_preferences' => 'array',
            // Frequency, inbox, mutes, quiet hours: App\Mail\NotificationSettings.
            'notification_settings' => 'array',
            // The writing workspace: App\Workspace\Workspace reads and writes it.
            'workspace' => 'array',
            'password' => 'hashed',
        ];
    }

    /** The reset link, in Écrivain's email template rather than Laravel's. */
    public function sendPasswordResetNotification(#[\SensitiveParameter] $token): void
    {
        $this->notify(new ResetPasswordNotification($token));
    }

    /** Ready for when MustVerifyEmail is switched on. */
    public function sendEmailVerificationNotification(): void
    {
        $this->notify(new VerifyEmailNotification);
    }

    /**
     * Books this user owns.
     *
     * Scoping every book query through this relation is what keeps one
     * author's manuscripts out of another's shelf (ADR-0006). Controllers
     * should reach for `$request->user()->books()` rather than `Book::query()`.
     */
    public function books(): HasMany
    {
        return $this->hasMany(Book::class);
    }

    /**
     * ISBNs in this account, assigned and unassigned.
     *
     * They belong to the account rather than to a book: you buy a block, then
     * assign numbers to editions as you publish them. The unassigned rows are
     * the pool the ISBN screen draws from.
     */
    public function isbns(): HasMany
    {
        return $this->hasMany(Isbn::class);
    }

    /** Books this user has on sale in their storefront. */
    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    /**
     * The storefront's public address, minted on first use.
     *
     * Opaque rather than the id or a name: the id counts accounts, and an
     * author who sells anonymously has no name to put in a URL.
     */
    public function storeHandle(): string
    {
        if (! $this->store_handle) {
            do {
                $handle = Str::lower(Str::random(12));
            } while (self::where('store_handle', $handle)->exists());

            $this->forceFill(['store_handle' => $handle])->saveQuietly();
        }

        return $this->store_handle;
    }

    /** Memberships of books belonging to somebody else. */
    public function bookMemberships(): HasMany
    {
        return $this->hasMany(BookMember::class);
    }

    /**
     * Every book this person can open: their own, plus any they have
     * accepted an invitation to.
     *
     * A query rather than a relation, because it unions two different
     * ownership paths. Without it an invited collaborator accepts and finds
     * an empty shelf, which is the whole feature failing quietly.
     */
    public function accessibleBooks()
    {
        return Book::query()
            ->where(fn ($query) => $query
                ->where('user_id', $this->id)
                ->orWhereHas('members', fn ($members) => $members
                    ->where('user_id', $this->id)
                    ->where('status', 'active')));
    }
}
