<?php

use App\Http\Controllers\AccessibilityController;
use App\Http\Controllers\AccountExportDownloadController;
use App\Http\Controllers\AiEditorController;
use App\Http\Controllers\AnalyticsController;
use App\Http\Controllers\BillingController;
use App\Http\Controllers\BookController;
use App\Http\Controllers\BookImageController;
use App\Http\Controllers\BookPartsController;
use App\Http\Controllers\ChartController;
use App\Http\Controllers\ClientErrorController;
use App\Http\Controllers\CommentController;
use App\Http\Controllers\ConsistencyController;
use App\Http\Controllers\ContentReportController;
use App\Http\Controllers\CoverAiController;
use App\Http\Controllers\CoverFontController;
use App\Http\Controllers\CoverWrapController;
use App\Http\Controllers\CrossReferenceController;
use App\Http\Controllers\DesignController;
use App\Http\Controllers\EditionsController;
use App\Http\Controllers\EditorialController;
use App\Http\Controllers\GoalsController;
use App\Http\Controllers\GrowthController;
use App\Http\Controllers\HouseStyleController;
use App\Http\Controllers\StyleGuideController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\InboxController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\JoinController;
use App\Http\Controllers\JourneyController;
use App\Http\Controllers\LaunchController;
use App\Http\Controllers\ManuscriptSectionController;
use App\Http\Controllers\OverviewController;
use App\Http\Controllers\PartnerController;
use App\Http\Controllers\PartnerPageController;
use App\Http\Controllers\PlatformStatusController;
use App\Http\Controllers\PreviewController;
use App\Http\Controllers\PrintFileController;
use App\Http\Controllers\PrintKitController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\PublishFlowController;
use App\Http\Controllers\PublishingController;
use App\Http\Controllers\ReferencesController;
use App\Http\Controllers\ResearchController;
use App\Http\Controllers\RightsController;
use App\Http\Controllers\SalesController;
use App\Http\Controllers\SellerPartnersController;
use App\Http\Controllers\ShelfController;
use App\Http\Controllers\StoreController;
use App\Http\Controllers\SupportController;
use App\Http\Controllers\TeamController;
use App\Http\Controllers\TwoFactorController;
use App\Http\Controllers\WorkflowController;
use App\Http\Controllers\PreferencesController;
use App\Http\Controllers\WorkspaceController;
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
    Route::patch('/profile/theme', [ProfileController::class, 'updateTheme'])->name('profile.theme');
    // The daily writing goal, from the studio's goals sheet (JSON).
    Route::patch('/profile/daily-goal', [GoalsController::class, 'daily'])->name('profile.daily-goal');
    // The writing workspace: panels, sizes, canvas and named layouts (JSON).
    Route::get('/workspace', [WorkspaceController::class, 'show'])->name('workspace.show');
    Route::put('/workspace', [WorkspaceController::class, 'update'])->name('workspace.update');
    Route::delete('/workspace', [WorkspaceController::class, 'destroy'])->name('workspace.destroy');
    Route::delete('/workspace/books/{book}', [WorkspaceController::class, 'forgetBook'])->name('workspace.book.forget');
    Route::post('/workspace/presets', [WorkspaceController::class, 'storePreset'])->name('workspace.presets.store');
    Route::delete('/workspace/presets/{preset}', [WorkspaceController::class, 'destroyPreset'])->name('workspace.presets.destroy');
    // Keyboard shortcuts, phone tabs and review-deck swipes (JSON).
    Route::get('/preferences', [PreferencesController::class, 'show'])->name('preferences.show');
    Route::put('/preferences', [PreferencesController::class, 'update'])->name('preferences.update');
    Route::delete('/preferences/{section}', [PreferencesController::class, 'destroy'])->name('preferences.destroy');
    Route::delete('/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    // Two-factor sign-in (App\Platform\TwoFactor). Throttled: each takes a
    // password or a code, and a guess at either should be slow.
    Route::middleware('throttle:6,1')->group(function () {
        Route::post('/user/two-factor', [TwoFactorController::class, 'enable'])->name('two-factor.enable');
        Route::post('/user/two-factor/confirm', [TwoFactorController::class, 'confirm'])->name('two-factor.confirm');
        Route::post('/user/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])->name('two-factor.recovery-codes');
        Route::delete('/user/two-factor', [TwoFactorController::class, 'disable'])->name('two-factor.disable');
    });
});

