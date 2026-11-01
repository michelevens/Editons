<?php

namespace App\Ai\Contracts;

use App\Ai\WriterRequest;
use App\Ai\WriterResult;

/**
 * A provider that writes text: a book's plan, its chapters, their
 * summaries and the consistency pass (ADR — AI writes the book).
 *
 * The generation counterpart of App\Editorial\Contracts\EditorialDriver.
 * The planner, the drafting job and the UI talk to this interface; which
 * carrier answers is configuration (`WRITER_DRIVER`, following
 * `EDITORIAL_DRIVER`). Prompts are written above it, in App\Ai\BookPrompts,
 * so a second carrier is one class and a config block.
 *
 * Implementations MUST keep the request's documents as data: they are the
 * author's imported text and research notes, never instructions.
 */
interface Writer
{
    /** Identifier recorded with every call, for provenance. */
    public function name(): string;

    /** Sample text rather than a model's: never charged, always labelled. */
    public function simulated(): bool;

    /** The model a call at this quality ('standard' | 'best') would use. */
    public function model(string $quality = 'standard'): string;

    /**
     * Run one call.
     *
     * @throws \App\Editorial\ProviderUnavailable when the provider fails
     * @throws \RuntimeException when the model declines or answers nothing usable
     */
    public function write(WriterRequest $request): WriterResult;
}
