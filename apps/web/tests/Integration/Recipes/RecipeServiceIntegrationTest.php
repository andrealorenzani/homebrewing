<?php

declare(strict_types=1);

namespace Tests\Integration\Recipes;

use App\Recipes\Exception\RecipeAccessDeniedException;
use App\Recipes\Exception\RecipeNotFoundException;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;
use Tests\Integration\IntegrationTestCase;

/**
 * Exercises RecipeService/RecipeRepository/RecipeIngredientRepository
 * against the real Dockerized MySQL test database (task V4), including
 * the full owner/other-user/anonymous x public/private visibility matrix
 * that is the single highest-risk area of this plan.
 */
final class RecipeServiceIntegrationTest extends IntegrationTestCase
{
    private RecipeService $service;
    private int $userA;
    private int $userB;

    protected function setUp(): void
    {
        parent::setUp();

        // Start from a clean slate; ON DELETE CASCADE takes recipes and
        // recipe_ingredients with it.
        self::db()->execute('DELETE FROM users');

        $this->service = new RecipeService(
            self::db(),
            new RecipeRepository(self::db()),
            new RecipeIngredientRepository(self::db()),
        );

        $this->userA = $this->insertUser('alice');
        $this->userB = $this->insertUser('bob');
    }

    private function insertUser(string $username): int
    {
        self::db()->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            [$username, $username . '@example.com', password_hash('irrelevant', PASSWORD_DEFAULT)],
        );

        return (int) self::db()->lastInsertId();
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

    public function testCreateAndRetrieveRecipeWithIngredientsAgainstRealMysql(): void
    {
        $id = $this->service->create($this->userA, $this->sampleRecipeData(), [
            ['ingredient_type' => 'hop', 'name' => 'Citra', 'quantity' => '30.00', 'unit' => 'g', 'timing_note' => 'boil'],
        ]);

        $result = $this->service->viewForUser($id, $this->userA);

        $this->assertSame('Session IPA', $result['recipe']['name']);
        $this->assertCount(1, $result['ingredients']);
        $this->assertSame('Citra', $result['ingredients'][0]['name']);
    }

    public function testUserBCannotEditUserAsRecipe(): void
    {
        $id = $this->service->create($this->userA, $this->sampleRecipeData(), []);

        $this->expectException(RecipeAccessDeniedException::class);
        $this->service->update($id, $this->userB, $this->sampleRecipeData(['name' => 'Hijacked']), []);
    }

    public function testUserBCannotDeleteUserAsRecipe(): void
    {
        $id = $this->service->create($this->userA, $this->sampleRecipeData(), []);

        try {
            $this->service->delete($id, $this->userB);
            $this->fail('Expected RecipeAccessDeniedException.');
        } catch (RecipeAccessDeniedException) {
            // expected
        }

        // Still there afterward.
        $this->assertNotNull($this->service->viewForUser($id, $this->userA));
    }

    public function testUserBCannotToggleUserAsRecipeVisibility(): void
    {
        $id = $this->service->create($this->userA, $this->sampleRecipeData(), []);

        $this->expectException(RecipeAccessDeniedException::class);
        $this->service->toggleVisibility($id, $this->userB);
    }

    public function testPrivateRecipe404sForAnonymousAndOtherUser(): void
    {
        $id = $this->service->create($this->userA, $this->sampleRecipeData(), []);

        $anonymousFailed = false;
        $otherUserFailed = false;

        try {
            $this->service->viewForUser($id, null);
        } catch (RecipeNotFoundException) {
            $anonymousFailed = true;
        }

        try {
            $this->service->viewForUser($id, $this->userB);
        } catch (RecipeNotFoundException) {
            $otherUserFailed = true;
        }

        $this->assertTrue($anonymousFailed, 'Anonymous visitor must not see a private recipe.');
        $this->assertTrue($otherUserFailed, 'Another logged-in user must not see a private recipe.');
    }

    public function testPublicRecipeIsViewableByAnonymousAndOtherUser(): void
    {
        $id = $this->service->create($this->userA, $this->sampleRecipeData(), []);
        $this->service->toggleVisibility($id, $this->userA);

        $anonResult = $this->service->viewForUser($id, null);
        $otherResult = $this->service->viewForUser($id, $this->userB);

        $this->assertSame($id, $anonResult['recipe']['id']);
        $this->assertSame($id, $otherResult['recipe']['id']);
    }

    public function testTogglingVisibilityChangesReadAccessEndToEnd(): void
    {
        $id = $this->service->create($this->userA, $this->sampleRecipeData(), []);

        // Starts private: hidden from anonymous.
        $hiddenBeforeToggle = false;

        try {
            $this->service->viewForUser($id, null);
        } catch (RecipeNotFoundException) {
            $hiddenBeforeToggle = true;
        }

        $this->assertTrue($hiddenBeforeToggle, 'Recipe should start private and hidden from anonymous visitors.');

        // Make public: now visible.
        $this->service->toggleVisibility($id, $this->userA);
        $visibleResult = $this->service->viewForUser($id, null);
        $this->assertSame($id, $visibleResult['recipe']['id']);

        // Make private again: access revoked.
        $this->service->toggleVisibility($id, $this->userA);

        $hiddenAfterToggleBack = false;

        try {
            $this->service->viewForUser($id, null);
        } catch (RecipeNotFoundException) {
            $hiddenAfterToggleBack = true;
        }

        $this->assertTrue($hiddenAfterToggleBack, 'Toggling back to private must revoke anonymous read access.');
    }

    public function testOwnerAlwaysSeesOwnRecipeRegardlessOfVisibility(): void
    {
        $id = $this->service->create($this->userA, $this->sampleRecipeData(), []);

        $privateResult = $this->service->viewForUser($id, $this->userA);
        $this->assertSame($id, $privateResult['recipe']['id']);

        $this->service->toggleVisibility($id, $this->userA);
        $publicResult = $this->service->viewForUser($id, $this->userA);
        $this->assertSame($id, $publicResult['recipe']['id']);
    }

    public function testListOwnOnlyReturnsThatUsersRecipesAgainstRealMysql(): void
    {
        $this->service->create($this->userA, $this->sampleRecipeData(['name' => 'Alices Recipe']), []);
        $this->service->create($this->userB, $this->sampleRecipeData(['name' => 'Bobs Recipe']), []);

        $ownRecipes = $this->service->listOwn($this->userA);

        $this->assertCount(1, $ownRecipes);
        $this->assertSame('Alices Recipe', $ownRecipes[0]['name']);
    }
}
