<?php

use App\Http\Controllers\AccessibilityController;
use App\Http\Controllers\AiEditorController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookImageController;
use App\Http\Controllers\ChartController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ConsistencyController;
use App\Http\Controllers\CoverAiController;
use App\Http\Controllers\DesignController;
use App\Http\Controllers\EditionsController;
use App\Http\Controllers\EditorialController;
use App\Http\Controllers\GrowthController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\JourneyController;
use App\Http\Controllers\LaunchController;
use App\Http\Controllers\ManuscriptSectionController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublishingController;
use App\Http\Controllers\ReferencesController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\RightsController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\WorkflowController;
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

/*
 | Where somebody lands after signing in, registering or verifying an email.
 |
 | Every auth controller Breeze ships redirects here — six of them — and this
 | route rendered Breeze's stock "You're logged in!" page. So the first thing
 | a new author saw after registering was an unstyled placeholder, not the
 | product. Correcting it here fixes all six paths at once, and keeps the
 | route name those controllers already reference.
 |
 | Somebody with no books goes to Quick Start, which asks one question and
 | opens the editor. Somebody with books goes to their shelf. Neither is a
 | dashboard, because this product does not have one and inventing a screen
 | of empty figures for a new account would be a worse welcome than either.
 */
Route::get('/dashboard', function () {
    return redirect()->route(
        auth()->user()->books()->where('status', '!=', 'archived')->exists()
            ? 'books.index'
            : 'books.quick',
    );
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
    /*
     | The same save, over POST, for navigator.sendBeacon.
     |
     | A beacon can only issue POST, and it is what carries the last edit when
     | a writer closes the tab. Against the PUT-only route it got a 405 and
     | the edit was lost — silently, because a beacon has no response to
     | check. This is the one path where losing a save is unrecoverable, so it
     | gets its own verb rather than relying on method spoofing the beacon
     | cannot send either.
     */
    Route::post('/sections/{section}/content', [ManuscriptSectionController::class, 'save'])
        ->name('sections.save.beacon');
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
    // The whole book at once: the structural review no per-section agent
    // can produce. Free, so no entitlement gate — see EditorialController.
    Route::get('/books/{book}/direct', [EditorialController::class, 'direct'])
        ->name('books.direct');

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

    /*
     | The native format: the only export that round-trips.
     |
     | EPUB and DOCX are reading formats, Markdown flattens the binder tree,
     | HTML is one document. None of them come back as the same book. See
     | App\Publishing\PortableBook.
     */
    Route::get('/books/{book}/export.ecrivain', [ImportController::class, 'portableExport'])
        ->name('books.portable.export');
    Route::post('/books/import', [ImportController::class, 'portableImport'])
        ->name('books.portable.import');
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

/*
 | Book-wide editorial and reference screens.
 |
 | These answer questions the studio's per-chapter panels cannot: what is left
 | across the whole book, and which sources are doing any work.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/findings', [AiEditorController::class, 'index'])
        ->name('ai.index');
    Route::patch('/findings/{finding}/reopen', [AiEditorController::class, 'reopen'])
        ->name('ai.reopen');

    Route::get('/books/{book}/references', [ReferencesController::class, 'show'])
        ->name('references.show');
    Route::put('/books/{book}/references/style', [ReferencesController::class, 'style'])
        ->name('references.style');
});

/*
 | Analytics, computed from version history rather than generated.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/analytics', [AnalyticsController::class, 'show'])
        ->name('analytics.show');
});

/*
 | Collaborators. Invitations are by email address, because the person being
 | invited usually has no account yet.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/members', [TeamController::class, 'index'])
        ->name('team.index');
    Route::post('/books/{book}/members', [TeamController::class, 'invite'])
        ->name('team.invite');
    Route::patch('/members/{member}', [TeamController::class, 'update'])
        ->name('team.update');
    Route::post('/members/{member}/resend', [TeamController::class, 'resend'])
        ->name('team.resend');
    Route::delete('/members/{member}', [TeamController::class, 'destroy'])
        ->name('team.destroy');
});

/*
 | Accepting an invitation. Outside the auth group: the whole point is that
 | the recipient may not have an account yet, so this has to be reachable
 | signed out and then send them to register.
 */
Route::get('/invitations/{token}', [InvitationController::class, 'show'])
    ->name('invitations.show');
Route::post('/invitations/{token}', [InvitationController::class, 'accept'])
    ->middleware('auth')
    ->name('invitations.accept');

/*
 | Comments. Gated on view rather than update: a reviewer who cannot edit the
 | book is exactly the person invited to comment on it.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/comments', [CommentController::class, 'index'])
        ->name('comments.index');
    Route::post('/sections/{section}/comments', [CommentController::class, 'store'])
        ->name('comments.store');
    Route::patch('/comments/{comment}', [CommentController::class, 'resolve'])
        ->name('comments.resolve');
    Route::delete('/comments/{comment}', [CommentController::class, 'destroy'])
        ->name('comments.destroy');
});

/*
 | Design & Layout, and Cover Studio.
 |
 | Reading is gated on view so a reviewer can see how the book will set;
 | changing it needs update, because layout is the author's decision.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/design', [DesignController::class, 'show'])
        ->name('design.show');
    Route::patch('/books/{book}/layout', [DesignController::class, 'updateLayout'])
        ->name('design.layout');
    Route::post('/books/{book}/layout/reset', [DesignController::class, 'resetLayout'])
        ->name('design.layout.reset');
    Route::patch('/books/{book}/cover', [DesignController::class, 'updateCover'])
        ->name('design.cover');
    Route::post('/books/{book}/cover/artwork', [DesignController::class, 'uploadArtwork'])
        ->name('design.cover.artwork');
    Route::post('/charts/preview', [ChartController::class, 'preview'])
        ->middleware('throttle:120,1')
        ->name('charts.preview');
    Route::post('/books/{book}/images', [BookImageController::class, 'store'])
        ->name('books.images.store');
    Route::get('/books/{book}/images/{name}', [BookImageController::class, 'show'])
        ->where('name', '[a-z0-9]+\.[a-z]+')
        ->name('books.images.show');
    Route::get('/books/{book}/cover/image', [DesignController::class, 'coverImage'])
        ->name('design.cover.image');
    Route::get('/books/{book}/cover/download', [DesignController::class, 'downloadCover'])
        ->name('design.cover.download');
    Route::post('/books/{book}/cover/concepts', [CoverAiController::class, 'propose'])
        ->name('design.cover.concepts');
    Route::post('/books/{book}/cover/concepts/{index}/apply', [CoverAiController::class, 'apply'])
        ->whereNumber('index')
        ->name('design.cover.concepts.apply');
    Route::post('/books/{book}/cover/artwork/generate', [CoverAiController::class, 'paint'])
        ->name('design.cover.paint');
    Route::delete('/books/{book}/cover/artwork', [DesignController::class, 'removeArtwork'])
        ->name('design.cover.artwork.destroy');
});

/*
 | Export & Publish. Every write needs update: submitting a book to a store
 | under the author's name is not a reviewer's call. Downloads need only view,
 | since a reviewer reading a proof is the point of inviting them.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/publishing', [PublishingController::class, 'show'])
        ->name('publishing.show');
    Route::patch('/books/{book}/publishing', [PublishingController::class, 'update'])
        ->name('publishing.update');
    Route::post('/books/{book}/channels', [PublishingController::class, 'toggleChannel'])
        ->name('publishing.channels');
    Route::post('/books/{book}/submit', [PublishingController::class, 'submit'])
        ->name('publishing.submit');
    Route::post('/books/{book}/channels/{channel}/unpublish', [PublishingController::class, 'unpublish'])
        ->name('publishing.unpublish');
    Route::post('/books/{book}/exports', [PublishingController::class, 'generate'])
        ->name('publishing.generate');
    Route::get('/books/{book}/exports/{export}/download', [PublishingController::class, 'download'])
        ->name('publishing.download');
});

/*
 | ISBN & Rights. The pool belongs to the account, so adding to it is not
 | scoped to a book.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/rights', [RightsController::class, 'show'])
        ->name('rights.show');
    Route::post('/books/{book}/isbns', [RightsController::class, 'assign'])
        ->name('rights.isbn.assign');
    Route::delete('/books/{book}/isbns/{isbn}', [RightsController::class, 'unassign'])
        ->name('rights.isbn.unassign');
    Route::post('/isbns', [RightsController::class, 'addToPool'])
        ->name('rights.isbn.pool');
    Route::patch('/books/{book}/rights', [RightsController::class, 'updateRights'])
        ->name('rights.update');
    Route::post('/books/{book}/copyright-page', [RightsController::class, 'insertCopyrightPage'])
        ->name('rights.copyright');
});

/*
 | Editions. Creating one makes a new book, which is an owner's decision, so
 | the controller gates on manage rather than update.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/editions', [EditionsController::class, 'index'])
        ->name('editions.index');
    Route::post('/books/{book}/editions', [EditionsController::class, 'store'])
        ->name('editions.store');
});

/*
 | Plans & Billing. Account-level, so nothing here is scoped to a book.
 */
Route::middleware('auth')->group(function () {
    Route::get('/billing', [BillingController::class, 'show'])
        ->name('billing.show');
    Route::get('/billing/summary', [BillingController::class, 'summary'])
        ->name('billing.summary');
});

/*
 | Accessibility. Reading is gated on view so a reviewer can see what is
 | outstanding; writing alt text is an edit to the manuscript.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/accessibility', [AccessibilityController::class, 'show'])
        ->name('accessibility.show');
    Route::patch('/sections/{section}/image-description', [AccessibilityController::class, 'describeImage'])
        ->name('accessibility.image');
});

/*
 | Sales & Distribution, and Royalties. Read-only: nothing reports sales yet,
 | and the screen says so rather than showing invented figures.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/sales', [SalesController::class, 'show'])
        ->name('sales.show');
});

/*
 | Launch Planner.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/launch', [LaunchController::class, 'show'])
        ->name('launch.show');
    Route::patch('/books/{book}/launch', [LaunchController::class, 'update'])
        ->name('launch.update');
    Route::post('/books/{book}/readers', [LaunchController::class, 'addReader'])
        ->name('launch.readers.store');
    Route::patch('/books/{book}/readers/{reader}', [LaunchController::class, 'updateReader'])
        ->name('launch.readers.update');
    Route::delete('/books/{book}/readers/{reader}', [LaunchController::class, 'removeReader'])
        ->name('launch.readers.destroy');
});

/*
 | Notes, the marketing kit, and the screens that are not built.
 |
 | The locked route is deliberately a real endpoint rather than a hard-coded
 | client string: what a screen would do and which plan it belongs to is
 | product information, and it should come from the same place as the plans.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/notes', [GrowthController::class, 'notes'])
        ->name('notes.show');
    Route::put('/books/{book}/notes', [GrowthController::class, 'saveNotes'])
        ->name('notes.save');

    Route::get('/books/{book}/marketing-kit', [GrowthController::class, 'kit'])
        ->name('kit.show');
    Route::post('/books/{book}/marketing-kit', [GrowthController::class, 'generateKit'])
        ->name('kit.generate');

    Route::get('/books/{book}/coming-soon/{screen}', [GrowthController::class, 'locked'])
        ->name('growth.locked');
});

/*
 | The writing journey: where the author is, and what to do next.
 |
 | Stages are a path rather than a wizard — any of them can be opened at any
 | point, because a product that refuses to let somebody revisit Discover in
 | chapter nine has misunderstood how writing works.
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/journey', [JourneyController::class, 'show'])
        ->name('journey.show');

    // The Guided Journey interface. A route rather than a mode, for the same
    // reason Quick Start is one: which door suits somebody is theirs to pick.
    Route::get('/books/{book}/guided', [JourneyController::class, 'guided'])
        ->name('journey.guided');
    Route::patch('/books/{book}/idea', [JourneyController::class, 'saveIdea'])
        ->name('journey.idea');
    Route::patch('/books/{book}/stage', [JourneyController::class, 'setStage'])
        ->name('journey.stage');
    Route::patch('/books/{book}/assistance', [JourneyController::class, 'setAssistance'])
        ->name('journey.assistance');
    Route::post('/books/{book}/outline', [JourneyController::class, 'outline'])
        ->name('journey.outline');
    Route::post('/books/{book}/outline/import', [JourneyController::class, 'importOutline'])
        ->name('journey.outline.import');
});

/*
 | Quick Start: one screen, one question, straight into writing.
 |
 | A route rather than a mode, because which door somebody prefers is theirs
 | to choose and should not need switching into. Same books, same sections,
 | same version history as the studio.
 */
Route::get('/start', [BookController::class, 'quickStart'])
    ->middleware('auth')
    ->name('books.quick');
