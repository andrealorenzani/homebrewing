<?php

declare(strict_types=1);

namespace Tests\Unit\Batches;

use App\Batches\BatchController;
use App\Batches\BatchLogEntryRepository;
use App\Batches\BatchRepository;
use App\Batches\BatchService;
use App\Db\FakeDb;
use App\Http\Csrf\CsrfTokenManager;
use App\Http\Request;
use App\Http\Session\ArraySession;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;
use App\View\Renderer;
use Tests\TestCase;

final class BatchControllerTest extends TestCase
{
    private function makeController(FakeDb $db): BatchController
    {
        $session = new ArraySession();
        $renderer = new Renderer(dirname(__DIR__, 3) . '/templates');

        return new BatchController(
            new BatchService(
                new BatchRepository($db),
                new BatchLogEntryRepository($db),
                new RecipeRepository($db),
            ),
            new CsrfTokenManager($session),
            $renderer,
        );
    }

    private function authedRequest(string $method, string $path, array $body = [], int $userId = 1): Request
    {
        return Request::create($method, $path, [], $body)->withAttribute('auth_user_id', $userId);
    }

    /**
     * Creates a recipe owned by $userId directly via RecipeService (not
     * through the BatchController, which has no recipe-creation routes)
     * and returns its id, for use as the recipe a batch is created under.
     */
    private function createRecipe(FakeDb $db, int $userId = 1, string $name = 'Session IPA'): int
    {
        $recipeService = new RecipeService($db, new RecipeRepository($db), new RecipeIngredientRepository($db));

        return $recipeService->create($userId, [
            'name' => $name,
            'category' => 'beer',
            'description' => null,
            'batch_size' => null,
            'batch_size_unit' => null,
            'water_quantity' => null,
            'water_unit' => null,
            'sugar_quantity' => null,
            'sugar_unit' => null,
            'sugar_type' => null,
            'yeast_type' => null,
            'yeast_quantity' => null,
            'yeast_unit' => null,
            'target_og' => null,
            'target_fg' => null,
            'notes' => null,
        ], []);
    }

