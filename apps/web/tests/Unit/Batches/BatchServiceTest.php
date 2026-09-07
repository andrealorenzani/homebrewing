<?php

declare(strict_types=1);

namespace Tests\Unit\Batches;

use App\Batches\BatchLogEntryRepository;
use App\Batches\BatchRepository;
use App\Batches\BatchService;
use App\Batches\Exception\BatchAccessDeniedException;
use App\Batches\Exception\BatchNotFoundException;
use App\Db\FakeDb;
use App\Recipes\Exception\RecipeAccessDeniedException;
use App\Recipes\Exception\RecipeNotFoundException;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;
use Tests\TestCase;

final class BatchServiceTest extends TestCase
{
    private function makeService(FakeDb $db): BatchService
    {
        return new BatchService(
            new BatchRepository($db),
            new BatchLogEntryRepository($db),
            new RecipeRepository($db),
        );
    }

    private function makeRecipeService(FakeDb $db): RecipeService
    {
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

    private function sampleBatchData(array $overrides = []): array
    {
        return [...[
            'label' => 'Batch 1',
            'status' => 'planning',
            'started_at' => '2026-01-01',
        ], ...$overrides];
    }

    // --- create() / recipe-ownership validation ---

    public function testCreatePersistsBatchAsPrivateByDefault(): void
    {
        $db = new FakeDb();
        $recipeService = $this->makeRecipeService($db);
        $batchService = $this->makeService($db);
        $recipeId = $recipeService->create(1, $this->sampleRecipeData(), []);

        $id = $batchService->create(1, $recipeId, $this->sampleBatchData());
        $result = $batchService->viewForUser($id, 1);

        $this->assertSame('Batch 1', $result['batch']['label']);
        $this->assertSame(0, $result['batch']['is_public']);
        $this->assertSame(1, $result['batch']['user_id']);
        $this->assertSame($recipeId, $result['batch']['recipe_id']);
    }

    public function testCreateThrowsRecipeNotFoundWhenRecipeDoesNotExist(): void
    {
        $db = new FakeDb();
        $batchService = $this->makeService($db);

        $this->expectException(RecipeNotFoundException::class);
        $batchService->create(1, 999, $this->sampleBatchData());
    }

    public function testCreateThrowsRecipeAccessDeniedWhenRecipeBelongsToAnotherUser(): void
    {
        $db = new FakeDb();
        $recipeService = $this->makeRecipeService($db);
        $batchService = $this->makeService($db);
        $recipeId = $recipeService->create(1, $this->sampleRecipeData(), []);

        $this->expectException(RecipeAccessDeniedException::class);
        $batchService->create(2, $recipeId, $this->sampleBatchData());
    }

    public function testListOwnReturnsOnlyThatUsersBatches(): void
    {
        $db = new FakeDb();
        $recipeService = $this->makeRecipeService($db);
        $batchService = $this->makeService($db);
        $recipeId1 = $recipeService->create(1, $this->sampleRecipeData(), []);
        $recipeId2 = $recipeService->create(2, $this->sampleRecipeData(), []);
        $batchService->create(1, $recipeId1, $this->sampleBatchData(['label' => 'Mine']));
        $batchService->create(2, $recipeId2, $this->sampleBatchData(['label' => 'Not Mine']));

        $batches = $batchService->listOwn(1);

        $this->assertCount(1, $batches);
        $this->assertSame('Mine', $batches[0]['label']);
    }

    public function testRecipesOwnedByReturnsOnlyThatUsersRecipes(): void
    {
        $db = new FakeDb();
        $recipeService = $this->makeRecipeService($db);
        $batchService = $this->makeService($db);
        $recipeService->create(1, $this->sampleRecipeData(['name' => 'Mine']), []);
        $recipeService->create(2, $this->sampleRecipeData(['name' => 'Not Mine']), []);

        $recipes = $batchService->recipesOwnedBy(1);

        $this->assertCount(1, $recipes);
        $this->assertSame('Mine', $recipes[0]['name']);
    }

    // --- Visibility matrix: owner / other user / anonymous x public / private ---

    private function createOwnedBatch(FakeDb $db, int $userId = 1, array $overrides = []): int
    {
        $recipeService = $this->makeRecipeService($db);
        $batchService = $this->makeService($db);
        $recipeId = $recipeService->create($userId, $this->sampleRecipeData(), []);

        return $batchService->create($userId, $recipeId, $this->sampleBatchData($overrides));
    }

    public function testOwnerCanViewOwnPrivateBatch(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $result = $service->viewForUser($id, 1);

        $this->assertSame($id, $result['batch']['id']);
    }

    public function testOtherAuthenticatedUserCannotViewPrivateBatch(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $this->expectException(BatchNotFoundException::class);
        $service->viewForUser($id, 2);
    }

    public function testAnonymousVisitorCannotViewPrivateBatch(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $this->expectException(BatchNotFoundException::class);
        $service->viewForUser($id, null);
    }

    public function testOtherAuthenticatedUserCanViewPublicBatch(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $service->toggleVisibility($id, 1);

        $result = $service->viewForUser($id, 2);

        $this->assertSame($id, $result['batch']['id']);
    }

    public function testAnonymousVisitorCanViewPublicBatch(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $service->toggleVisibility($id, 1);

        $result = $service->viewForUser($id, null);

        $this->assertSame($id, $result['batch']['id']);
    }

    public function testViewForUserThrowsNotFoundForNonExistentBatch(): void
    {
        $db = new FakeDb();
        $service = $this->makeService($db);

        $this->expectException(BatchNotFoundException::class);
        $service->viewForUser(999, null);
    }

    public function testToggleVisibilityRevokesAccessWhenTurnedBackToPrivate(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $service->toggleVisibility($id, 1); // now public
        $service->viewForUser($id, null); // sanity check: accessible

        $service->toggleVisibility($id, 1); // back to private

        $this->expectException(BatchNotFoundException::class);
        $service->viewForUser($id, null);
    }

    public function testToggleVisibilityReturnsNewValue(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $this->assertTrue($service->toggleVisibility($id, 1));
        $this->assertFalse($service->toggleVisibility($id, 1));
    }

    // --- Ownership enforcement: edit/delete/toggle ---

    public function testUpdateByOwnerSucceeds(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db, 1, ['label' => 'Original']);
        $service = $this->makeService($db);

        $service->update($id, 1, $this->sampleBatchData(['label' => 'Updated']));

        $result = $service->viewForUser($id, 1);
        $this->assertSame('Updated', $result['batch']['label']);
    }

