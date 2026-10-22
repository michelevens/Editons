<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\ResetPasswordNotification;
use App\Notifications\VerifyEmailNotification;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Billable, HasFactory, Notifiable;

    /** The plan's configured limits, falling back to free if it is unknown. */
    public function planLimits(): array
    {
        return config("billing.plans.{$this->plan}")
            ?? config('billing.plans.free');
    }

    /**
     * May this account create another book?
     *
     * Enforced on the server. The prototype gates limits client-side, which
     * tells an honest user what to expect but stops nobody.
     */
    public function canCreateBook(): bool
    {
        $limit = $this->planLimits()['books'];

        return $limit === null || $this->books()->count() < $limit;
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
            // Overrides only; App\Mail\EmailPreferences resolves defaults.
            'email_preferences' => 'array',
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