    public function testListOwnRendersOnlyOwnBatches(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'label' => 'Mine', 'started_at' => '2026-01-01']));

        $response = $controller->listOwn($this->authedRequest('GET', '/diaries'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Mine', $response->getBody());
    }

    public function testShowCreateFormRendersFormWithOwnRecipes(): void
    {
        $db = new FakeDb();
        $this->createRecipe($db, 1, 'My Recipe');
        $controller = $this->makeController($db);

        $response = $controller->showCreateForm($this->authedRequest('GET', '/diaries/new'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<form', $response->getBody());
        $this->assertStringContainsString('My Recipe', $response->getBody());
    }

    public function testCreateWithValidDataRedirectsToShowPage(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);

        $response = $controller->create($this->authedRequest('POST', '/diaries', [
            'recipe_id' => (string) $recipeId,
            'label' => 'Batch 1',
            'started_at' => '2026-01-01',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertMatchesRegularExpression('#^/diaries/\d+$#', $response->getHeaders()['Location']);
    }

    public function testCreateWithMissingStartedAtReRendersFormWithError(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);

        $response = $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Start date is required', $response->getBody());
    }

    public function testCreateWithMissingRecipeIdReRendersFormWithError(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);

        $response = $controller->create($this->authedRequest('POST', '/diaries', ['started_at' => '2026-01-01']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('recipe is required', $response->getBody());
    }

    public function testCreateWithNonExistentRecipeReturns404(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);

        $response = $controller->create($this->authedRequest('POST', '/diaries', [
            'recipe_id' => '999',
            'started_at' => '2026-01-01',
        ]));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testCreateWithAnotherUsersRecipeReturns403(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db, 1);
        $controller = $this->makeController($db);

        $response = $controller->create($this->authedRequest('POST', '/diaries', [
            'recipe_id' => (string) $recipeId,
            'started_at' => '2026-01-01',
        ], 2));

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testCreateWithInvalidStatusFallsBackToPlanning(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);

        $controller->create($this->authedRequest('POST', '/diaries', [
            'recipe_id' => (string) $recipeId,
            'started_at' => '2026-01-01',
            'status' => 'not-a-real-status',
        ]));

        $row = (new BatchRepository($db))->findById(1);
        $this->assertSame('planning', $row['status']);
    }

    public function testShowReturns404WhenBatchDoesNotExist(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->show(Request::create('GET', '/diaries/999')->withRouteParams(['id' => '999']));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowReturns404ForAnonymousVisitorOnPrivateBatch(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01']));

        $request = Request::create('GET', '/diaries/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', null);

        $response = $controller->show($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowReturns404ForOtherLoggedInUserOnPrivateBatch(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = Request::create('GET', '/diaries/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', 2);

        $response = $controller->show($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowSucceedsForOwnerOnPrivateBatchAndComputesDayNumbers(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'label' => 'Secret', 'started_at' => '2026-01-01'], 1));
        $controller->addLogEntry(
            $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05'], 1)->withRouteParams(['id' => '1']),
        );

        $request = Request::create('GET', '/diaries/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', 1);

        $response = $controller->show($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Secret', $response->getBody());
        $this->assertStringContainsString('Day 4', $response->getBody());
    }

    public function testShowSucceedsForAnonymousVisitorOnPublicBatch(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'label' => 'Open Batch', 'started_at' => '2026-01-01'], 1));
        $controller->toggleVisibility($this->authedRequest('POST', '/diaries/1/toggle-visibility', [], 1)->withRouteParams(['id' => '1']));

        $request = Request::create('GET', '/diaries/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', null);

        $response = $controller->show($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Open Batch', $response->getBody());
    }

    public function testShowSucceedsForOtherLoggedInUserOnPublicBatch(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));
        $controller->toggleVisibility($this->authedRequest('POST', '/diaries/1/toggle-visibility', [], 1)->withRouteParams(['id' => '1']));

        $request = Request::create('GET', '/diaries/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', 2);

        $response = $controller->show($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowEditFormReturns404ForMissingBatch(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showEditForm($this->authedRequest('GET', '/diaries/999/edit')->withRouteParams(['id' => '999']));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowEditFormReturns403ForNonOwner(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('GET', '/diaries/1/edit', [], 2)->withRouteParams(['id' => '1']);
        $response = $controller->showEditForm($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowEditFormSucceedsForOwner(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'label' => 'Mine', 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('GET', '/diaries/1/edit', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->showEditForm($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('value="Mine"', $response->getBody());
    }

    public function testUpdateByNonOwnerReturns403AndDoesNotChangeData(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'label' => 'Mine', 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/edit', ['label' => 'Hijacked', 'started_at' => '2026-01-02'], 2)
            ->withRouteParams(['id' => '1']);
        $response = $controller->update($request);

        $this->assertSame(403, $response->getStatusCode());

        $showResponse = $controller->show(
            Request::create('GET', '/diaries/1')->withRouteParams(['id' => '1'])->withAttribute('auth_user_id', 1),
        );
        $this->assertStringContainsString('Mine', $showResponse->getBody());
    }

    public function testUpdateByOwnerRedirectsToShowPage(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'label' => 'Mine', 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/edit', ['label' => 'Renamed', 'status' => 'fermenting', 'started_at' => '2026-01-02'], 1)
            ->withRouteParams(['id' => '1']);
        $response = $controller->update($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/diaries/1', $response->getHeaders()['Location']);
    }

    public function testUpdateWithMissingStartedAtReRendersFormWithError(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/edit', ['started_at' => ''], 1)
            ->withRouteParams(['id' => '1']);
        $response = $controller->update($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Start date is required', $response->getBody());
    }

    public function testUpdateReturns404ForMissingBatch(): void
    {
        $controller = $this->makeController(new FakeDb());

        $request = $this->authedRequest('POST', '/diaries/999/edit', ['started_at' => '2026-01-01'], 1)
            ->withRouteParams(['id' => '999']);
        $response = $controller->update($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteByNonOwnerReturns403(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/delete', [], 2)->withRouteParams(['id' => '1']);
        $response = $controller->delete($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteByOwnerRedirectsToOwnList(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/delete', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->delete($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/diaries', $response->getHeaders()['Location']);
    }

    public function testDeleteReturns404ForMissingBatch(): void
    {
        $controller = $this->makeController(new FakeDb());

        $request = $this->authedRequest('POST', '/diaries/999/delete', [], 1)->withRouteParams(['id' => '999']);
        $response = $controller->delete($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testToggleVisibilityByNonOwnerReturns403(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/toggle-visibility', [], 2)->withRouteParams(['id' => '1']);
        $response = $controller->toggleVisibility($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testToggleVisibilityByOwnerRedirectsAndFlipsFlag(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/toggle-visibility', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->toggleVisibility($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/diaries/1', $response->getHeaders()['Location']);

        $anonView = $controller->show(
            Request::create('GET', '/diaries/1')->withRouteParams(['id' => '1'])->withAttribute('auth_user_id', null),
        );
        $this->assertSame(200, $anonView->getStatusCode());
    }

    public function testToggleVisibilityReturns404ForMissingBatch(): void
    {
        $controller = $this->makeController(new FakeDb());

        $request = $this->authedRequest('POST', '/diaries/999/toggle-visibility', [], 1)->withRouteParams(['id' => '999']);
        $response = $controller->toggleVisibility($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    // --- Log entries ---

    public function testShowAddLogEntryFormReturns403ForNonOwner(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('GET', '/diaries/1/log-entries/new', [], 2)->withRouteParams(['id' => '1']);
        $response = $controller->showAddLogEntryForm($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowAddLogEntryFormReturns404ForMissingBatch(): void
    {
        $controller = $this->makeController(new FakeDb());

        $request = $this->authedRequest('GET', '/diaries/999/log-entries/new')->withRouteParams(['id' => '999']);
        $response = $controller->showAddLogEntryForm($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowAddLogEntryFormSucceedsForOwner(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('GET', '/diaries/1/log-entries/new', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->showAddLogEntryForm($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<form', $response->getBody());
    }

    public function testAddLogEntryWithMissingDateReRendersFormWithError(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/log-entries', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->addLogEntry($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Entry date is required', $response->getBody());
    }

    public function testAddLogEntryByNonOwnerReturns403(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05'], 2)->withRouteParams(['id' => '1']);
        $response = $controller->addLogEntry($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testAddLogEntryOnMissingBatchReturns404(): void
    {
        $controller = $this->makeController(new FakeDb());

        $request = $this->authedRequest('POST', '/diaries/999/log-entries', ['entry_date' => '2026-01-05'], 1)->withRouteParams(['id' => '999']);
        $response = $controller->addLogEntry($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testAddLogEntryByOwnerRedirectsToBatchShowPage(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/log-entries', [
            'entry_date' => '2026-01-05',
            'note' => 'Fermenting well.',
            'specific_gravity' => '1.020',
        ], 1)->withRouteParams(['id' => '1']);
        $response = $controller->addLogEntry($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/diaries/1', $response->getHeaders()['Location']);
    }

    public function testShowEditLogEntryFormReturns404ForMissingEntry(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('GET', '/diaries/1/log-entries/999/edit', [], 1)
            ->withRouteParams(['id' => '1', 'entryId' => '999']);
        $response = $controller->showEditLogEntryForm($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowEditLogEntryFormReturns403ForNonOwner(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));
        $controller->addLogEntry(
            $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05'], 1)->withRouteParams(['id' => '1']),
        );

        $request = $this->authedRequest('GET', '/diaries/1/log-entries/1/edit', [], 2)
            ->withRouteParams(['id' => '1', 'entryId' => '1']);
        $response = $controller->showEditLogEntryForm($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowEditLogEntryFormSucceedsForOwner(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));
        $controller->addLogEntry(
            $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05', 'note' => 'Editable'], 1)->withRouteParams(['id' => '1']),
        );

        $request = $this->authedRequest('GET', '/diaries/1/log-entries/1/edit', [], 1)
            ->withRouteParams(['id' => '1', 'entryId' => '1']);
        $response = $controller->showEditLogEntryForm($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Editable', $response->getBody());
    }

    public function testUpdateLogEntryWithMissingDateReRendersFormWithError(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));
        $controller->addLogEntry(
            $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05'], 1)->withRouteParams(['id' => '1']),
        );

        $request = $this->authedRequest('POST', '/diaries/1/log-entries/1/edit', ['entry_date' => ''], 1)
            ->withRouteParams(['id' => '1', 'entryId' => '1']);
        $response = $controller->updateLogEntry($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Entry date is required', $response->getBody());
    }

    public function testUpdateLogEntryByNonOwnerReturns403(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));
        $controller->addLogEntry(
            $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05'], 1)->withRouteParams(['id' => '1']),
        );

        $request = $this->authedRequest('POST', '/diaries/1/log-entries/1/edit', ['entry_date' => '2026-01-06'], 2)
            ->withRouteParams(['id' => '1', 'entryId' => '1']);
        $response = $controller->updateLogEntry($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testUpdateLogEntryReturns404ForMissingEntry(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/log-entries/999/edit', ['entry_date' => '2026-01-06'], 1)
            ->withRouteParams(['id' => '1', 'entryId' => '999']);
        $response = $controller->updateLogEntry($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testUpdateLogEntryByOwnerRedirectsToBatchShowPage(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));
        $controller->addLogEntry(
            $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05'], 1)->withRouteParams(['id' => '1']),
        );

        $request = $this->authedRequest('POST', '/diaries/1/log-entries/1/edit', ['entry_date' => '2026-01-06'], 1)
            ->withRouteParams(['id' => '1', 'entryId' => '1']);
        $response = $controller->updateLogEntry($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/diaries/1', $response->getHeaders()['Location']);
    }

    public function testDeleteLogEntryByNonOwnerReturns403(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));
        $controller->addLogEntry(
            $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05'], 1)->withRouteParams(['id' => '1']),
        );

        $request = $this->authedRequest('POST', '/diaries/1/log-entries/1/delete', [], 2)
            ->withRouteParams(['id' => '1', 'entryId' => '1']);
        $response = $controller->deleteLogEntry($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteLogEntryReturns404ForMissingEntry(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));

        $request = $this->authedRequest('POST', '/diaries/1/log-entries/999/delete', [], 1)
            ->withRouteParams(['id' => '1', 'entryId' => '999']);
        $response = $controller->deleteLogEntry($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteLogEntryByOwnerRedirectsToBatchShowPage(): void
    {
        $db = new FakeDb();
        $recipeId = $this->createRecipe($db);
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/diaries', ['recipe_id' => (string) $recipeId, 'started_at' => '2026-01-01'], 1));
        $controller->addLogEntry(
            $this->authedRequest('POST', '/diaries/1/log-entries', ['entry_date' => '2026-01-05'], 1)->withRouteParams(['id' => '1']),
        );

        $request = $this->authedRequest('POST', '/diaries/1/log-entries/1/delete', [], 1)
            ->withRouteParams(['id' => '1', 'entryId' => '1']);
        $response = $controller->deleteLogEntry($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/diaries/1', $response->getHeaders()['Location']);
    }
}
