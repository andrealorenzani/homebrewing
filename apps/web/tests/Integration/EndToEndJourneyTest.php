<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Auth\AuthService;
use App\Auth\UserRepository;
use App\Batches\BatchLogEntryRepository;
use App\Batches\BatchRepository;
use App\Batches\BatchService;
use App\Batches\Exception\BatchNotFoundException;
use App\Http\Session\ArraySession;
use App\Recipes\Exception\RecipeNotFoundException;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;

/**
 * End-to-end smoke test (task V9) proving the seams between
 * Auth/Recipes/Batches actually work together against the real
 * Dockerized MySQL test database, rather than re-testing branch-by-branch
 * logic already covered in depth by
 * tests/Integration/Recipes/RecipeServiceIntegrationTest.php and
 * tests/Integration/Batches/BatchServiceIntegrationTest.php.
 *
 * Journey: register a user -> create a private recipe -> confirm it is
 * hidden from an anonymous visitor and another logged-in user, and absent
 * from the homepage's public recipe list -> make it public -> confirm the
 * opposite for all three -> create a batch under it -> add, edit, and
 * delete diary log entries -> confirm the batch starts private (404 for
 * anonymous/other user) -> make the batch public -> confirm it becomes
 * visible to both.
 */
final class EndToEndJourneyTest extends IntegrationTestCase
{
    private AuthService $auth;
    private RecipeService $recipes;
    private BatchService $batches;

    protected function setUp(): void
    {
        parent::setUp();

        // Start from a clean slate; ON DELETE CASCADE takes recipes,
        // recipe_ingredients, batches, and batch_log_entries with it.
        self::db()->execute('DELETE FROM users');

        $this->auth = new AuthService(new UserRepository(self::db()), new ArraySession());
        $this->recipes = new RecipeService(
            self::db(),
            new RecipeRepository(self::db()),
            new RecipeIngredientRepository(self::db()),
        );
        $this->batches = new BatchService(
            new BatchRepository(self::db()),
            new BatchLogEntryRepository(self::db()),
            new RecipeRepository(self::db()),
        );
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

    public function testFullRecipeAndBatchJourneyAgainstRealMysql(): void
    {
        // --- Register the recipe owner and a second, unrelated user. ---
        $owner = $this->auth->register('journey_owner', 'journey_owner@example.com', 'correct-horse-battery');
        $otherUser = $this->auth->register('journey_other', 'journey_other@example.com', 'correct-horse-battery');
        $ownerId = (int) $owner['id'];
        $otherUserId = (int) $otherUser['id'];

        // --- Create a recipe; it must be private by default. ---
        $recipeId = $this->recipes->create($ownerId, $this->sampleRecipeData(), []);
        $ownerView = $this->recipes->viewForUser($recipeId, $ownerId);
        $this->assertSame(0, (int) $ownerView['recipe']['is_public'], 'A newly created recipe must be private by default.');

        // --- Private recipe: hidden from anonymous and other users, absent from the homepage list. ---
        $this->assertRecipeHiddenFrom($recipeId, null);
        $this->assertRecipeHiddenFrom($recipeId, $otherUserId);
        $this->assertNotContains($recipeId, array_column($this->recipes->recentPublic(50), 'id'), 'A private recipe must not appear in the homepage public listing.');

        // --- Toggle the recipe public. ---
        $this->recipes->toggleVisibility($recipeId, $ownerId);

        // --- Public recipe: visible to anonymous and other users, present on the homepage. ---
        $this->assertSame($recipeId, $this->recipes->viewForUser($recipeId, null)['recipe']['id']);
        $this->assertSame($recipeId, $this->recipes->viewForUser($recipeId, $otherUserId)['recipe']['id']);
        $this->assertContains($recipeId, array_column($this->recipes->recentPublic(50), 'id'), 'A public recipe must appear in the homepage public listing.');

        // --- Create a batch under the now-public recipe. ---
        $batchId = $this->batches->create($ownerId, $recipeId, [
            'label' => 'First Brew Day',
            'status' => 'planning',
            'started_at' => '2026-01-01',
        ]);

        // --- The batch itself starts private, independent of its recipe's visibility. ---
        $this->assertBatchHiddenFrom($batchId, null);
        $this->assertBatchHiddenFrom($batchId, $otherUserId);

        // --- Diary log entries: add two, edit one, delete one. ---
        $firstEntryId = $this->batches->addLogEntry($batchId, $ownerId, [
            'entry_date' => '2026-01-02',
            'note' => 'Pitched yeast.',
        ]);
        $secondEntryId = $this->batches->addLogEntry($batchId, $ownerId, [
            'entry_date' => '2026-01-05',
            'note' => 'Fermentation slowing.',
        ]);

        $this->batches->updateLogEntry($batchId, $firstEntryId, $ownerId, [
            'entry_date' => '2026-01-02',
            'note' => 'Pitched yeast at 20C.',
        ]);
        $this->batches->deleteLogEntry($batchId, $secondEntryId, $ownerId);

        $ownerBatchView = $this->batches->viewForUser($batchId, $ownerId);
        $this->assertCount(1, $ownerBatchView['logEntries'], 'Exactly one log entry should remain after adding two and deleting one.');
        $this->assertSame('Pitched yeast at 20C.', $ownerBatchView['logEntries'][0]['note']);

        // --- Toggle the batch public. ---
        $this->batches->toggleVisibility($batchId, $ownerId);

        // --- Public batch: now visible to anonymous and other users. ---
        $anonBatchView = $this->batches->viewForUser($batchId, null);
        $otherBatchView = $this->batches->viewForUser($batchId, $otherUserId);
        $this->assertSame($batchId, $anonBatchView['batch']['id']);
        $this->assertSame($batchId, $otherBatchView['batch']['id']);
        $this->assertCount(1, $anonBatchView['logEntries']);
    }

    private function assertRecipeHiddenFrom(int $recipeId, ?int $viewerUserId): void
    {
        try {
            $this->recipes->viewForUser($recipeId, $viewerUserId);
            $this->fail('Expected RecipeNotFoundException for a private recipe.');
        } catch (RecipeNotFoundException) {
            // expected
        }
    }

    private function assertBatchHiddenFrom(int $batchId, ?int $viewerUserId): void
    {
        try {
            $this->batches->viewForUser($batchId, $viewerUserId);
            $this->fail('Expected BatchNotFoundException for a private batch.');
        } catch (BatchNotFoundException) {
            // expected
        }
    }
}