    public function testUpdateByNonOwnerThrowsAccessDenied(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $this->expectException(BatchAccessDeniedException::class);
        $service->update($id, 2, $this->sampleBatchData(['label' => 'Hijacked']));
    }

    public function testUpdatePreservesVisibilityAndRecipeId(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $service->toggleVisibility($id, 1); // now public
        $before = $service->viewForUser($id, 1)['batch'];

        $service->update($id, 1, $this->sampleBatchData(['label' => 'Renamed']));

        $result = $service->viewForUser($id, null);
        $this->assertSame(1, $result['batch']['is_public']);
        $this->assertSame($before['recipe_id'], $result['batch']['recipe_id']);
    }

    public function testDeleteByOwnerRemovesBatch(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $service->delete($id, 1);

        $this->expectException(BatchNotFoundException::class);
        $service->viewForUser($id, 1);
    }

    public function testDeleteByNonOwnerThrowsAccessDeniedAndDoesNotDelete(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        try {
            $service->delete($id, 2);
            $this->fail('Expected BatchAccessDeniedException.');
        } catch (BatchAccessDeniedException) {
            // expected
        }

        $result = $service->viewForUser($id, 1);
        $this->assertSame($id, $result['batch']['id']);
    }

    public function testToggleVisibilityByNonOwnerThrowsAccessDenied(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $this->expectException(BatchAccessDeniedException::class);
        $service->toggleVisibility($id, 2);
    }