require __DIR__.'/auth.php';
require __DIR__.'/emails.php';

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

    /*
     | Writing goals: the chapter's word goal, and the goals sheet's figures
     | (chapter, book target, today's words, streak) from version history.
     */
    Route::get('/books/{book}/goals', [GoalsController::class, 'show'])->name('books.goals');
    Route::patch('/sections/{section}/goal', [GoalsController::class, 'chapter'])->name('sections.goal');

    /*
     | Book anatomy: the conventional parts (Chicago ch. 1). The checklist,
     | adding a part from its template, the conventional order (previewed
     | before anything moves), and naming a section's part in the binder.
     */
    Route::get('/books/{book}/parts', [BookPartsController::class, 'show'])
        ->name('books.parts');
    Route::post('/books/{book}/parts', [BookPartsController::class, 'store'])
        ->name('books.parts.store');
    Route::get('/books/{book}/parts/order', [BookPartsController::class, 'preview'])
        ->name('books.parts.order');
    Route::post('/books/{book}/parts/order', [BookPartsController::class, 'reorder'])
        ->name('books.parts.reorder');
    Route::patch('/sections/{section}/part', [BookPartsController::class, 'assign'])
        ->name('sections.part');
    // The author's own order: drag-and-drop and move up/down in the binder.
    Route::patch('/books/{book}/sections/order', [BookPartsController::class, 'arrange'])
        ->name('books.sections.order');
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

    // Review options (ADR — Style guides and review options): a selection,
    // a chapter, a range or the whole book, estimated before it runs.
    Route::post('/books/{book}/reviews/estimate', [EditorialController::class, 'estimate'])
        ->name('editorial.estimate');
    Route::post('/books/{book}/reviews', [EditorialController::class, 'reviewBook'])
        ->name('editorial.review-book');
    // A range or the whole book runs on the worker; these follow it.
    Route::get('/books/{book}/reviews/active', [EditorialController::class, 'activeRun'])->name('editorial.active-run');
    Route::get('/editorial-runs/{run}', [EditorialController::class, 'run'])->name('editorial.run');
    Route::post('/editorial-runs/{run}/cancel', [EditorialController::class, 'cancelRun'])->name('editorial.run.cancel');

    // Style guides: the account's editorial rules, followed by its books.
    Route::get('/books/{book}/style-guide', [StyleGuideController::class, 'show'])->name('style-guides.show');
    Route::post('/books/{book}/style-guide', [StyleGuideController::class, 'store'])->name('style-guides.store');
    Route::post('/books/{book}/style-guide/follow', [StyleGuideController::class, 'follow'])->name('style-guides.follow');
    Route::post('/books/{book}/style-guide/detach', [StyleGuideController::class, 'detach'])->name('style-guides.detach');
    Route::put('/books/{book}/style-guide/own', [StyleGuideController::class, 'overrides'])->name('style-guides.overrides');
    Route::put('/style-guides/{guide}', [StyleGuideController::class, 'update'])->name('style-guides.update');
    Route::delete('/style-guides/{guide}', [StyleGuideController::class, 'destroy'])->name('style-guides.destroy');
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
    // Triage: many at once, and "never flag this again" with its undo.
    Route::post('/books/{book}/findings/bulk', [AiEditorController::class, 'bulk'])
        ->name('ai.bulk');
    Route::post('/findings/{finding}/never-flag', [AiEditorController::class, 'neverFlag'])
        ->name('ai.never-flag');
    Route::post('/books/{book}/findings/unsuppress', [AiEditorController::class, 'unsuppress'])
        ->name('ai.unsuppress');

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
 | Joining Écrivain from an invitation the console sent (ADR — author
 | invitations). Signed out by design, and open while new sign-ups are
 | closed: that is what an invite-only beta is. Registering shares the
 | sign-up page's throttle.
 */
