<?php

namespace App\Mail\Growth;

use App\Billing\PlanCatalog;
use App\Mail\EcrivainMail;
use App\Mail\Support\ContainsSecrets;
use App\Mail\Support\Letter;
use App\Models\AuthorInvitation;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * An invitation to join Écrivain, sent from the console
 * (App\Growth\Invitations\Invitations).
 *
 * Addressed to somebody without an account, so there are no preferences to
 * consult and no unsubscribe footer: it is one personal message, not a list
 * mailing (category "collaboration", like a book invitation). What stands in
 * for an unsubscribe is "Don't invite me again", which puts the address on
 * the do-not-invite list.
 *
 * Written in the invitation's language. The copy lives here rather than in
 * lang/*.json because the app has no translation files yet; the shared
 * footer is still English. The Haitian Creole is awaiting review by a
 * native speaker (docs/EMAILS.md).
 *
 * Carries the raw token (the only place it exists), so it is ContainsSecrets:
 * the email log keeps metadata only and cannot resend it — a resend from the
 * console mints a new token.
 */
class AuthorInvitationMail extends EcrivainMail implements ContainsSecrets, ShouldQueue
{
    public const CATEGORY = 'collaboration';

    /** Every string, per language. `ht`: pending native review. */
    public const COPY = [
        'en' => [
            'team' => 'the :brand team',
            'subject' => 'Your invitation to write on :brand',
            'preheader' => 'Create your account. The invitation is open until :date.',
            'subtitle' => 'You are invited',
            'eyebrow' => 'Invitation',
            'headline' => 'Write your book with :brand',
            'lede' => ':inviter invites you to create your account: a writing studio with chapters, editorial review, and print and ebook exports.',
            'note' => 'A note from :inviter',
            'perks' => 'Included with your invitation',
            'perk_plan' => ':plan plan for :months months',
            'perk_plan_one' => ':plan plan for 1 month',
            'perk_credits' => ':credits AI credits',
            'cta' => 'Create your account',
            'callout_title' => 'Open until :date',
            'callout' => 'It works only for :email.',
            'decline' => 'Not interested? Don’t invite me again',
            'reason' => 'You received this because :inviter invited this address. If you were not expecting it, you can ignore it.',
        ],
        'fr' => [
            'team' => 'l’équipe :brand',
            'subject' => 'Votre invitation à écrire sur :brand',
            'preheader' => 'Créez votre compte : l’invitation est valable jusqu’au :date.',
            'subtitle' => 'Vous êtes invité(e)',
            'eyebrow' => 'Invitation',
            'headline' => 'Écrivez votre livre avec :brand',
            'lede' => ':inviter vous invite à créer votre compte : un atelier d’écriture avec chapitres, relecture éditoriale et exports pour l’impression et le livre numérique.',
            'note' => 'Un mot de :inviter',
            'perks' => 'Offert avec votre invitation',
            'perk_plan' => 'Formule :plan pendant :months mois',
            'perk_plan_one' => 'Formule :plan pendant 1 mois',
            'perk_credits' => ':credits crédits IA',
            'cta' => 'Créer mon compte',
            'callout_title' => 'Valable jusqu’au :date',
            'callout' => 'Elle fonctionne uniquement pour :email.',
            'decline' => 'Pas intéressé(e) ? Ne plus m’inviter',
            'reason' => 'Vous recevez ce message parce que :inviter a invité cette adresse. Si vous ne l’attendiez pas, vous pouvez l’ignorer.',
        ],
        // Awaiting review by a native Kreyòl speaker.
        'ht' => [
            'team' => 'ekip :brand',
            'subject' => 'Envitasyon ou pou ekri sou :brand',
            'preheader' => 'Kreye kont ou. Envitasyon an valab jiska :date.',
            'subtitle' => 'Ou envite',
            'eyebrow' => 'Envitasyon',
            'headline' => 'Ekri liv ou ak :brand',
            'lede' => ':inviter envite w kreye kont ou: yon atelye pou ekri, ak chapit, revizyon editoryal, ak ekspòtasyon pou enprime ak pou liv elektwonik.',
            'note' => 'Yon mo nan men :inviter',
            'perks' => 'Sa ki vini ak envitasyon an',
            'perk_plan' => 'Plan :plan pandan :months mwa',
            'perk_plan_one' => 'Plan :plan pandan 1 mwa',
            'perk_credits' => ':credits kredi IA',
            'cta' => 'Kreye kont mwen',
            'callout_title' => 'Valab jiska :date',
            'callout' => 'Li mache sèlman pou :email.',
            'decline' => 'Sa pa enterese w? Pa envite m ankò',
            'reason' => 'Ou resevwa mesaj sa a paske :inviter envite adrès sa a. Si w pa t ap tann li, ou ka inyore l.',
        ],
    ];

