<?php

use App\Editorial\Contracts\EditorialDriver;
use App\Editorial\Drivers\FakeDriver;
use App\Editorial\EditorialAgent;
use App\Editorial\ReviewResult;
use App\Models\Book;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind a different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

/**
 * Bind an editorial driver that is not sample output.
 *
 * The suite runs on FakeDriver, whose reviews are never charged. Tests about
 * charging for a review need a driver that stands in for a real provider:
 * this one gives FakeDriver's findings under another name, without the
 * SimulatesReview marker.
 */
function useChargingDriver(): void
{
    app()->bind(EditorialDriver::class, fn () => new class implements EditorialDriver
    {
        public function review(EditorialAgent $agent, string $text): ReviewResult
        {
            return (new FakeDriver)->review($agent, $text);
        }

        public function name(): string
        {
            return 'test-provider';
        }
    });
}

/**
 * A book whose author has confirmed the rights declaration and answered the
 * AI-content questions, as Prepare for stores requires: for tests about
 * something else that need a book that can be submitted.
 */
function declared(Book $book): Book
{
    $book->forceFill([
        'rights_declaration' => ['holds_rights' => true, 'materials' => [], 'confirmed_by' => $book->user_id, 'confirmed_name' => 'Test', 'confirmed_at' => now()->toIso8601String()],
        'ai_disclosure' => ['answers' => ['text' => 'none', 'images' => 'none', 'translations' => 'none'], 'confirmed_by' => $book->user_id, 'confirmed_name' => 'Test', 'confirmed_at' => now()->toIso8601String()],
    ])->save();

    return $book->fresh();
}

/**
 * A platform staff account for the admin console's tests, with two-factor
 * confirmed unless asked otherwise. Never created by a seeder (docs/ADMIN.md);
 * only here and by `php artisan platform:admin`.
 */
function platformAdmin(string $role = 'owner', bool $twoFactor = true): App\Models\User
{
    $user = App\Models\User::factory()->create();
    $user->forceFill(['platform_role' => $role])->save();

    if ($twoFactor) {
        app(App\Platform\TwoFactor::class)->begin($user);
        $user->forceFill(['two_factor_confirmed_at' => now()])->save();
    }

    return $user->fresh();
}

/** A one-paragraph ProseMirror document. */
function platformDoc(string $text): array
{
    return ['type' => 'doc', 'content' => [
        ['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => $text]]],
    ]];
}