Route::get('/join/{token}', [JoinController::class, 'show'])->name('join.show');
Route::post('/join/{token}', [JoinController::class, 'register'])
    ->middleware(['guest', 'throttle:register'])
    ->name('join.register');
Route::post('/join/{token}/accept', [JoinController::class, 'accept'])
    ->middleware(['auth', 'throttle:10,1'])
    ->name('join.accept');
Route::post('/join/{token}/decline', [JoinController::class, 'decline'])
    ->middleware('throttle:10,1')
    ->name('join.decline');

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
    Route::get('/books/{book}/design/callouts', [DesignController::class, 'callouts'])
        ->name('design.callouts');
    Route::patch('/books/{book}/layout', [DesignController::class, 'updateLayout'])
        ->name('design.layout');
    Route::post('/books/{book}/layout/reset', [DesignController::class, 'resetLayout'])
        ->name('design.layout.reset');
    // House styles: a book's design saved under a name and applied to the
    // account's other books (HouseStyles).
    Route::get('/house-styles', [HouseStyleController::class, 'index'])->name('house-styles.index');
    Route::post('/books/{book}/house-style', [HouseStyleController::class, 'store'])->name('house-styles.store');
    Route::post('/books/{book}/house-style/detach', [HouseStyleController::class, 'detach'])->name('house-styles.detach');
    Route::put('/house-styles/{style}', [HouseStyleController::class, 'update'])->name('house-styles.update');
    Route::patch('/house-styles/{style}', [HouseStyleController::class, 'rename'])->name('house-styles.rename');
    Route::delete('/house-styles/{style}', [HouseStyleController::class, 'destroy'])->name('house-styles.destroy');
    Route::post('/house-styles/{style}/apply', [HouseStyleController::class, 'apply'])->name('house-styles.apply');
    Route::patch('/books/{book}/cover', [DesignController::class, 'updateCover'])
        ->name('design.cover');
    Route::post('/books/{book}/cover/artwork', [DesignController::class, 'uploadArtwork'])
        ->name('design.cover.artwork');
    Route::get('/books/{book}/cross-references', [CrossReferenceController::class, 'index'])
        ->name('books.cross-references');
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
    // Text layers: "Customise text" turns the layout into layers, and
    // "Reset to layout" goes back (CoverLayers, ADR "Cover Studio text layers").
    Route::post('/books/{book}/cover/layers', [DesignController::class, 'customiseCover'])
        ->name('design.cover.layers');
    Route::delete('/books/{book}/cover/layers', [DesignController::class, 'resetCover'])
        ->name('design.cover.layers.destroy');
    // The back and the spine as layers, the whole print wrap as Cover
    // Studio shows it, and the pictures image layers place
    // (ADR "Editable back cover, spine and image layers").
    Route::get('/books/{book}/cover/wrap', [CoverWrapController::class, 'show'])
        ->name('design.cover.wrap');
    Route::get('/books/{book}/cover/wrap/image', [CoverWrapController::class, 'image'])
        ->middleware('throttle:120,1')
        ->name('design.cover.wrap.image');
    Route::post('/books/{book}/cover/{panel}-layers', [CoverWrapController::class, 'customise'])
        ->whereIn('panel', ['back', 'spine'])
        ->name('design.cover.panel');
    Route::delete('/books/{book}/cover/{panel}-layers', [CoverWrapController::class, 'reset'])
        ->whereIn('panel', ['back', 'spine'])
        ->name('design.cover.panel.destroy');
    Route::post('/books/{book}/cover/images', [CoverWrapController::class, 'upload'])
        ->name('design.cover.images');
    Route::get('/books/{book}/cover/images/{image}', [CoverWrapController::class, 'picture'])
        ->where('image', '[A-Za-z0-9_-]{1,40}')
        ->name('design.cover.images.show');
    Route::delete('/books/{book}/cover/images/{image}', [CoverWrapController::class, 'destroyImage'])
        ->where('image', '[A-Za-z0-9_-]{1,40}')
        ->name('design.cover.images.destroy');
    // The faces cover layers are set in, and their metrics, for the live
    // canvas (CoverFonts).
    Route::get('/cover-fonts/{face}/metrics', [CoverFontController::class, 'metrics'])
        ->where('face', '[a-z0-9.-]+')
        ->name('cover-fonts.metrics');
    Route::get('/cover-fonts/{face}', [CoverFontController::class, 'file'])
        ->where('face', '[a-z0-9.-]+')
        ->name('cover-fonts.file');

    // The full-screen preview (PreviewController): contents first, then a
    // page at a time.
    Route::get('/books/{book}/preview', [PreviewController::class, 'show'])
        ->middleware('feature:preview')
        ->name('preview.show');
    Route::get('/books/{book}/preview/page', [PreviewController::class, 'page'])
        ->middleware(['feature:preview', 'throttle:240,1'])
        ->name('preview.page');
    Route::get('/preview/fonts/{name}', [PreviewController::class, 'font'])
        ->where('name', '[A-Za-z0-9-]+\.ttf')
        ->name('preview.font');
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
    Route::post('/books/{book}/channels/{channel}/submitted', [PublishingController::class, 'markSubmitted'])
        ->name('publishing.submitted');
    Route::post('/books/{book}/exports', [PublishingController::class, 'generate'])
        ->name('publishing.generate');
    Route::get('/books/{book}/exports/{export}/download', [PublishingController::class, 'download'])
        ->name('publishing.download');
    // Export profiles (App\Publishing\ExportOptions) and the book's own
    // front and back matter per format.
    Route::post('/books/{book}/export-profiles', [PublishingController::class, 'storeProfile'])
        ->name('publishing.profiles.store');
    Route::put('/books/{book}/export-profiles/{profile}', [PublishingController::class, 'updateProfile'])
        ->name('publishing.profiles.update');
    Route::delete('/books/{book}/export-profiles/{profile}', [PublishingController::class, 'destroyProfile'])
        ->name('publishing.profiles.destroy');
    Route::put('/books/{book}/export-matter', [PublishingController::class, 'matter'])
        ->name('publishing.matter');

    // Publish in three steps: Check, Choose channels, Publish
    // (App\Http\Controllers\PublishFlowController for who may do what).
    Route::get('/books/{book}/publish-flow', [PublishFlowController::class, 'show'])->name('publish.show');
    Route::post('/books/{book}/publish-flow/regenerate', [PublishFlowController::class, 'regenerate'])
        ->middleware('throttle:20,1')->name('publish.regenerate');
    Route::post('/books/{book}/publish-flow/channels', [PublishFlowController::class, 'channel'])->name('publish.channel');
    Route::patch('/books/{book}/publish-flow/kdp', [PublishFlowController::class, 'kdp'])->name('publish.kdp');
    Route::post('/books/{book}/publish-flow/store', [PublishFlowController::class, 'store'])->name('publish.store');
    Route::post('/books/{book}/publish-flow/lulu', [PublishFlowController::class, 'lulu'])
        ->middleware('throttle:10,1')->name('publish.lulu');
    Route::post('/books/{book}/publish-flow/lulu/refresh', [PublishFlowController::class, 'luluRefresh'])
        ->middleware('throttle:30,1')->name('publish.lulu.refresh');
    Route::post('/books/{book}/publish-flow/lulu/offer', [PublishFlowController::class, 'luluOffer'])->name('publish.lulu.offer');
    Route::post('/books/{book}/publish-flow/submitted', [PublishFlowController::class, 'submitted'])->name('publish.submitted');
    Route::get('/books/{book}/publish-flow/google-play', [PublishFlowController::class, 'googlePlay'])
        ->middleware('throttle:10,1')->name('publish.google-play');
    // Several books at once, from the shelf.
    Route::post('/publish/prepare', [PublishFlowController::class, 'prepareMany'])
        ->middleware('throttle:10,1')->name('publish.prepare');
    Route::get('/publish/google-play', [PublishFlowController::class, 'googlePlayMany'])
        ->middleware('throttle:10,1')->name('publish.google-play.many');
    Route::get('/publish/kdp-checklist', [PublishFlowController::class, 'checklist'])->name('publish.checklist');

    // Shelf views and bulk actions (ADR-0045): the author's shelf and the
    // console's House catalog, each book permission-checked on its own.
    Route::put('/shelf/{view}/current', [ShelfController::class, 'remember'])->whereIn('view', ['books', 'house'])->name('shelf.current');
    Route::post('/shelf/{view}/views', [ShelfController::class, 'store'])->whereIn('view', ['books', 'house'])->name('shelf.views.store');
    Route::patch('/shelf/views/{id}', [ShelfController::class, 'update'])->whereNumber('id')->name('shelf.views.update');
    Route::delete('/shelf/views/{id}', [ShelfController::class, 'destroy'])->whereNumber('id')->name('shelf.views.destroy');
    Route::post('/shelf/bulk', [ShelfController::class, 'bulk'])->middleware('throttle:20,1')->name('shelf.bulk');
    Route::get('/shelf/metadata.csv', [ShelfController::class, 'csv'])->middleware('throttle:20,1')->name('shelf.csv');
    Route::get('/shelf/progress', [ShelfController::class, 'progress'])->middleware('throttle:120,1')->name('shelf.progress');

    // The print kit: print settings, checks, prices and the cover preview.
    Route::get('/books/{book}/print-kit', [PrintKitController::class, 'show'])
        ->name('print-kit.show');
    Route::patch('/books/{book}/print-kit', [PrintKitController::class, 'update'])
        ->name('print-kit.update');
    Route::get('/books/{book}/print-kit/prices', [PrintKitController::class, 'prices'])
        ->name('print-kit.prices');
    Route::get('/books/{book}/print-kit/cover-preview', [PrintKitController::class, 'preview'])
        ->middleware('throttle:60,1')
        ->name('print-kit.preview');
    Route::post('/books/{book}/print-kit/back', [PrintKitController::class, 'uploadBack'])
        ->name('print-kit.back');
    Route::delete('/books/{book}/print-kit/back', [PrintKitController::class, 'removeBack'])
        ->name('print-kit.back.destroy');
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
    Route::patch('/books/{book}/rights/isbn-plan', [RightsController::class, 'updateIsbnPlan'])
        ->name('rights.isbn.plan');
    Route::put('/books/{book}/rights/declaration', [RightsController::class, 'declareRights'])
        ->name('rights.declaration');
    Route::put('/books/{book}/rights/ai-disclosure', [RightsController::class, 'discloseAi'])
        ->name('rights.ai');
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
 | The inbox. Account-level: every row is the signed-in person's own
 | (InboxItem::scopeFor), about books they can still open.
 */
