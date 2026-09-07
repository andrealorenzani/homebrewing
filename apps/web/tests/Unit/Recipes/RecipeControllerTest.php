<?php

declare(strict_types=1);

namespace Tests\Unit\Recipes;

use App\Db\FakeDb;
use App\Http\Csrf\CsrfTokenManager;
use App\Http\Request;
use App\Http\Session\ArraySession;
use App\Recipes\RecipeController;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;
use App\View\Renderer;
use Tests\TestCase;

final class RecipeControllerTest extends TestCase
{
    private function makeController(FakeDb $db): RecipeController
    {
        $session = new ArraySession();
        $renderer = new Renderer(dirname(__DIR__, 3) . '/templates');

        return new RecipeController(
            new RecipeService($db, new RecipeRepository($db), new RecipeIngredientRepository($db)),
            new CsrfTokenManager($session),
            $renderer,
        );
    }

    private function authedRequest(string $method, string $path, array $body = [], int $userId = 1): Request
    {
        return Request::create($method, $path, [], $body)->withAttribute('auth_user_id', $userId);
    }

    public function testListOwnRendersOnlyOwnRecipes(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer']));
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Also Mine', 'category' => 'mead']));

        $response = $controller->listOwn($this->authedRequest('GET', '/recipes'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Mine', $response->getBody());
        $this->assertStringContainsString('Also Mine', $response->getBody());
    }

    public function testShowCreateFormRendersForm(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showCreateForm($this->authedRequest('GET', '/recipes/new'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<form', $response->getBody());
    }

    public function testCreateWithValidDataRedirectsToShowPage(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Session IPA',
            'category' => 'beer',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertMatchesRegularExpression('#^/recipes/\d+$#', $response->getHeaders()['Location']);
    }

    public function testCreateWithMissingNameReRendersFormWithError(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->create($this->authedRequest('POST', '/recipes', ['category' => 'beer']));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Name is required', $response->getBody());
    }

    public function testCreateWithInvalidCategoryFallsBackToOther(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);

        $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Mystery Brew',
            'category' => 'not-a-real-category',
        ]));

        $row = (new RecipeRepository($db))->findById(1);
        $this->assertSame('other', $row['category']);
    }

    public function testCreateWithInvalidIngredientTypeFallsBackToOther(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);