    public function __construct(public AuthorInvitation $invitation, public string $token)
    {
        $this->locale($this->language());
    }

    public function joinUrl(): string
    {
        return route('join.show', $this->token);
    }

    public function letter(): Letter
    {
        $invitation = $this->invitation;
        $date = $invitation->expires_at->format('Y-m-d');
        $inviter = $this->inviterName();

        $letter = Letter::make($this->t('subject'))
            ->preheader($this->t('preheader', ['date' => $date]))
            ->subtitle($this->t('subtitle'))
            ->eyebrow($this->t('eyebrow'))
            ->severity('action')
            ->headline($this->t('headline'))
            ->lede(self::sentence($this->t('lede', ['inviter' => $inviter])));

        // The note is staff's own words: a quote, and like every slot plain
        // text, escaped by the layout.
        if (trim((string) $invitation->note) !== '') {
            $letter->quote(trim($invitation->note), $this->t('note', ['inviter' => $inviter]));
        }

        $perks = $this->perkLines();

        if ($perks !== []) {
            $letter->items(array_map(fn (string $line) => ['title' => $line], $perks), $this->t('perks'));
        }

        return $letter
            ->cta($this->t('cta'), $this->joinUrl(), showFallbackUrl: true)
            ->secondary($this->t('decline'), route('join.show', ['token' => $this->token, 'decline' => 1]))
            ->callout('info', $this->t('callout', ['email' => $invitation->email]), $this->t('callout_title', ['date' => $date]))
            ->reason(self::sentence($this->t('reason', ['inviter' => $inviter])));
    }

    /** @return list<string> */
    public function perkLines(): array
    {
        $perks = $this->invitation->perkList();
        $lines = [];

        if ($perks['plan'] && $perks['plan_months']) {
            $plan = app(PlanCatalog::class)->get($perks['plan']);
            $lines[] = $this->t($perks['plan_months'] === 1 ? 'perk_plan_one' : 'perk_plan', [
                'plan' => $plan['name'] ?? ucfirst($perks['plan']),
                'months' => $perks['plan_months'],
            ]);
        }

        if ($perks['credits']) {
            $lines[] = $this->t('perk_credits', ['credits' => number_format($perks['credits'])]);
        }

        return $lines;
    }

    private function inviterName(): string
    {
        $name = config('growth.invitations.show_inviter_name') ? trim((string) $this->invitation->inviter?->name) : '';

        return $name !== '' ? $name : $this->t('team');
    }

    private function language(): string
    {
        return array_key_exists($this->invitation->language, self::COPY) ? $this->invitation->language : 'en';
    }

    /** @param  array<string, scalar>  $replace */
    private function t(string $key, array $replace = []): string
    {
        $line = self::COPY[$this->language()][$key];

        foreach (['brand' => config('mail.ecrivain.brand')] + $replace as $name => $value) {
            $line = str_replace(':'.$name, (string) $value, $line);
        }

        return $line;
    }

    /** "the Écrivain team invites you…" opens a sentence: capitalise it. */
    private static function sentence(string $text): string
    {
        return mb_strtoupper(mb_substr($text, 0, 1)).mb_substr($text, 1);
    }
}