Route::middleware(['auth', 'feature:inbox'])->group(function () {
    Route::get('/inbox', [InboxController::class, 'index'])
        ->name('inbox.index');
    Route::post('/inbox/read-all', [InboxController::class, 'readAll'])
        ->name('inbox.read-all');
    Route::patch('/inbox/{item}', [InboxController::class, 'update'])
        ->whereNumber('item')
        ->name('inbox.update');
    Route::get('/inbox/{item}/open', [InboxController::class, 'open'])
        ->whereNumber('item')
        ->name('inbox.open');
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
 | Sales & Distribution, and Royalties. Store channels report nothing yet,
 | and the screen says so; direct sales from the author's own storefront are
 | real orders (App\Commerce\SalesReport).
 */
Route::middleware('auth')->group(function () {
    Route::get('/books/{book}/sales', [SalesController::class, 'show'])
        ->name('sales.show');
    Route::get('/sales', [SalesController::class, 'overview'])
        ->name('sales.overview');
    // The report's CSVs and royalty statements (ADR-0046 — Sales reports
    // and royalty statements): the same scope as the report, rate-limited.
    Route::get('/sales/export/{kind}.csv', [SalesController::class, 'export'])
        ->whereIn('kind', ['lines', 'summary'])
        ->middleware('throttle:sales-exports')
        ->name('sales.export');
    Route::get('/sales/statements/{period}.pdf', [SalesController::class, 'statement'])
        ->where('period', '\d{4}-(0[1-9]|1[0-2]|Q[1-4])')
        ->middleware('throttle:sales-exports')
        ->name('sales.statement');
    Route::put('/sales/timezone', [SalesController::class, 'timezone'])
        ->name('sales.timezone');

    // The book's product in the storefront. Owner only (BookPolicy::manage).
    Route::get('/books/{book}/product', [ProductController::class, 'show'])
        ->name('products.show');
    Route::put('/books/{book}/product', [ProductController::class, 'update'])
        ->name('products.update');

    // What this account has bought, with fresh download links.
    Route::get('/purchases', [StoreController::class, 'purchases'])
        ->name('purchases.index');
});

/*
 | The public storefront. No sign-in to look or to buy; every route 404s
 | while direct sales are off (App\Commerce\Commerce). Paths under /store,
 | so nothing here can shadow a studio route.
 |
 | Payment is confirmed only by Stripe's signed webhook (POST /stripe/webhook,
 | Cashier's route, StripeWebhookController), never by these pages.
 */
Route::prefix('store')->name('store.')->group(function () {
    Route::get('/checkout/success', [StoreController::class, 'success'])
        ->name('checkout.success');
    // The fake gateway's payment page: local development and tests only.
    Route::get('/checkout/test/{session}', [StoreController::class, 'fakeCheckout'])
        ->name('checkout.fake');
    Route::post('/checkout/test/{session}', [StoreController::class, 'fakePay'])
        ->middleware('throttle:20,1')
        ->name('checkout.fake.pay');

    // Signed over the path, expiring, and re-checked against the license.
    Route::get('/downloads/{key}/{format}', [StoreController::class, 'download'])
        ->middleware(['signed:relative', 'throttle:30,1'])
        ->name('download');

    Route::get('/authors/{handle}', [StoreController::class, 'author'])
        ->name('author');
    // "Report this book / page": no account, rate-limited per IP.
    Route::post('/authors/{handle}/report', [ContentReportController::class, 'author'])
        ->middleware('throttle:content-reports')
        ->name('author.report');

    Route::get('/{product:slug}', [StoreController::class, 'product'])
        ->name('product');
    Route::get('/{product:slug}/cover', [StoreController::class, 'cover'])
        ->name('cover');
    // "Read a sample": a Sample-profile export, by a link the product page
    // signs (expiring), rate-limited per visitor.
    Route::get('/{product:slug}/sample/{format}', [StoreController::class, 'sample'])
        ->middleware(['signed:relative', 'throttle:20,1'])
        ->whereIn('format', ['epub', 'pdf'])
        ->name('sample');
    Route::post('/{product:slug}/checkout', [StoreController::class, 'checkout'])
        ->middleware('throttle:10,1')
        ->name('checkout');
    Route::post('/{product:slug}/report', [ContentReportController::class, 'product'])
        ->middleware('throttle:content-reports')
        ->name('report');
    Route::get('/{product:slug}/cancelled', [StoreController::class, 'cancelled'])
        ->name('checkout.cancelled');
});

/*
 | Écrivain Partners (ADR — partner program; docs/PARTNERS.md). The whole
 | module answers 404 while the `partners` flag is off.
 |
 | The partner's own pages are signed in and only ever the account's own
 | partner record. The public page, /p/{handle}, needs no account; its book
 | links and the ?partner= parameter give the partner the credit for a
 | later purchase. An author's invitation opens signed out, so somebody
 | without an account can read it first.
 */
Route::middleware(['auth', 'feature:partners'])->group(function () {
    Route::get('/partners/apply', [PartnerController::class, 'apply'])->name('partners.apply');
    Route::post('/partners/apply', [PartnerController::class, 'storeApplication'])
        ->middleware('throttle:10,1')
        ->name('partners.apply.store');

    Route::get('/partner', [PartnerController::class, 'dashboard'])->name('partners.dashboard');
    // The partner's own commissions in a date range, as CSV (ADR-0046).
    Route::get('/partner/commissions.csv', [PartnerController::class, 'exportCommissions'])
        ->middleware('throttle:sales-exports')
        ->name('partners.commissions.export');
    Route::put('/partner/page', [PartnerController::class, 'updatePage'])->name('partners.page.update');
    Route::get('/partner/handle', [PartnerController::class, 'checkHandle'])
        ->middleware('throttle:60,1')
        ->name('partners.handle.check');
    Route::post('/partner/logo', [PartnerController::class, 'uploadLogo'])
        ->middleware('throttle:20,1')
        ->name('partners.logo');
    Route::delete('/partner/logo', [PartnerController::class, 'removeLogo'])->name('partners.logo.destroy');
    Route::put('/partner/payout-details', [PartnerController::class, 'updatePayoutDetails'])->name('partners.payout-details');

    Route::post('/partners/invitations/{token}', [PartnerController::class, 'acceptInvitation'])
        ->middleware('throttle:10,1')
        ->name('partners.invitation.accept');

    // The seller's side: who sells their books, block, invite.
    Route::post('/sales/partners/{partner}/block', [SellerPartnersController::class, 'block'])->whereNumber('partner')->name('sales.partners.block');
    Route::delete('/sales/partners/{partner}/block', [SellerPartnersController::class, 'unblock'])->whereNumber('partner')->name('sales.partners.unblock');
    Route::post('/sales/partners/invite', [SellerPartnersController::class, 'invite'])
        ->middleware('throttle:10,1')
        ->name('sales.partners.invite');
});

Route::get('/partners/invitations/{token}', [PartnerController::class, 'invitation'])
    ->middleware(['feature:partners', 'throttle:30,1'])
    ->name('partners.invitation');

Route::prefix('p')->name('partners.page')->group(function () {
    Route::get('/{handle}', [PartnerPageController::class, 'show'])->name('');
    Route::get('/{handle}/qr.{format}', [PartnerPageController::class, 'qr'])
        ->where('format', 'svg|png')
        ->middleware('throttle:30,1')
        ->name('.qr');
    Route::get('/{handle}/logo/{version}', [PartnerPageController::class, 'logo'])
        ->where('version', '[a-f0-9]{8}')
        ->name('.logo');
    Route::post('/{handle}/report', [PartnerPageController::class, 'report'])
        ->middleware('throttle:content-reports')
        ->name('.report');
    Route::get('/{handle}/{slug}', [PartnerPageController::class, 'book'])->name('.book');
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

/*
 | The platform owner's console, "View as" and the public status endpoint.
 */
require __DIR__.'/admin.php';

/*
 | Help: an author's requests to Écrivain support (SupportController), and
 | errors the browser caught, for the console's error groups. Reports are
 | accepted signed in or not — a broken sign-in page is an error too — and
 | are limited per person and per IP.
 */
Route::middleware(['auth', 'feature:support'])->group(function () {
    Route::get('/support', [SupportController::class, 'index'])->name('support.index');
    Route::post('/support', [SupportController::class, 'store'])
        ->middleware('throttle:support')
        ->name('support.store');
    Route::get('/support/{ticket}', [SupportController::class, 'show'])
        ->whereNumber('ticket')
        ->name('support.show');
    Route::post('/support/{ticket}/messages', [SupportController::class, 'reply'])
        ->whereNumber('ticket')
        ->middleware('throttle:support')
        ->name('support.reply');
});

Route::post('/client-errors', [ClientErrorController::class, 'store'])
    ->middleware('throttle:client-errors')
    ->name('client-errors.store');

Route::get('/status', PlatformStatusController::class)
    ->middleware('throttle:60,1')
    ->name('platform.status');
Route::get('/status.json', PlatformStatusController::class)
    ->middleware('throttle:60,1')
    ->name('platform.status.json');

/*
 | An account's data export, from the link emailed to it (signed, expiring;
 | App\Platform\Trust\AccountExporter). No sign-in: the account may be
 | suspended while it is offboarded.
 */
/*
 | A print interior or cover wrap for Lulu to fetch (signed, expiring;
 | App\Commerce\Print\LuluPrinting::link). No sign-in: Lulu has none.
 */
Route::get('/print-files/{export}/{name}', PrintFileController::class)
    ->whereNumber('export')
    ->where('name', '(interior|cover)\.pdf')
    ->middleware(['signed', 'throttle:60,1'])
    ->name('print-files.show');

Route::get('/account-exports/{export}/download', AccountExportDownloadController::class)
    ->whereNumber('export')
    ->middleware(['signed', 'throttle:10,1'])
    ->name('account-exports.download');