    public function testGetForEditByNonOwnerThrowsAccessDenied(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $this->expectException(BatchAccessDeniedException::class);
        $service->getForEdit($id, 2);
    }

    public function testGetForEditByOwnerReturnsBatch(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $batch = $service->getForEdit($id, 1);

        $this->assertSame($id, $batch['id']);
    }

    public function testUpdateOnMissingBatchThrowsNotFound(): void
    {
        $db = new FakeDb();
        $service = $this->makeService($db);

        $this->expectException(BatchNotFoundException::class);
        $service->update(999, 1, $this->sampleBatchData());
    }

    public function testDeleteOnMissingBatchThrowsNotFound(): void
    {
        $db = new FakeDb();
        $service = $this->makeService($db);

        $this->expectException(BatchNotFoundException::class);
        $service->delete(999, 1);
    }

    public function testToggleVisibilityOnMissingBatchThrowsNotFound(): void
    {
        $db = new FakeDb();
        $service = $this->makeService($db);

        $this->expectException(BatchNotFoundException::class);
        $service->toggleVisibility(999, 1);
    }

    // --- Log entry CRUD + ownership ---

    public function testAddLogEntryByOwnerSucceeds(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $entryId = $service->addLogEntry($id, 1, [
            'entry_date' => '2026-01-05',
            'note' => 'Bubbling nicely.',
            'specific_gravity' => '1.020',
            'acidity_ph' => null,
            'temperature' => null,
            'temperature_unit' => null,
            'stage_vessel' => null,
        ]);

        $result = $service->viewForUser($id, 1);
        $this->assertCount(1, $result['logEntries']);
        $this->assertSame($entryId, $result['logEntries'][0]['id']);
        $this->assertSame('Bubbling nicely.', $result['logEntries'][0]['note']);
    }

    public function testAddLogEntryByNonOwnerThrowsAccessDenied(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $this->expectException(BatchAccessDeniedException::class);
        $service->addLogEntry($id, 2, ['entry_date' => '2026-01-05']);
    }

    public function testAddLogEntryOnMissingBatchThrowsNotFound(): void
    {
        $db = new FakeDb();
        $service = $this->makeService($db);

        $this->expectException(BatchNotFoundException::class);
        $service->addLogEntry(999, 1, ['entry_date' => '2026-01-05']);
    }

    public function testLogEntriesRenderInAscendingDateOrderRegardlessOfInsertionOrder(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $service->addLogEntry($id, 1, ['entry_date' => '2026-01-10']);
        $service->addLogEntry($id, 1, ['entry_date' => '2026-01-01']);
        $service->addLogEntry($id, 1, ['entry_date' => '2026-01-05']);

        $result = $service->viewForUser($id, 1);
        $dates = array_column($result['logEntries'], 'entry_date');

        $this->assertSame(['2026-01-01', '2026-01-05', '2026-01-10'], $dates);
    }

    public function testUpdateLogEntryByOwnerSucceeds(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $entryId = $service->addLogEntry($id, 1, ['entry_date' => '2026-01-05', 'note' => 'Original']);

        $service->updateLogEntry($id, $entryId, 1, ['entry_date' => '2026-01-06', 'note' => 'Updated']);

        $result = $service->viewForUser($id, 1);
        $this->assertSame('Updated', $result['logEntries'][0]['note']);
        $this->assertSame('2026-01-06', $result['logEntries'][0]['entry_date']);
    }

    public function testUpdateLogEntryByNonOwnerThrowsAccessDenied(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $entryId = $service->addLogEntry($id, 1, ['entry_date' => '2026-01-05']);

        $this->expectException(BatchAccessDeniedException::class);
        $service->updateLogEntry($id, $entryId, 2, ['entry_date' => '2026-01-06']);
    }

