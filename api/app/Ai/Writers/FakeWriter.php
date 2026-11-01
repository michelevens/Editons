<?php

namespace App\Ai\Writers;

use App\Ai\BookPrompts;
use App\Ai\Contracts\Writer;
use App\Ai\WriterRequest;
use App\Ai\WriterResult;
use App\Platform\Ai\AiPricing;
use RuntimeException;

/**
 * The sample writer: deterministic, free, and labelled (ADR — AI writes
 * the book).
 *
 * Answers every task in kind — a plan shaped by the brief, chapters built
 * from their key points, summaries, a consistency pass that flags a key
 * point two sections share — without calling anything. Every chapter it
 * writes opens by saying it is sample text, in the book's language, so it
 * cannot be mistaken for a model's draft. Used in tests and development,
 * and wherever no provider is configured.
 *
 * Token counts are estimated from characters, and the cost is what the
 * standard model would have charged, so the estimate, the budget and the
 * ledger can be exercised; in use, sample text is never charged
 * (`writer.charge_simulated`).
 */
class FakeWriter implements Writer
{
    public const MODEL = 'sample-writer';

    /** Set by a test to make the next calls fail like a provider would. */
    public static ?\Throwable $failWith = null;

    private const TEXT = [
        'en' => [
            'label' => '*Sample text from Écrivain\'s sample writer, not a model: it shows where your chapter will go.*',
            'open' => 'It is early morning, and the reader meets the question this section answers: %s.',
            'point' => 'This part covers %s. A practical example belongs here, with the details only you can supply %s.',
            'box' => '> **Your next step.** Write down one thing you will do this week about %s.',
            'title' => ['A Practical Guide', 'From Idea to Practice', 'The Essential Handbook'],
            'reader' => 'Readers new to the subject who want clear, practical steps.',
            'promise' => 'By the end, the reader can act on the idea with confidence.',
            'voice' => 'Warm, direct and practical; second person; short paragraphs.',
            'style' => ['Second person ("you").', 'Short paragraphs, concrete examples.', 'Define each term the first time it is used.'],
            'accuracy' => ['No invented figures or studies; mark claims to check with [verify].', 'Local prices, dates and regulations are checked by the author.'],
            'elements' => ['An opening scene in every chapter.', 'A closing box: "Your next step."'],
            'intro' => 'Introduction',
            'resources' => 'Resources',
            'part' => 'Part %d',
            'chapter' => 'Chapter %d: %s',
            'stage' => ['Getting started', 'The basics', 'Planning', 'Doing it', 'Common problems', 'Going further', 'Looking back', 'Next steps'],
            'purpose' => 'Show the reader %s.',
            'points' => ['why it matters', 'what to prepare', 'the first steps'],
            'repeat' => 'Repeated material',
            'repeat_detail' => 'Both sections cover "%s". Keep it in one place, or make the second mention build on the first.',
        ],
        'fr' => [
            'label' => '*Texte d\'exemple du rédacteur d\'exemple d\'Écrivain, pas d\'un modèle : il montre où ira votre chapitre.*',
            'open' => 'C\'est le petit matin, et le lecteur découvre la question à laquelle cette section répond : %s.',
            'point' => 'Cette partie traite de %s. Un exemple concret a sa place ici, avec les détails que vous seul pouvez fournir %s.',
            'box' => '> **Votre prochaine étape.** Notez une chose que vous ferez cette semaine à propos de %s.',
            'title' => ['Guide pratique', 'De l\'idée à la pratique', 'Le manuel essentiel'],
            'reader' => 'Des lecteurs qui découvrent le sujet et veulent des étapes claires.',
            'promise' => 'À la fin, le lecteur peut passer à l\'action avec confiance.',
            'voice' => 'Chaleureux, direct et pratique ; vouvoiement ; paragraphes courts.',
            'style' => ['Vouvoiement.', 'Paragraphes courts, exemples concrets.', 'Définir chaque terme à sa première occurrence.'],
            'accuracy' => ['Aucun chiffre ni étude inventés ; marquer les affirmations à vérifier par [à vérifier].', 'Les prix, dates et règles locales sont vérifiés par l\'auteur.'],
            'elements' => ['Une scène d\'ouverture dans chaque chapitre.', 'Un encadré final : « Votre prochaine étape. »'],
            'intro' => 'Introduction',
            'resources' => 'Ressources',
            'part' => 'Partie %d',
            'chapter' => 'Chapitre %d : %s',
            'stage' => ['Pour commencer', 'Les bases', 'Préparer', 'Passer à l\'action', 'Problèmes courants', 'Aller plus loin', 'Faire le point', 'Et après'],
            'purpose' => 'Montrer au lecteur %s.',
            'points' => ['pourquoi c\'est important', 'ce qu\'il faut préparer', 'les premières étapes'],
            'repeat' => 'Contenu répété',
            'repeat_detail' => 'Les deux sections traitent de « %s ». Gardez-le à un seul endroit, ou faites en sorte que la seconde mention s\'appuie sur la première.',
        ],
        'ht' => [
            'label' => '*Tèks egzanp redaktè egzanp Écrivain an, se pa yon modèl ki ekri l : li montre kote chapit ou a pral ye.*',
            'open' => 'Se granmaten, epi lektè a dekouvri kesyon seksyon sa a reponn : %s.',
            'point' => 'Pati sa a pale de %s. Yon egzanp pratik dwe la, ak detay se ou sèl ki ka bay %s.',
            'box' => '> **Pwochen etap ou.** Ekri yon bagay ou pral fè semèn sa a sou %s.',
            'title' => ['Gid pratik', 'Soti nan lide rive nan pratik', 'Ti liv esansyèl la'],
            'reader' => 'Lektè ki fèk kòmanse ak sijè a epi ki vle etap ki klè.',
            'promise' => 'Lè yo fini, lektè a ka aji ak konfyans.',
            'voice' => 'Cho, dirèk e pratik ; dezyèm moun ; paragraf kout.',
            'style' => ['Pale ak lektè a dirèkteman.', 'Paragraf kout, egzanp konkrè.', 'Eksplike chak mo teknik premye fwa li parèt.'],
            'accuracy' => ['Pa envante chif ni etid ; make sa pou verifye ak [pou verifye].', 'Otè a verifye pri, dat ak règ lokal yo.'],
            'elements' => ['Yon sèn pou louvri chak chapit.', 'Yon bwat pou fini : « Pwochen etap ou. »'],
            'intro' => 'Entwodiksyon',
            'resources' => 'Resous',
            'part' => 'Pati %d',
            'chapter' => 'Chapit %d : %s',
            'stage' => ['Pou kòmanse', 'Baz yo', 'Planifye', 'Pase a lakyon', 'Pwoblèm ki konn parèt', 'Ale pi lwen', 'Fè bilan', 'Apre sa'],
            'purpose' => 'Montre lektè a %s.',
            'points' => ['poukisa sa enpòtan', 'sa pou prepare', 'premye etap yo'],
            'repeat' => 'Menm bagay la repete',
            'repeat_detail' => 'De seksyon yo pale de « %s ». Kenbe l yon sèl kote, oswa fè dezyèm fwa a bati sou premye a.',
        ],
    ];

