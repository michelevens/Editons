<?php

use App\Http\Controllers\EditorialController;
use App\Http\Controllers\ConsistencyController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\ManuscriptSectionController;
use App\Http\Controllers\ProfileController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

/*
 | The studio's root.
 |
 | app.ecrivain.ai is the application, not a landing page — ecrivain.ai is
 | the marketing site. A signed-in author belongs on their shelf; anyone else
 | belongs at the sign-in form. Laravel's stock Welcome page served neither,
 | and shipping a framework's default screen on a product domain reads as an
 | unfinished deployment.
 */
Route::get('/', function () {
    return redirect()->route(auth()->check() ? 'books.index' : 'login');
});

Route::get('/dashboard', function () {
    return Inertia::render('Dashboard');
})->middleware(['auth', 'verified'])->name('dashboard');

Route::middleware('auth')->group(function () {
    Route::get('/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');
});

require __DIR__.'/auth.php';

/*
 * Writing studio.
 *
 * Books use implicit model binding; the policies (BookPolicy,
 * ManuscriptSectionPolicy) do the ownership checks, because binding resolves
 * an id before any authorisation runs.
 *
 * Section save/versions/restore return JSON rather than Inertia redirects:
 * they fire while someone is typing, and a page visit would throw away the
 * cursor position.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books', [BookController::class, 'index'])->name('books.index');
    Route::post('/books', [BookController::class, 'store'])->name('books.store');
    Route::get('/books/{book}/edit', [BookController::class, 'edit'])->name('books.edit');
    Route::patch('/books/{book}', [BookController::class, 'update'])->name('books.update');
    Route::delete('/books/{book}', [BookController::class, 'destroy'])->name('books.destroy');

    Route::post('/books/{book}/sections', [ManuscriptSectionController::class, 'store'])
        ->name('sections.store');
    Route::patch('/sections/{section}', [ManuscriptSectionController::class, 'update'])
        ->name('sections.update');
    Route::delete('/sections/{section}', [ManuscriptSectionController::class, 'destroy'])
        ->name('sections.destroy');

    Route::put('/sections/{section}/content', [ManuscriptSectionController::class, 'save'])
        ->name('sections.save');
    Route::get('/sections/{section}/versions', [ManuscriptSectionController::class, 'versions'])
        ->name('sections.versions');
    Route::post('/sections/{section}/versions/{version}/restore', [ManuscriptSectionController::class, 'restore'])
        ->name('sections.restore');
});

/*
 * Editorial review (Milestone 2).
 *
 * Synchronous: the fake driver answers in milliseconds, so a queue would add
 * a worker and a polling UI to buy nothing. The seam is the driver, not the
 * dispatch — a real provider moves this behind a job without touching the
 * client.
 */
Route::middleware('auth')->group(function () {
    Route::post('/sections/{section}/review', [EditorialController::class, 'review'])
        ->name('editorial.review');
    Route::get('/sections/{section}/findings', [EditorialController::class, 'findings'])
        ->name('editorial.findings');
    Route::patch('/findings/{finding}', [EditorialController::class, 'resolve'])
        ->name('editorial.resolve');
});

/*
 * Research library, citation provenance and cross-chapter consistency
 * (Milestone 2).
 *
 * Verification is its own endpoint rather than a field on store(): a source
 * must not be able to arrive pre-approved, because the approval is what
 * carries evidentiary weight.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/sources', [ResearchController::class, 'index'])
        ->name('research.index');
    Route::post('/books/{book}/sources', [ResearchController::class, 'store'])
        ->name('research.store');
    Route::patch('/sources/{source}/verification', [ResearchController::class, 'verify'])
        ->name('research.verify');
    Route::delete('/sources/{source}', [ResearchController::class, 'destroy'])
        ->name('research.destroy');

    Route::get('/sections/{section}/citations', [ResearchController::class, 'citations'])
        ->name('citations.index');
    Route::post('/sections/{section}/citations', [ResearchController::class, 'storeCitation'])
        ->name('citations.store');
    Route::delete('/citations/{citation}', [ResearchController::class, 'destroyCitation'])
        ->name('citations.destroy');

    Route::get('/books/{book}/consistency', [ConsistencyController::class, 'show'])
        ->name('consistency.show');
    Route::post('/books/{book}/terms', [ConsistencyController::class, 'storeTerm'])
        ->name('consistency.terms.store');
    Route::delete('/terms/{term}', [ConsistencyController::class, 'destroyTerm'])
        ->name('consistency.terms.destroy');
});

/*
 * Manuscript import and Markdown export (Milestone 1).
 *
 * Not the Milestone 3 export pipeline: no edition themes, pagination or
 * validation. This is the round-trip that proves content survives.
 */
Route::middleware('auth')->group(function () {
    Route::post('/books/{book}/import', [ImportController::class, 'store'])
        ->name('books.import');
    Route::get('/books/{book}/export.md', [ImportController::class, 'export'])
        ->name('books.export');
});

/*
 | Book overview and review workflow.
 |
 | Overview is a full page because it is where an author lands; the workflow
 | board answers with JSON because it lives inside the studio shell.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/overview', [OverviewController::class, 'show'])
        ->name('books.overview');
    Route::patch('/books/{book}/overview', [OverviewController::class, 'update'])
        ->name('books.overview.update');

    Route::get('/books/{book}/workflow', [WorkflowController::class, 'show'])
        ->name('workflow.show');
    Route::patch('/sections/{section}/status', [WorkflowController::class, 'move'])
        ->name('workflow.move');
    Route::patch('/sections/{section}/reviewer', [WorkflowController::class, 'assign'])
        ->name('workflow.assign');
});

/*
 | Book settings. A full page rather than a studio pane: it changes the book's
 | identity and can archive it, which is not something to do from a side panel.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/settings', [BookController::class, 'settings'])
        ->name('books.settings');
});