        $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Mystery Brew',
            'category' => 'beer',
            'ingredient_name' => ['Mystery Item'],
            'ingredient_type' => ['not-a-real-type'],
        ]));

        $rows = (new RecipeIngredientRepository($db))->findAllByRecipeId(1);
        $this->assertSame('other', $rows[0]['ingredient_type']);
    }

    public function testCreatePersistsIngredientRows(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);

        $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Session IPA',
            'category' => 'beer',
            'ingredient_name' => ['Citra', '', 'Pale Malt'],
            'ingredient_type' => ['hop', 'other', 'grain'],
            'ingredient_quantity' => ['30', '', '4'],
            'ingredient_unit' => ['g', '', 'kg'],
        ]));

        $rows = (new RecipeIngredientRepository($db))->findAllByRecipeId(1);
        $this->assertCount(2, $rows);
        $this->assertSame('Citra', $rows[0]['name']);
        $this->assertSame('Pale Malt', $rows[1]['name']);
    }

    public function testShowReturns404WhenRecipeDoesNotExist(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->show(Request::create('GET', '/recipes/999')->withRouteParams(['id' => '999']));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowReturns404ForAnonymousVisitorOnPrivateRecipe(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Secret', 'category' => 'beer']));

        $request = Request::create('GET', '/recipes/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', null);

        $response = $controller->show($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowReturns404ForOtherLoggedInUserOnPrivateRecipe(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Secret', 'category' => 'beer'], 1));

        $request = Request::create('GET', '/recipes/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', 2);

        $response = $controller->show($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowSucceedsForOwnerOnPrivateRecipe(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Secret', 'category' => 'beer'], 1));

        $request = Request::create('GET', '/recipes/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', 1);

        $response = $controller->show($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Secret', $response->getBody());
    }

    public function testShowSucceedsForAnonymousVisitorOnPublicRecipe(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Open Recipe', 'category' => 'beer'], 1));
        $controller->toggleVisibility($this->authedRequest('POST', '/recipes/1/toggle-visibility', [], 1)->withRouteParams(['id' => '1']));

        $request = Request::create('GET', '/recipes/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', null);

        $response = $controller->show($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('Open Recipe', $response->getBody());
    }

    public function testShowSucceedsForOtherLoggedInUserOnPublicRecipe(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Open Recipe', 'category' => 'beer'], 1));
        $controller->toggleVisibility($this->authedRequest('POST', '/recipes/1/toggle-visibility', [], 1)->withRouteParams(['id' => '1']));

        $request = Request::create('GET', '/recipes/1')
            ->withRouteParams(['id' => '1'])
            ->withAttribute('auth_user_id', 2);

        $response = $controller->show($request);

        $this->assertSame(200, $response->getStatusCode());
    }

    public function testShowEditFormReturns404ForMissingRecipe(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showEditForm($this->authedRequest('GET', '/recipes/999/edit')->withRouteParams(['id' => '999']));

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testShowEditFormReturns403ForNonOwner(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('GET', '/recipes/1/edit', [], 2)->withRouteParams(['id' => '1']);
        $response = $controller->showEditForm($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testShowEditFormSucceedsForOwner(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('GET', '/recipes/1/edit', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->showEditForm($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('value="Mine"', $response->getBody());
    }

    public function testUpdateByNonOwnerReturns403AndDoesNotChangeData(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('POST', '/recipes/1/edit', ['name' => 'Hijacked', 'category' => 'beer'], 2)
            ->withRouteParams(['id' => '1']);
        $response = $controller->update($request);

        $this->assertSame(403, $response->getStatusCode());

        $showResponse = $controller->show(
            Request::create('GET', '/recipes/1')->withRouteParams(['id' => '1'])->withAttribute('auth_user_id', 1),
        );
        $this->assertStringContainsString('Mine', $showResponse->getBody());
    }

    public function testUpdateByOwnerRedirectsToShowPage(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('POST', '/recipes/1/edit', ['name' => 'Renamed', 'category' => 'mead'], 1)
            ->withRouteParams(['id' => '1']);
        $response = $controller->update($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/recipes/1', $response->getHeaders()['Location']);
    }

    public function testUpdateWithMissingNameReRendersFormWithError(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('POST', '/recipes/1/edit', ['name' => '', 'category' => 'beer'], 1)
            ->withRouteParams(['id' => '1']);
        $response = $controller->update($request);

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Name is required', $response->getBody());
    }

    public function testUpdateReturns404ForMissingRecipe(): void
    {
        $controller = $this->makeController(new FakeDb());

        $request = $this->authedRequest('POST', '/recipes/999/edit', ['name' => 'X', 'category' => 'beer'], 1)
            ->withRouteParams(['id' => '999']);
        $response = $controller->update($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testDeleteByNonOwnerReturns403(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('POST', '/recipes/1/delete', [], 2)->withRouteParams(['id' => '1']);
        $response = $controller->delete($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testDeleteByOwnerRedirectsToOwnList(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('POST', '/recipes/1/delete', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->delete($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/recipes', $response->getHeaders()['Location']);
    }

    public function testDeleteReturns404ForMissingRecipe(): void
    {
        $controller = $this->makeController(new FakeDb());

        $request = $this->authedRequest('POST', '/recipes/999/delete', [], 1)->withRouteParams(['id' => '999']);
        $response = $controller->delete($request);

        $this->assertSame(404, $response->getStatusCode());
    }

    public function testToggleVisibilityByNonOwnerReturns403(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('POST', '/recipes/1/toggle-visibility', [], 2)->withRouteParams(['id' => '1']);
        $response = $controller->toggleVisibility($request);

        $this->assertSame(403, $response->getStatusCode());
    }

    public function testToggleVisibilityByOwnerRedirectsAndFlipsFlag(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', ['name' => 'Mine', 'category' => 'beer'], 1));

        $request = $this->authedRequest('POST', '/recipes/1/toggle-visibility', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->toggleVisibility($request);

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/recipes/1', $response->getHeaders()['Location']);

        $anonView = $controller->show(
            Request::create('GET', '/recipes/1')->withRouteParams(['id' => '1'])->withAttribute('auth_user_id', null),
        );
        $this->assertSame(200, $anonView->getStatusCode());
    }

    public function testToggleVisibilityReturns404ForMissingRecipe(): void
    {
        $controller = $this->makeController(new FakeDb());

        $request = $this->authedRequest('POST', '/recipes/999/toggle-visibility', [], 1)->withRouteParams(['id' => '999']);
        $response = $controller->toggleVisibility($request);

        $this->assertSame(404, $response->getStatusCode());
    }
}