    public function testUpdateLogEntryThrowsNotFoundWhenEntryBelongsToDifferentBatch(): void
    {
        $db = new FakeDb();
        $batchId1 = $this->createOwnedBatch($db, 1, ['label' => 'Batch A']);
        $batchId2 = $this->createOwnedBatch($db, 1, ['label' => 'Batch B']);
        $service = $this->makeService($db);
        $entryId = $service->addLogEntry($batchId1, 1, ['entry_date' => '2026-01-05']);

        $this->expectException(BatchNotFoundException::class);
        $service->updateLogEntry($batchId2, $entryId, 1, ['entry_date' => '2026-01-06']);
    }

    public function testDeleteLogEntryByOwnerSucceeds(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $entryId = $service->addLogEntry($id, 1, ['entry_date' => '2026-01-05']);

        $service->deleteLogEntry($id, $entryId, 1);

        $result = $service->viewForUser($id, 1);
        $this->assertCount(0, $result['logEntries']);
    }

    public function testDeleteLogEntryByNonOwnerThrowsAccessDeniedAndDoesNotDelete(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $entryId = $service->addLogEntry($id, 1, ['entry_date' => '2026-01-05']);

        try {
            $service->deleteLogEntry($id, $entryId, 2);
            $this->fail('Expected BatchAccessDeniedException.');
        } catch (BatchAccessDeniedException) {
            // expected
        }

        $result = $service->viewForUser($id, 1);
        $this->assertCount(1, $result['logEntries']);
    }

    public function testGetLogEntryForEditByOwnerReturnsEntry(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $entryId = $service->addLogEntry($id, 1, ['entry_date' => '2026-01-05']);

        $entry = $service->getLogEntryForEdit($id, $entryId, 1);

        $this->assertSame($entryId, $entry['id']);
    }

    public function testGetLogEntryForEditByNonOwnerThrowsAccessDenied(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);
        $entryId = $service->addLogEntry($id, 1, ['entry_date' => '2026-01-05']);

        $this->expectException(BatchAccessDeniedException::class);
        $service->getLogEntryForEdit($id, $entryId, 2);
    }

    public function testDeleteLogEntryThrowsNotFoundForMissingEntry(): void
    {
        $db = new FakeDb();
        $id = $this->createOwnedBatch($db);
        $service = $this->makeService($db);

        $this->expectException(BatchNotFoundException::class);
        $service->deleteLogEntry($id, 999, 1);
    }

    // --- Day-number computation ---

    public function testDayNumberForEntryOnStartDateIsZero(): void
    {
        $service = $this->makeService(new FakeDb());
        $batch = ['started_at' => '2026-01-01'];

        $this->assertSame(0, $service->dayNumberFor($batch, new \DateTimeImmutable('2026-01-01')));
    }

    public function testDayNumberForEntryAfterStartDateIsPositive(): void
    {
        $service = $this->makeService(new FakeDb());
        $batch = ['started_at' => '2026-01-01'];

        $this->assertSame(5, $service->dayNumberFor($batch, new \DateTimeImmutable('2026-01-06')));
    }

    public function testDayNumberForEntryBeforeStartDateIsNegative(): void
    {
        $service = $this->makeService(new FakeDb());
        $batch = ['started_at' => '2026-01-10'];

        $this->assertSame(-3, $service->dayNumberFor($batch, new \DateTimeImmutable('2026-01-07')));
    }

    public function testDayNumberIgnoresTimeComponentOfStartedAt(): void
    {
        $service = $this->makeService(new FakeDb());
        // started_at from the DB is a DATE column, but guard against any
        // accidental datetime string still being handled correctly.
        $batch = ['started_at' => '2026-01-01 00:00:00'];

        $this->assertSame(1, $service->dayNumberFor($batch, new \DateTimeImmutable('2026-01-02')));
    }
}