    public function name(): string
    {
        return 'fake';
    }

    public function simulated(): bool
    {
        return true;
    }

    public function model(string $quality = 'standard'): string
    {
        return self::MODEL;
    }

    public function write(WriterRequest $request): WriterResult
    {
        if (self::$failWith) {
            throw self::$failWith;
        }

        [$text, $json] = match ($request->task) {
            'plan' => $this->json($this->plan($request->data['brief'] ?? [])),
            'chapter' => [$this->chapter($request->data), null],
            'summary' => $this->json($this->summary($request->data)),
            'consistency' => $this->json($this->consistency($request->data['sections'] ?? [], $request->data)),
            default => throw new RuntimeException("The sample writer has no answer for [{$request->task}]."),
        };

        $input = (int) ceil($request->characters() / 3.5);
        $output = (int) ceil(mb_strlen($text) / 3.5);

        return new WriterResult(
            text: $text,
            model: self::MODEL,
            json: $json,
            inputTokens: $input,
            outputTokens: $output,
            // What the standard model would have cost: the ledger and the
            // budget can be exercised, though sample text is free in use.
            costUsd: AiPricing::tokens('anthropic', 'claude-sonnet-5-5', $input, $output),
            stopReason: 'end_turn',
        );
    }

    private function json(array $data): array
    {
        return [json_encode($data, JSON_UNESCAPED_UNICODE), $data];
    }

    private function t(?string $language): array
    {
        return self::TEXT[BookPrompts::language($language)];
    }

