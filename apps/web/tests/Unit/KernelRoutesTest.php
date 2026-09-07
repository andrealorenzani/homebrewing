<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Config\Config;
use App\Db\FakeDb;
use App\Http\Csrf\CsrfTokenManager;
use App\Http\Request;
use App\Http\Session\ArraySession;
use App\Kernel;
use Tests\TestCase;

/**
 * Exercises the Auth/Recipes routes wired in Kernel end to end (through
 * the real Router + middleware pipeline), using an injected FakeDb and
 * ArraySession so no real session/database is touched. Complements the
 * more granular AuthController/RecipeController unit tests by proving
 * the actual route registrations (paths, methods, middleware) are wired
 * correctly.
 */
final class KernelRoutesTest extends TestCase
{
    private function makeKernel(?ArraySession $session = null, ?FakeDb $db = null): Kernel
    {
        return new Kernel(Config::fromArray([]), $session ?? new ArraySession(), $db ?? new FakeDb());
    }

    private function csrfTokenFor(ArraySession $session): string
    {
        return (new CsrfTokenManager($session))->getToken();
    }

    public function testGetRegisterFormRendersWithoutAuth(): void
    {
        $kernel = $this->makeKernel();

        $response = $kernel->handle(Request::create('GET', '/register'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Register', $response->getBody());
    }

    public function testPostRegisterWithoutCsrfTokenIsRejected(): void
    {
        $kernel = $this->makeKernel();

        $response = $kernel->handle(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
        ]));

        $this->assertSame(419, $response->getStatusCode());
    }

