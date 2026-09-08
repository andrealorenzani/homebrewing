<?php

declare(strict_types=1);

namespace Tests\Unit\Recipes;

use App\Db\FakeDb;
use App\Recipes\Exception\RecipeAccessDeniedException;
use App\Recipes\Exception\RecipeNotFoundException;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;
use Tests\TestCase;

final class RecipeServiceTest extends TestCase
{
    private function makeService(): RecipeService
    {
        $db = new FakeDb();

        return new RecipeService($db, new RecipeRepository($db), new RecipeIngredientRepository($db));
    }

    private function sampleRecipeData(array $overrides = []): array
    {
        return [...[
            'name' => 'Session IPA',
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
        ], ...$overrides];
    }

    public function testCreatePersistsRecipeAsPrivateByDefault(): void
    {
        $service = $this->makeService();

        $id = $service->create(1, $this->sampleRecipeData(), []);
        $result = $service->viewForUser($id, 1);

        $this->assertSame('Session IPA', $result['recipe']['name']);
        $this->assertSame(0, $result['recipe']['is_public']);
        $this->assertSame(1, $result['recipe']['user_id']);
    }

    public function testCreatePersistsIngredients(): void
    {
        $service = $this->makeService();

        $id = $service->create(1, $this->sampleRecipeData(), [
            ['ingredient_type' => 'hop', 'name' => 'Citra', 'quantity' => '30', 'unit' => 'g', 'timing_note' => null],
        ]);

        $result = $service->viewForUser($id, 1);
        $this->assertCount(1, $result['ingredients']);
        $this->assertSame('Citra', $result['ingredients'][0]['name']);
    }

    public function testListOwnReturnsOnlyThatUsersRecipes(): void
    {
        $service = $this->makeService();
        $service->create(1, $this->sampleRecipeData(['name' => 'Mine']), []);
        $service->create(2, $this->sampleRecipeData(['name' => 'Not Mine']), []);

        $recipes = $service->listOwn(1);

        $this->assertCount(1, $recipes);
        $this->assertSame('Mine', $recipes[0]['name']);
    }

    // --- Visibility matrix: owner / other user / anonymous x public / private ---

    public function testOwnerCanViewOwnPrivateRecipe(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $result = $service->viewForUser($id, 1);

        $this->assertSame($id, $result['recipe']['id']);
    }

    public function testOtherAuthenticatedUserCannotViewPrivateRecipe(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $this->expectException(RecipeNotFoundException::class);
        $service->viewForUser($id, 2);
    }

    public function testAnonymousVisitorCannotViewPrivateRecipe(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $this->expectException(RecipeNotFoundException::class);
        $service->viewForUser($id, null);
    }

    public function testOtherAuthenticatedUserCanViewPublicRecipe(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);
        $service->toggleVisibility($id, 1);

        $result = $service->viewForUser($id, 2);

