<?php

declare(strict_types=1);

namespace Tests\Unit\Home;

use App\Db\FakeDb;
use App\Home\HomeController;
use App\Http\Request;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;
use App\View\Renderer;
use Tests\TestCase;

/**
 * Proves the homepage lists only public recipes ordered by recency
 * (most-recently-updated first), explicitly excludes a more-recently-
 * updated private recipe, respects the configured showcase count, and
 * renders a graceful empty state when there are zero public recipes.
 */
final class HomeControllerTest extends TestCase
{
    private function makeController(FakeDb $db, int $count = 6): HomeController
    {
        return new HomeController(
            new RecipeService($db, new RecipeRepository($db), new RecipeIngredientRepository($db)),
            new Renderer(dirname(__DIR__, 3) . '/templates'),
            $count,
        );
    }

    public function testShowRendersEmptyStateWhenNoPublicRecipesExist(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->show(Request::create('GET', '/'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('No public recipes yet', $response->getBody());
    }

    public function testShowListsOnlyPublicRecipes(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'user_id' => 1, 'name' => 'Public Ale', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-01-02 00:00:00'],
            ['id' => 2, 'user_id' => 1, 'name' => 'Private Mead', 'category' => 'mead', 'is_public' => 0, 'updated_at' => '2026-01-05 00:00:00'],
        ]);
        $controller = $this->makeController($db);

        $response = $controller->show(Request::create('GET', '/'));

        $this->assertStringContainsString('Public Ale', $response->getBody());
        $this->assertStringNotContainsString('Private Mead', $response->getBody());
    }

    public function testShowExcludesAMoreRecentlyUpdatedPrivateRecipe(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'user_id' => 1, 'name' => 'Older Public IPA', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            // Updated far more recently than the public recipe above, but private.
            ['id' => 2, 'user_id' => 2, 'name' => 'Freshly Updated Secret Stout', 'category' => 'beer', 'is_public' => 0, 'updated_at' => '2026-06-01 00:00:00'],
        ]);
        $controller = $this->makeController($db);

        $response = $controller->show(Request::create('GET', '/'));

        $this->assertStringContainsString('Older Public IPA', $response->getBody());
        $this->assertStringNotContainsString('Freshly Updated Secret Stout', $response->getBody());
    }

    public function testShowOrdersPublicRecipesByMostRecentlyUpdatedFirst(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'user_id' => 1, 'name' => 'Oldest', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'user_id' => 1, 'name' => 'Newest', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-03-01 00:00:00'],
            ['id' => 3, 'user_id' => 1, 'name' => 'Middle', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-02-01 00:00:00'],
        ]);
        $controller = $this->makeController($db);

        $response = $controller->show(Request::create('GET', '/'));
        $body = $response->getBody();

        $newestPos = strpos($body, 'Newest');
        $middlePos = strpos($body, 'Middle');
        $oldestPos = strpos($body, 'Oldest');

        $this->assertNotFalse($newestPos);
        $this->assertNotFalse($middlePos);
        $this->assertNotFalse($oldestPos);
        $this->assertLessThan($middlePos, $newestPos);
        $this->assertLessThan($oldestPos, $middlePos);
    }

    public function testShowRespectsConfiguredShowcaseCount(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 1, 'user_id' => 1, 'name' => 'RecipeOne', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-01-01 00:00:00'],
            ['id' => 2, 'user_id' => 1, 'name' => 'RecipeTwo', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-01-02 00:00:00'],
            ['id' => 3, 'user_id' => 1, 'name' => 'RecipeThree', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-01-03 00:00:00'],
        ]);
        $controller = $this->makeController($db, 2);

        $response = $controller->show(Request::create('GET', '/'));
        $body = $response->getBody();

        $this->assertStringContainsString('RecipeThree', $body);
        $this->assertStringContainsString('RecipeTwo', $body);
        $this->assertStringNotContainsString('RecipeOne', $body);
    }

    public function testShowLinksToTheRecipesDetailRoute(): void
    {
        $db = new FakeDb();
        $db->seedTable('recipes', [
            ['id' => 7, 'user_id' => 1, 'name' => 'Linked Recipe', 'category' => 'beer', 'is_public' => 1, 'updated_at' => '2026-01-01 00:00:00'],
        ]);
        $controller = $this->makeController($db);

        $response = $controller->show(Request::create('GET', '/'));

        $this->assertStringContainsString('href="/recipes/7"', $response->getBody());
    }

    public function testShowRendersNavWithRecipesAndDiariesLinks(): void
    {
        $controller = $this->makeController(new FakeDb());

        $response = $controller->show(Request::create('GET', '/'));
        $body = $response->getBody();

        $this->assertStringContainsString('href="/recipes"', $body);
        $this->assertStringContainsString('href="/diaries"', $body);
    }
}
