<?php

namespace App\Covers;

use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Paints the picture behind a cover's type.
 *
 * The prompt asks for an image with no lettering at all: the title and
 * author are set afterwards by the cover renderer in real type, because
 * image models misspell words and a misspelled title on a cover is the one
 * mistake an author cannot ship.
 */
class CoverArtist
{
    private const SUFFIX = ' Portrait book cover artwork. Absolutely no text, letters, words, numbers, '
        .'logos, signatures or watermarks anywhere in the image. Keep the top third and the bottom '
        .'fifth visually calm, because the title and the author will be set over them.';

    /**
     * Whether painting is real here. The fake painter exists for tests and
     * development; in production it would charge for an abstract blur.
     */
    public static function available(): bool
    {
        return config('covers.artwork.driver') === 'openai'
            ? filled(config('covers.artwork.apiKey'))
            : ! app()->environment('production');
    }

    /**
     * @return array{bytes: string, mime: string, model: string, prompt: string}
     */
    public function paint(string $brief): array
    {
        $brief = trim($brief);

        if ($brief === '') {
            throw new RuntimeException('Describe the picture first.');
        }

        $prompt = mb_substr($brief, 0, 1200).self::SUFFIX;

        return match (config('covers.artwork.driver')) {
            'openai' => $this->openai($prompt),
            default => $this->fake($prompt),
        };
    }

    /** @return array{bytes: string, mime: string, model: string, prompt: string} */
    private function openai(string $prompt): array
    {
        $key = config('covers.artwork.apiKey');

        if (! $key) {
            throw new RuntimeException('Cover artwork needs an OpenAI API key, which is not configured.');
        }

        $model = (string) config('covers.artwork.model');

        $response = Http::withToken($key)
            ->timeout((int) config('covers.artwork.timeout', 120))
            ->post('https://api.openai.com/v1/images/generations', [
                'model' => $model,
                'prompt' => $prompt,
                'size' => config('covers.artwork.size'),
                'quality' => config('covers.artwork.quality'),
                'output_format' => 'jpeg',
                'n' => 1,
            ]);

        if ($response->failed()) {
            // The provider's own words where it gave them: a content-policy
            // refusal tells the author what to change; a status code does not.
            $reason = $response->json('error.message') ?? "status {$response->status()}";

            throw new RuntimeException("The image could not be made: {$reason}");
        }

        $bytes = base64_decode((string) $response->json('data.0.b64_json'), true);

        if (! $bytes || ! @imagecreatefromstring($bytes)) {
            throw new RuntimeException('The image service returned no picture.');
        }

        return ['bytes' => $bytes, 'mime' => 'image/jpeg', 'model' => $model, 'prompt' => $prompt];
    }

    /**
     * An abstract picture drawn locally, seeded by the prompt.
     *
     * @return array{bytes: string, mime: string, model: string, prompt: string}
     */
    private function fake(string $prompt): array
    {
        mt_srand(crc32($prompt));

        $image = imagecreatetruecolor(1024, 1536);

        for ($y = 0; $y < 1536; $y++) {
            $t = $y / 1536;
            imageline($image, 0, $y, 1024, $y, imagecolorallocate(
                $image,
                (int) (60 + 120 * $t),
                (int) (90 + 60 * (1 - $t)),
                (int) (150 - 70 * $t),
            ));
        }

        for ($i = 0; $i < 18; $i++) {
            imagefilledellipse(
                $image,
                mt_rand(0, 1024), mt_rand(400, 1300),
                mt_rand(120, 520), mt_rand(120, 520),
                imagecolorallocatealpha($image, mt_rand(80, 255), mt_rand(80, 220), mt_rand(60, 200), 90),
            );
        }

        ob_start();
        imagejpeg($image, null, 88);

        return ['bytes' => (string) ob_get_clean(), 'mime' => 'image/jpeg', 'model' => 'fake', 'prompt' => $prompt];
    }
}