        $this->assertSame($id, $result['recipe']['id']);
    }

    public function testAnonymousVisitorCanViewPublicRecipe(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);
        $service->toggleVisibility($id, 1);

        $result = $service->viewForUser($id, null);

        $this->assertSame($id, $result['recipe']['id']);
    }

    public function testOwnerCanStillViewOwnRecipeAfterMadePublic(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);
        $service->toggleVisibility($id, 1);

        $result = $service->viewForUser($id, 1);

        $this->assertSame($id, $result['recipe']['id']);
    }

    public function testViewForUserThrowsNotFoundForNonExistentRecipe(): void
    {
        $service = $this->makeService();

        $this->expectException(RecipeNotFoundException::class);
        $service->viewForUser(999, null);
    }

    public function testToggleVisibilityRevokesAccessWhenTurnedBackToPrivate(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $service->toggleVisibility($id, 1); // now public
        $service->viewForUser($id, null); // sanity check: accessible

        $service->toggleVisibility($id, 1); // back to private

        $this->expectException(RecipeNotFoundException::class);
        $service->viewForUser($id, null);
    }

    public function testToggleVisibilityReturnsNewValue(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $this->assertTrue($service->toggleVisibility($id, 1));
        $this->assertFalse($service->toggleVisibility($id, 1));
    }

    // --- Ownership enforcement: edit/delete/toggle ---

    public function testUpdateByOwnerSucceeds(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(['name' => 'Original']), []);

        $service->update($id, 1, $this->sampleRecipeData(['name' => 'Updated']), []);

        $result = $service->viewForUser($id, 1);
        $this->assertSame('Updated', $result['recipe']['name']);
    }

    public function testUpdateByNonOwnerThrowsAccessDenied(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $this->expectException(RecipeAccessDeniedException::class);
        $service->update($id, 2, $this->sampleRecipeData(['name' => 'Hijacked']), []);
    }

    public function testUpdatePreservesVisibilityFlag(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);
        $service->toggleVisibility($id, 1); // now public

        $service->update($id, 1, $this->sampleRecipeData(['name' => 'Renamed']), []);

        $result = $service->viewForUser($id, null);
        $this->assertSame(1, $result['recipe']['is_public']);
    }

    public function testDeleteByOwnerRemovesRecipe(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $service->delete($id, 1);

        $this->expectException(RecipeNotFoundException::class);
        $service->viewForUser($id, 1);
    }

    public function testDeleteByNonOwnerThrowsAccessDeniedAndDoesNotDelete(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        try {
            $service->delete($id, 2);
            $this->fail('Expected RecipeAccessDeniedException.');
        } catch (RecipeAccessDeniedException) {
            // expected
        }

        $result = $service->viewForUser($id, 1);
        $this->assertSame($id, $result['recipe']['id']);
    }

    public function testToggleVisibilityByNonOwnerThrowsAccessDenied(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $this->expectException(RecipeAccessDeniedException::class);
        $service->toggleVisibility($id, 2);
    }

    public function testGetForEditByNonOwnerThrowsAccessDenied(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), []);

        $this->expectException(RecipeAccessDeniedException::class);
        $service->getForEdit($id, 2);
    }

    public function testGetForEditByOwnerReturnsRecipeAndIngredients(): void
    {
        $service = $this->makeService();
        $id = $service->create(1, $this->sampleRecipeData(), [
            ['ingredient_type' => 'hop', 'name' => 'Citra', 'quantity' => null, 'unit' => null, 'timing_note' => null],
        ]);

        $result = $service->getForEdit($id, 1);

        $this->assertSame($id, $result['recipe']['id']);
        $this->assertCount(1, $result['ingredients']);
    }

    public function testUpdateOnMissingRecipeThrowsNotFound(): void
    {
        $service = $this->makeService();

        $this->expectException(RecipeNotFoundException::class);
        $service->update(999, 1, $this->sampleRecipeData(), []);
    }

    public function testDeleteOnMissingRecipeThrowsNotFound(): void
    {
        $service = $this->makeService();

        $this->expectException(RecipeNotFoundException::class);
        $service->delete(999, 1);
    }

    public function testToggleVisibilityOnMissingRecipeThrowsNotFound(): void
    {
        $service = $this->makeService();

        $this->expectException(RecipeNotFoundException::class);
        $service->toggleVisibility(999, 1);
    }

    // --- Constrained value-list constants ---

    public function testConstrainedValueListConstants(): void
    {
        $this->assertSame(['L', 'mL', 'gal', 'qt', 'kg', 'g', 'lb', 'oz'], RecipeService::UNITS);
        $this->assertSame(['packet', 'g', 'mL'], RecipeService::YEAST_UNITS);
        $this->assertSame(['table_sugar', 'dextrose', 'honey', 'dme', 'lme', 'other'], RecipeService::SUGAR_TYPES);
        $this->assertSame(['ale', 'lager', 'wine', 'champagne', 'wild', 'other'], RecipeService::YEAST_TYPES);
        $this->assertSame(0.990, RecipeService::TARGET_GRAVITY_MIN);
        $this->assertSame(1.300, RecipeService::TARGET_GRAVITY_MAX);
    }

    public function testRecentPublicExcludesPrivateRecipesAndRespectsLimit(): void
    {
        $service = $this->makeService();
        $id1 = $service->create(1, $this->sampleRecipeData(['name' => 'Public One']), []);
        $id2 = $service->create(1, $this->sampleRecipeData(['name' => 'Public Two']), []);
        $service->create(1, $this->sampleRecipeData(['name' => 'Stays Private']), []);
        $service->toggleVisibility($id1, 1);
        $service->toggleVisibility($id2, 1);

        $recentAll = $service->recentPublic(10);
        $recentLimited = $service->recentPublic(1);

        $this->assertCount(2, $recentAll, 'Only the two public recipes should be returned.');
        $names = array_column($recentAll, 'name');
        $this->assertNotContains('Stays Private', $names);
        $this->assertCount(1, $recentLimited, 'The limit argument must be respected.');
    }
}