    public function testFullRegisterLoginRecipeLifecycleThroughKernel(): void
    {
        $session = new ArraySession();
        $db = new FakeDb();
        $kernel = $this->makeKernel($session, $db);
        $csrf = $this->csrfTokenFor($session);

        $registerResponse = $kernel->handle(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $registerResponse->getStatusCode());
        $this->assertSame('/login', $registerResponse->getHeaders()['Location']);

        $loginResponse = $kernel->handle(Request::create('POST', '/login', [], [
            'username' => 'alice',
            'password' => 'correct-horse',
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $loginResponse->getStatusCode());
        $this->assertSame('/recipes', $loginResponse->getHeaders()['Location']);
        $this->assertNotNull($session->get('user_id'));

        // GET /recipes (own list) requires auth and now succeeds.
        $listResponse = $kernel->handle(Request::create('GET', '/recipes'));
        $this->assertSame(200, $listResponse->getStatusCode());

        // Create a recipe.
        $createResponse = $kernel->handle(Request::create('POST', '/recipes', [], [
            'name' => 'Session IPA',
            'category' => 'beer',
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $createResponse->getStatusCode());
        $location = $createResponse->getHeaders()['Location'];
        $this->assertMatchesRegularExpression('#^/recipes/\d+$#', $location);

        // View it (still logged in, owner).
        $showResponse = $kernel->handle(Request::create('GET', $location));
        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertStringContainsString('Session IPA', $showResponse->getBody());

        // Toggle visibility to public.
        $toggleResponse = $kernel->handle(Request::create('POST', $location . '/toggle-visibility', [], [
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $toggleResponse->getStatusCode());

        // Anonymous visitor can now view it.
        $anonSession = new ArraySession();
        $anonKernel = $this->makeKernel($anonSession, $db);
        $anonResponse = $anonKernel->handle(Request::create('GET', $location));
        $this->assertSame(200, $anonResponse->getStatusCode());

        // Edit form and update as owner.
        $editFormResponse = $kernel->handle(Request::create('GET', $location . '/edit'));
        $this->assertSame(200, $editFormResponse->getStatusCode());

        $updateResponse = $kernel->handle(Request::create('POST', $location . '/edit', [], [
            'name' => 'Session IPA v2',
            'category' => 'beer',
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $updateResponse->getStatusCode());

        // Logout.
        $logoutResponse = $kernel->handle(Request::create('POST', '/logout', [], ['csrf_token' => $csrf]));
        $this->assertSame(302, $logoutResponse->getStatusCode());
        $this->assertSame('/login', $logoutResponse->getHeaders()['Location']);
        $this->assertNull($session->get('user_id'));

        // Now logged out: GET /recipes redirects to /login.
        $listAfterLogout = $kernel->handle(Request::create('GET', '/recipes'));
        $this->assertSame(302, $listAfterLogout->getStatusCode());
        $this->assertSame('/login', $listAfterLogout->getHeaders()['Location']);

        // But the recipe is still public, so the show page still works
        // for the now-anonymous former owner.
        $showAfterLogout = $kernel->handle(Request::create('GET', $location));
        $this->assertSame(200, $showAfterLogout->getStatusCode());

        // Delete requires auth again.
        $deleteWithoutAuth = $kernel->handle(Request::create('POST', $location . '/delete', [], ['csrf_token' => $csrf]));
        $this->assertSame(302, $deleteWithoutAuth->getStatusCode());
        $this->assertSame('/login', $deleteWithoutAuth->getHeaders()['Location']);
    }

    public function testGetLoginFormRenders(): void
    {
        $kernel = $this->makeKernel();

        $response = $kernel->handle(Request::create('GET', '/login'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Log in', $response->getBody());
    }

    public function testHomepageShowsPublicRecipesAndExcludesPrivateOnesThroughKernel(): void
    {
        $session = new ArraySession();
        $db = new FakeDb();
        $kernel = $this->makeKernel($session, $db);
        $csrf = $this->csrfTokenFor($session);

        $kernel->handle(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
            'csrf_token' => $csrf,
        ]));
        $kernel->handle(Request::create('POST', '/login', [], [
            'username' => 'alice',
            'password' => 'correct-horse',
            'csrf_token' => $csrf,
        ]));

        $kernel->handle(Request::create('POST', '/recipes', [], [
            'name' => 'Homepage Public IPA',
            'category' => 'beer',
            'csrf_token' => $csrf,
        ]));
        $kernel->handle(Request::create('POST', '/recipes', [], [
            'name' => 'Homepage Private Stout',
            'category' => 'beer',
            'csrf_token' => $csrf,
        ]));
        $kernel->handle(Request::create('POST', '/recipes/1/toggle-visibility', [], ['csrf_token' => $csrf]));

        $homepage = $kernel->handle(Request::create('GET', '/'));

        $this->assertSame(200, $homepage->getStatusCode());
        $this->assertStringContainsString('Homepage Public IPA', $homepage->getBody());
        $this->assertStringNotContainsString('Homepage Private Stout', $homepage->getBody());
        $this->assertStringContainsString('href="/recipes"', $homepage->getBody());
        $this->assertStringContainsString('href="/diaries"', $homepage->getBody());
    }

    public function testHomepageRendersEmptyStateWhenNoPublicRecipesExist(): void
    {
        $kernel = $this->makeKernel();

        $response = $kernel->handle(Request::create('GET', '/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('No public recipes yet', $response->getBody());
    }

    public function testRecipesNewFormRequiresAuth(): void
    {
        $kernel = $this->makeKernel();

        $response = $kernel->handle(Request::create('GET', '/recipes/new'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testRecipeDeleteAndToggleByNonOwnerAreForbiddenThroughKernel(): void
    {
        $db = new FakeDb();

        $ownerSession = new ArraySession();
        $ownerKernel = $this->makeKernel($ownerSession, $db);
        $ownerCsrf = $this->csrfTokenFor($ownerSession);
        $ownerKernel->handle(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
            'csrf_token' => $ownerCsrf,
        ]));
        $ownerKernel->handle(Request::create('POST', '/login', [], [
            'username' => 'alice',
            'password' => 'correct-horse',
            'csrf_token' => $ownerCsrf,
        ]));
        $createResponse = $ownerKernel->handle(Request::create('POST', '/recipes', [], [
            'name' => 'Owned Recipe',
            'category' => 'beer',
            'csrf_token' => $ownerCsrf,
        ]));
        $location = $createResponse->getHeaders()['Location'];

        $otherSession = new ArraySession();
        $otherKernel = $this->makeKernel($otherSession, $db);
        $otherCsrf = $this->csrfTokenFor($otherSession);
        $otherKernel->handle(Request::create('POST', '/register', [], [
            'username' => 'bob',
            'email' => 'bob@example.com',
            'password' => 'another-password',
            'csrf_token' => $otherCsrf,
        ]));
        $otherKernel->handle(Request::create('POST', '/login', [], [
            'username' => 'bob',
            'password' => 'another-password',
            'csrf_token' => $otherCsrf,
        ]));

        $deleteResponse = $otherKernel->handle(Request::create('POST', $location . '/delete', [], [
            'csrf_token' => $otherCsrf,
        ]));
        $this->assertSame(403, $deleteResponse->getStatusCode());

        $toggleResponse = $otherKernel->handle(Request::create('POST', $location . '/toggle-visibility', [], [
            'csrf_token' => $otherCsrf,
        ]));
        $this->assertSame(403, $toggleResponse->getStatusCode());

        // Still private and hidden from the other user's own view of it.
        $showAsOther = $otherKernel->handle(Request::create('GET', $location));
        $this->assertSame(404, $showAsOther->getStatusCode());
    }

    public function testFullDiaryLifecycleThroughKernel(): void
    {
        $session = new ArraySession();
        $db = new FakeDb();
        $kernel = $this->makeKernel($session, $db);
        $csrf = $this->csrfTokenFor($session);

        $kernel->handle(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
            'csrf_token' => $csrf,
        ]));
        $kernel->handle(Request::create('POST', '/login', [], [
            'username' => 'alice',
            'password' => 'correct-horse',
            'csrf_token' => $csrf,
        ]));

        $createRecipeResponse = $kernel->handle(Request::create('POST', '/recipes', [], [
            'name' => 'Session IPA',
            'category' => 'beer',
            'csrf_token' => $csrf,
        ]));
        $recipeLocation = $createRecipeResponse->getHeaders()['Location'];
        $recipeId = (int) substr($recipeLocation, strrpos($recipeLocation, '/') + 1);

        // GET /diaries (own list) requires auth and now succeeds.
        $listResponse = $kernel->handle(Request::create('GET', '/diaries'));
        $this->assertSame(200, $listResponse->getStatusCode());

        $newFormResponse = $kernel->handle(Request::create('GET', '/diaries/new'));
        $this->assertSame(200, $newFormResponse->getStatusCode());
        $this->assertStringContainsString('Session IPA', $newFormResponse->getBody());

        $createResponse = $kernel->handle(Request::create('POST', '/diaries', [], [
            'recipe_id' => (string) $recipeId,
            'label' => 'First Batch',
            'started_at' => '2026-01-01',
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $createResponse->getStatusCode());
        $location = $createResponse->getHeaders()['Location'];
        $this->assertMatchesRegularExpression('#^/diaries/\d+$#', $location);

        $showResponse = $kernel->handle(Request::create('GET', $location));
        $this->assertSame(200, $showResponse->getStatusCode());
        $this->assertStringContainsString('First Batch', $showResponse->getBody());

        $addLogResponse = $kernel->handle(Request::create('POST', $location . '/log-entries', [], [
            'entry_date' => '2026-01-05',
            'note' => 'Bubbling nicely.',
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $addLogResponse->getStatusCode());

        $showWithEntryResponse = $kernel->handle(Request::create('GET', $location));
        $this->assertStringContainsString('Day 4', $showWithEntryResponse->getBody());
        $this->assertStringContainsString('Bubbling nicely.', $showWithEntryResponse->getBody());

        // Toggle visibility to public.
        $toggleResponse = $kernel->handle(Request::create('POST', $location . '/toggle-visibility', [], [
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $toggleResponse->getStatusCode());

        // Anonymous visitor can now view it, including the log timeline.
        $anonSession = new ArraySession();
        $anonKernel = $this->makeKernel($anonSession, $db);
        $anonResponse = $anonKernel->handle(Request::create('GET', $location));
        $this->assertSame(200, $anonResponse->getStatusCode());
        $this->assertStringContainsString('Bubbling nicely.', $anonResponse->getBody());

        // Edit the batch metadata and the log entry as the owner.
        $editFormResponse = $kernel->handle(Request::create('GET', $location . '/edit'));
        $this->assertSame(200, $editFormResponse->getStatusCode());

        $updateResponse = $kernel->handle(Request::create('POST', $location . '/edit', [], [
            'label' => 'First Batch v2',
            'status' => 'fermenting',
            'started_at' => '2026-01-01',
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $updateResponse->getStatusCode());

        $editLogFormResponse = $kernel->handle(Request::create('GET', $location . '/log-entries/1/edit'));
        $this->assertSame(200, $editLogFormResponse->getStatusCode());

        $updateLogResponse = $kernel->handle(Request::create('POST', $location . '/log-entries/1/edit', [], [
            'entry_date' => '2026-01-06',
            'note' => 'Updated note.',
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $updateLogResponse->getStatusCode());

        $deleteLogResponse = $kernel->handle(Request::create('POST', $location . '/log-entries/1/delete', [], [
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $deleteLogResponse->getStatusCode());

        // Delete the batch itself.
        $deleteResponse = $kernel->handle(Request::create('POST', $location . '/delete', [], [
            'csrf_token' => $csrf,
        ]));
        $this->assertSame(302, $deleteResponse->getStatusCode());
        $this->assertSame('/diaries', $deleteResponse->getHeaders()['Location']);

        $showAfterDelete = $kernel->handle(Request::create('GET', $location));
        $this->assertSame(404, $showAfterDelete->getStatusCode());
    }

    public function testDiariesNewFormRequiresAuth(): void
    {
        $kernel = $this->makeKernel();

        $response = $kernel->handle(Request::create('GET', '/diaries/new'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testUsesRealSessionAndDatabaseByDefaultWithoutEagerlyConnecting(): void
    {
        // Constructing a Kernel without an injected session/db must not
        // eagerly start a session or open a database connection — those
        // must only happen lazily, on first actual use by a dispatched
        // route. /healthz never touches either.
        $kernel = new Kernel(Config::fromArray([]));

        $response = $kernel->handle(Request::create('GET', '/healthz'));

        $this->assertSame(200, $response->getStatusCode());
    }
}
