<?php

namespace App\Editorial\Contracts;

/**
 * A driver whose output is sample output, not a model's review.
 *
 * FakeDriver is heuristics: hedge-word lists, a sentence-length limit, a
 * passive-voice pattern. Useful, deterministic, and free to run, so a
 * review from it is never charged for and the studio labels its findings
 * as sample output (ADR-0009 made it the development default; production
 * ran on it with nothing saying so, and charged credits).
 */
interface SimulatesReview {}
