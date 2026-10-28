<?php

namespace App\Http\Controllers;

use App\Covers\CoverFonts;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Cover Studio's typefaces, for the browser.
 *
 * The same TTF files the server draws covers with, so the live canvas is
 * set in the same faces as the JPEG, and the metrics both ends wrap text
 * by. Only faces in CoverFonts are served; the URLs carry a version, so
 * they cache for a year.
 */
class CoverFontController extends Controller
{
    public function file(string $face): BinaryFileResponse
    {
        $file = $this->resolve($face);

        return response()->file($file, [
            'Content-Type' => 'font/ttf',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    public function metrics(string $face): JsonResponse
    {
        $this->resolve($face);

        return response()->json(CoverFonts::metrics($face), 200, [
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    private function resolve(string $face): string
    {
        $file = in_array($face, CoverFonts::faces(), true) ? CoverFonts::file($face) : null;

        abort_unless($file !== null && is_file($file), 404);

        return $file;
    }
}
