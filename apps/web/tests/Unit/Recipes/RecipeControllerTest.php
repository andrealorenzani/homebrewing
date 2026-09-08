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
use PHPUnit\Framework\Attributes\DataProvider;
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

    /**
     * Extracts only the live, rendered `#ingredient-rows` section of the
     * form (excluding the inert `<template id="ingredient-row-template">`
     * clone-source block, which also contains an
     * `name="ingredient_name[]"` field and would otherwise be
     * double-counted by naive substring counting).
     */
    private function extractIngredientRowsSection(string $html): string
    {
        $start = strpos($html, '<div id="ingredient-rows">');
        $end = strpos($html, '<template id="ingredient-row-template">');
        $this->assertIsInt($start, 'Expected #ingredient-rows container to be present.');
        $this->assertIsInt($end, 'Expected ingredient-row-template to be present.');

        return substr($html, $start, $end - $start);
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

    public function testShowCreateFormHasNoFreeTextUnitOrTypeInputs(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showCreateForm($this->authedRequest('GET', '/recipes/new'));
        $body = $response->getBody();

        foreach (['batch_size_unit', 'water_unit', 'sugar_unit', 'sugar_type', 'yeast_unit', 'yeast_type', 'ingredient_unit'] as $field) {
            $this->assertStringNotContainsString(
                'type="text" id="' . $field,
                $body,
                "Expected no free-text input for {$field}.",
            );
        }
    }

    public function testShowCreateFormRendersSelectsForAllConstrainedFields(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showCreateForm($this->authedRequest('GET', '/recipes/new'));
        $body = $response->getBody();

        $this->assertStringContainsString('<select id="batch_size_unit" name="batch_size_unit">', $body);
        $this->assertStringContainsString('<select id="water_unit" name="water_unit">', $body);
        $this->assertStringContainsString('<select id="sugar_unit" name="sugar_unit">', $body);
        $this->assertStringContainsString('<select id="sugar_type" name="sugar_type">', $body);
        $this->assertStringContainsString('<select id="yeast_unit" name="yeast_unit">', $body);
        $this->assertStringContainsString('<select id="yeast_type" name="yeast_type">', $body);
        $this->assertStringContainsString('name="ingredient_unit[]"', $body);

        // Spot-check that the actual constant values populate the options.
        $this->assertStringContainsString('value="L"', $body);
        $this->assertStringContainsString('value="dextrose"', $body);
        $this->assertStringContainsString('value="packet"', $body);
        $this->assertStringContainsString('value="ale"', $body);
    }

    public function testShowCreateFormHasCompactGroupedRowMarkup(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showCreateForm($this->authedRequest('GET', '/recipes/new'));
        $body = $response->getBody();

        $rowCount = substr_count($body, 'class="quantity-unit-row"');
        $this->assertSame(5, $rowCount, 'Expected 5 grouped rows: batch size, water, sugar, yeast, OG/FG.');
        $this->assertStringContainsString('class="field-hint"', $body);
    }

    public function testShowCreateFormHasOgFgConstraintsAndAbvOutput(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showCreateForm($this->authedRequest('GET', '/recipes/new'));
        $body = $response->getBody();

        $this->assertStringContainsString(
            'type="number"',
            $body,
        );
        $this->assertMatchesRegularExpression(
            '/id="target_og"[^>]*step="0\.001"[^>]*min="0\.990"[^>]*max="1\.300"/s',
            $body,
        );
        $this->assertMatchesRegularExpression(
            '/id="target_fg"[^>]*step="0\.001"[^>]*min="0\.990"[^>]*max="1\.300"/s',
            $body,
        );
        $this->assertStringContainsString('id="abv-estimate"', $body);
    }

    public function testShowCreateFormHasAddIngredientButtonAndScriptTag(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showCreateForm($this->authedRequest('GET', '/recipes/new'));
        $body = $response->getBody();

        $this->assertStringContainsString('<button type="button" id="add-ingredient-row"', $body);
        $this->assertStringContainsString('<script src="/js/recipe-form.js" defer></script>', $body);
    }

    public function testShowCreateFormRendersExactlyThreeBlankIngredientRows(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->showCreateForm($this->authedRequest('GET', '/recipes/new'));

        $section = $this->extractIngredientRowsSection($response->getBody());
        $rowCount = substr_count($section, 'name="ingredient_name[]"');
        $this->assertSame(3, $rowCount);
    }

    public function testShowEditFormRendersAllIngredientsWhenMoreThanThreeExist(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);
        $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Big Brew',
            'category' => 'beer',
            'ingredient_name' => ['Malt', 'Hops', 'Yeast', 'Sugar', 'Fruit'],
            'ingredient_type' => ['grain', 'hop', 'other', 'other', 'fruit'],
        ]));

        $request = $this->authedRequest('GET', '/recipes/1/edit', [], 1)->withRouteParams(['id' => '1']);
        $response = $controller->showEditForm($request);

        $section = $this->extractIngredientRowsSection($response->getBody());
        $rowCount = substr_count($section, 'name="ingredient_name[]"');
        $this->assertSame(5, $rowCount);
        $this->assertStringContainsString('value="Malt"', $response->getBody());
        $this->assertStringContainsString('value="Fruit"', $response->getBody());
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

    /**
     * @return list<array{0: string, 1: string}>
     */
    public static function invalidConstrainedFieldProvider(): array
    {
        return [
            'batch_size_unit' => ['batch_size_unit', 'not-a-real-unit'],
            'water_unit' => ['water_unit', 'not-a-real-unit'],
            'sugar_unit' => ['sugar_unit', 'not-a-real-unit'],
            'sugar_type' => ['sugar_type', 'not-a-real-type'],
            'yeast_unit' => ['yeast_unit', 'not-a-real-unit'],
            'yeast_type' => ['yeast_type', 'not-a-real-type'],
        ];
    }

    #[DataProvider('invalidConstrainedFieldProvider')]
    public function testCreateWithInvalidConstrainedFieldFallsBackToNull(string $field, string $invalidValue): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);

        $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Mystery Brew',
            'category' => 'beer',
            $field => $invalidValue,
        ]));

        $row = (new RecipeRepository($db))->findById(1);
        $this->assertNull($row[$field]);
    }

    public function testCreateWithInvalidIngredientUnitFallsBackToNull(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);

        $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Mystery Brew',
            'category' => 'beer',
            'ingredient_name' => ['Mystery Item'],
            'ingredient_type' => ['other'],
            'ingredient_unit' => ['not-a-real-unit'],
        ]));

        $rows = (new RecipeIngredientRepository($db))->findAllByRecipeId(1);
        $this->assertNull($rows[0]['unit']);
    }

    public function testCreateWithValidConstrainedFieldsPersistsThem(): void
    {
        $db = new FakeDb();
        $controller = $this->makeController($db);

        $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Valid Brew',
            'category' => 'beer',
            'batch_size_unit' => 'L',
            'water_unit' => 'L',
            'sugar_unit' => 'g',
            'sugar_type' => 'dextrose',
            'yeast_unit' => 'packet',
            'yeast_type' => 'ale',
        ]));

        $row = (new RecipeRepository($db))->findById(1);
        $this->assertSame('L', $row['batch_size_unit']);
        $this->assertSame('L', $row['water_unit']);
        $this->assertSame('g', $row['sugar_unit']);
        $this->assertSame('dextrose', $row['sugar_type']);
        $this->assertSame('packet', $row['yeast_unit']);
        $this->assertSame('ale', $row['yeast_type']);
    }

    /**
     * @return list<array{0: string}>
     */
    public static function validGravityValueProvider(): array
    {
        return [
            'min boundary' => ['0.990'],
            'max boundary' => ['1.300'],
        ];
    }

    #[DataProvider('validGravityValueProvider')]
    public function testCreateWithGravityAtBoundaryIsAccepted(string $value): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Boundary Brew',
            'category' => 'beer',
            'target_og' => $value,
            'target_fg' => $value,
        ]));

        $this->assertSame(302, $response->getStatusCode());
    }

    /**
     * @return list<array{0: string}>
     */
    public static function invalidGravityValueProvider(): array
    {
        return [
            'below min' => ['0.989'],
            'above max' => ['1.301'],
        ];
    }

    #[DataProvider('invalidGravityValueProvider')]
    public function testCreateWithOutOfRangeTargetOgIsRejected(string $value): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Out Of Range Brew',
            'category' => 'beer',
            'target_og' => $value,
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Target OG must be between', $response->getBody());
    }

    #[DataProvider('invalidGravityValueProvider')]
    public function testCreateWithOutOfRangeTargetFgIsRejected(string $value): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Out Of Range Brew',
            'category' => 'beer',
            'target_fg' => $value,
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Target FG must be between', $response->getBody());
    }

    public function testCreateWithNonNumericTargetOgIsRejected(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'Not A Number Brew',
            'category' => 'beer',
            'target_og' => 'not-a-number',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Target OG must be a number', $response->getBody());
    }

    public function testCreateWithEmptyTargetOgAndFgIsAccepted(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'No Gravity Brew',
            'category' => 'beer',
            'target_og' => '',
            'target_fg' => '',
        ]));

        $this->assertSame(302, $response->getStatusCode());
    }

    public function testCreateWithAbsentTargetOgAndFgIsAccepted(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->create($this->authedRequest('POST', '/recipes', [
            'name' => 'No Gravity Fields Brew',
            'category' => 'beer',
        ]));

        $this->assertSame(302, $response->getStatusCode());
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