    /** @return array<string, mixed> */
    private function plan(array $brief): array
    {
        $t = $this->t($brief['language'] ?? 'en');
        $idea = trim((string) ($brief['idea'] ?? ''));
        $subject = mb_strtolower(rtrim(mb_substr($idea, 0, 80), '.')) ?: 'the subject';
        $chapters = max(2, min(12, (int) ($brief['chapters'] ?? 0) ?: 6));
        $words = max(300, (int) (((int) ($brief['words'] ?? 0) ?: 18000) / ($chapters + 1)));
        $perPart = (int) ceil($chapters / 2);

        $parts = [];
        for ($i = 0; $i < $chapters; $i++) {
            $stage = $t['stage'][$i % count($t['stage'])];
            $parts[intdiv($i, $perPart)]['title'] = sprintf($t['part'], intdiv($i, $perPart) + 1);
            $parts[intdiv($i, $perPart)]['chapters'][] = [
                'title' => $stage,
                'purpose' => sprintf($t['purpose'], mb_strtolower($stage)),
                'key_points' => array_map(fn ($p) => $p.' ('.mb_strtolower($stage).')', $t['points']),
                'words' => $words,
            ];
        }

        return [
            'title_options' => array_map(fn ($title) => $title, $t['title']),
            'subtitle' => mb_substr($idea, 0, 200),
            'reader' => trim((string) ($brief['reader'] ?? '')) ?: $t['reader'],
            'promise' => trim((string) ($brief['promise'] ?? '')) ?: $t['promise'],
            'voice' => $t['voice'],
            'style_sheet' => $t['style'],
            'accuracy_rules' => $t['accuracy'],
            'recurring_elements' => $t['elements'],
            'fiction' => (bool) ($brief['fiction'] ?? false),
            'characters' => ! empty($brief['fiction']) ? [['name' => 'Rose', 'role' => 'protagonist', 'description' => $subject]] : [],
            'setting' => '',
            'front_matter' => [['title' => $t['intro'], 'purpose' => sprintf($t['purpose'], $subject), 'key_points' => [], 'words' => (int) round($words / 2)]],
            'parts' => array_values($parts),
            'back_matter' => [['title' => $t['resources'], 'purpose' => sprintf($t['purpose'], $subject), 'key_points' => [], 'words' => (int) round($words / 3)]],
        ];
    }

    private function chapter(array $data): string
    {
        $t = $this->t($data['language'] ?? 'en');
        $item = $data['item'] ?? [];
        $title = mb_strtolower((string) ($data['title'] ?? ''));
        $words = max(60, min(600, (int) ($data['words'] ?? 300)));
        $mark = BookPrompts::mark($data['language'] ?? 'en');
        $part = (int) ($data['part'] ?? 1);
        $parts = (int) ($data['parts'] ?? 1);

        $blocks = $part === 1 ? [$t['label'], sprintf($t['open'], $title)] : [];
        $points = $item['key_points'] ?? [] ?: [$title];

        foreach ($points as $point) {
            $blocks[] = '## '.mb_strtoupper(mb_substr($point, 0, 1)).mb_substr($point, 1);
            $blocks[] = sprintf($t['point'], $point, $mark);
        }

        // Padded to roughly the length asked for, so word counts and the
        // split into parts behave as they would with a model.
        $filler = sprintf($t['point'], $title, $mark);
        while (str_word_count(implode(' ', $blocks)) < $words) {
            $blocks[] = $filler;
        }

        if ($part === $parts && ($data['kind'] ?? 'chapter') === 'chapter') {
            $blocks[] = sprintf($t['box'], $title);
        }

        return implode("\n\n", $blocks)."\n";
    }

    /** @return array<string, mixed> */
    private function summary(array $data): array
    {
        $markdown = (string) ($data['markdown'] ?? '');
        preg_match_all('/^## (.+)$/m', $markdown, $m);

        return [
            'summary' => (string) ($data['title'] ?? '').': '.implode('; ', array_unique($m[1] ?? [])),
            'names' => [],
            'timeline' => [],
            'anecdotes' => array_values(array_unique(array_map('mb_strtolower', $m[1] ?? []))),
        ];
    }

    /**
     * Flags an anecdote two sections share, the one conflict the sample
     * writer can see honestly.
     *
     * @return array<string, mixed>
     */
    private function consistency(array $sections, array $data): array
    {
        $seen = [];
        $findings = [];
        $language = 'en';

        foreach ($sections as $section) {
            $language = $section['language'] ?? $language;

            foreach ($section['summary']['anecdotes'] ?? [] as $anecdote) {
                if (isset($seen[$anecdote]) && $seen[$anecdote] !== $section['key']) {
                    $t = $this->t($language);
                    $findings[$anecdote] = [
                        'sections' => [$seen[$anecdote], $section['key']],
                        'severity' => 'suggestion',
                        'title' => $t['repeat'],
                        'detail' => sprintf($t['repeat_detail'], $anecdote),
                    ];
                }

                $seen[$anecdote] ??= $section['key'];
            }
        }

        return ['findings' => array_values($findings)];
    }
}
