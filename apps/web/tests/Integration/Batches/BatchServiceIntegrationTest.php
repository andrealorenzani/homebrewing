<?php

declare(strict_types=1);

namespace Tests\Integration\Batches;

use App\Batches\BatchLogEntryRepository;
use App\Batches\BatchRepository;
use App\Batches\BatchService;
use App\Batches\Exception\BatchAccessDeniedException;
use App\Batches\Exception\BatchNotFoundException;
use App\Recipes\Exception\RecipeAccessDeniedException;
use App\Recipes\Exception\RecipeNotFoundException;
use App\Recipes\RecipeIngredientRepository;
use App\Recipes\RecipeRepository;
use App\Recipes\RecipeService;
use Tests\Integration\IntegrationTestCase;

/**
 * Exercises BatchService/BatchRepository/BatchLogEntryRepository against
 * the real Dockerized MySQL test database (task V4), including the full
 * owner/other-user/anonymous x public/private visibility matrix, the
 * recipe-ownership check at batch-creation time, day-number computation,
 * and log-entry cascade deletion — mirrors
 * tests/Integration/Recipes/RecipeServiceIntegrationTest.php.
 */
final class BatchServiceIntegrationTest extends IntegrationTestCase
{
    private BatchService $service;
    private RecipeService $recipeService;
    private int $userA;
    private int $userB;
    private int $recipeA;

    protected function setUp(): void
    {
        parent::setUp();

        // Start from a clean slate; ON DELETE CASCADE takes recipes,
        // batches, and batch_log_entries with it.
        self::db()->execute('DELETE FROM users');

        $this->recipeService = new RecipeService(
            self::db(),
            new RecipeRepository(self::db()),
            new RecipeIngredientRepository(self::db()),
        );

        $this->service = new BatchService(
            new BatchRepository(self::db()),
            new BatchLogEntryRepository(self::db()),
            new RecipeRepository(self::db()),
        );

        $this->userA = $this->insertUser('alice');
        $this->userB = $this->insertUser('bob');
        $this->recipeA = $this->recipeService->create($this->userA, $this->sampleRecipeData(), []);
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

    private function sampleBatchData(array $overrides = []): array
    {
        return [...[
            'label' => 'Batch 1',
            'status' => 'planning',
            'started_at' => '2026-01-01',
        ], ...$overrides];
    }

    public function testCreateAndRetrieveBatchAgainstRealMysql(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());

        $result = $this->service->viewForUser($id, $this->userA);

        $this->assertSame('Batch 1', $result['batch']['label']);
        $this->assertSame($this->recipeA, $result['batch']['recipe_id']);
    }

    public function testUserCannotCreateBatchUnderAnotherUsersRecipe(): void
    {
        $this->expectException(RecipeAccessDeniedException::class);
        $this->service->create($this->userB, $this->recipeA, $this->sampleBatchData());
    }

    public function testCreateThrowsRecipeNotFoundForNonExistentRecipe(): void
    {
        $this->expectException(RecipeNotFoundException::class);
        $this->service->create($this->userA, 999999, $this->sampleBatchData());
    }

    public function testUserBCannotEditUserAsBatch(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());

        $this->expectException(BatchAccessDeniedException::class);
        $this->service->update($id, $this->userB, $this->sampleBatchData(['label' => 'Hijacked']));
    }

    public function testUserBCannotDeleteUserAsBatch(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());

        try {
            $this->service->delete($id, $this->userB);
            $this->fail('Expected BatchAccessDeniedException.');
        } catch (BatchAccessDeniedException) {
            // expected
        }

        $this->assertNotNull($this->service->viewForUser($id, $this->userA));
    }

    public function testUserBCannotToggleUserAsBatchVisibility(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());

        $this->expectException(BatchAccessDeniedException::class);
        $this->service->toggleVisibility($id, $this->userB);
    }

    public function testUserBCannotAddLogEntryToUserAsBatch(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());

        $this->expectException(BatchAccessDeniedException::class);
        $this->service->addLogEntry($id, $this->userB, ['entry_date' => '2026-01-05']);
    }

    public function testPrivateBatch404sForAnonymousAndOtherUser(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());

        $anonymousFailed = false;
        $otherUserFailed = false;

        try {
            $this->service->viewForUser($id, null);
        } catch (BatchNotFoundException) {
            $anonymousFailed = true;
        }

        try {
            $this->service->viewForUser($id, $this->userB);
        } catch (BatchNotFoundException) {
            $otherUserFailed = true;
        }

        $this->assertTrue($anonymousFailed, 'Anonymous visitor must not see a private batch.');
        $this->assertTrue($otherUserFailed, 'Another logged-in user must not see a private batch.');
    }

    public function testPublicBatchIsViewableByAnonymousAndOtherUserIncludingLogTimeline(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());
        $this->service->addLogEntry($id, $this->userA, ['entry_date' => '2026-01-05', 'note' => 'Bubbling.']);
        $this->service->toggleVisibility($id, $this->userA);

        $anonResult = $this->service->viewForUser($id, null);
        $otherResult = $this->service->viewForUser($id, $this->userB);

        $this->assertSame($id, $anonResult['batch']['id']);
        $this->assertCount(1, $anonResult['logEntries']);
        $this->assertSame($id, $otherResult['batch']['id']);
        $this->assertCount(1, $otherResult['logEntries']);
    }

    public function testTogglingVisibilityChangesReadAccessEndToEnd(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());

        $hiddenBeforeToggle = false;

        try {
            $this->service->viewForUser($id, null);
        } catch (BatchNotFoundException) {
            $hiddenBeforeToggle = true;
        }

        $this->assertTrue($hiddenBeforeToggle, 'Batch should start private and hidden from anonymous visitors.');

        $this->service->toggleVisibility($id, $this->userA);
        $visibleResult = $this->service->viewForUser($id, null);
        $this->assertSame($id, $visibleResult['batch']['id']);

        $this->service->toggleVisibility($id, $this->userA);

        $hiddenAfterToggleBack = false;

        try {
            $this->service->viewForUser($id, null);
        } catch (BatchNotFoundException) {
            $hiddenAfterToggleBack = true;
        }

        $this->assertTrue($hiddenAfterToggleBack, 'Toggling back to private must revoke anonymous read access.');
    }

    public function testOwnerAlwaysSeesOwnBatchRegardlessOfVisibility(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());

        $privateResult = $this->service->viewForUser($id, $this->userA);
        $this->assertSame($id, $privateResult['batch']['id']);

        $this->service->toggleVisibility($id, $this->userA);
        $publicResult = $this->service->viewForUser($id, $this->userA);
        $this->assertSame($id, $publicResult['batch']['id']);
    }

    public function testListOwnOnlyReturnsThatUsersBatchesAgainstRealMysql(): void
    {
        $recipeB = $this->recipeService->create($this->userB, $this->sampleRecipeData(['name' => 'Bobs Recipe']), []);
        $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData(['label' => 'Alices Batch']));
        $this->service->create($this->userB, $recipeB, $this->sampleBatchData(['label' => 'Bobs Batch']));

        $ownBatches = $this->service->listOwn($this->userA);

        $this->assertCount(1, $ownBatches);
        $this->assertSame('Alices Batch', $ownBatches[0]['label']);
    }

    public function testLogEntriesOrderedAscendingByDateRegardlessOfInsertionOrderAgainstRealMysql(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());
        $this->service->addLogEntry($id, $this->userA, ['entry_date' => '2026-01-10']);
        $this->service->addLogEntry($id, $this->userA, ['entry_date' => '2026-01-01']);
        $this->service->addLogEntry($id, $this->userA, ['entry_date' => '2026-01-05']);

        $result = $this->service->viewForUser($id, $this->userA);
        $dates = array_map(static fn (array $e): string => (string) $e['entry_date'], $result['logEntries']);

        $this->assertSame(['2026-01-01', '2026-01-05', '2026-01-10'], $dates);
    }

    public function testDayNumberComputationIncludingPreStartEdgeCase(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData(['started_at' => '2026-01-10']));
        $batch = $this->service->viewForUser($id, $this->userA)['batch'];

        $this->assertSame(0, $this->service->dayNumberFor($batch, new \DateTimeImmutable('2026-01-10')));
        $this->assertSame(5, $this->service->dayNumberFor($batch, new \DateTimeImmutable('2026-01-15')));
        $this->assertSame(-2, $this->service->dayNumberFor($batch, new \DateTimeImmutable('2026-01-08')));
    }

    public function testDeletingBatchCascadeDeletesItsLogEntries(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());
        $this->service->addLogEntry($id, $this->userA, ['entry_date' => '2026-01-05']);

        $this->service->delete($id, $this->userA);

        $row = self::db()->fetchOne('SELECT * FROM batch_log_entries WHERE batch_id = ?', [$id]);
        $this->assertNull($row, 'Log entries must be cascade-deleted along with their batch.');
    }

    public function testUpdateAndDeleteLogEntryAgainstRealMysql(): void
    {
        $id = $this->service->create($this->userA, $this->recipeA, $this->sampleBatchData());
        $entryId = $this->service->addLogEntry($id, $this->userA, ['entry_date' => '2026-01-05', 'note' => 'Original']);

        $this->service->updateLogEntry($id, $entryId, $this->userA, ['entry_date' => '2026-01-06', 'note' => 'Updated']);
        $result = $this->service->viewForUser($id, $this->userA);
        $this->assertSame('Updated', $result['logEntries'][0]['note']);

        $this->service->deleteLogEntry($id, $entryId, $this->userA);
        $result = $this->service->viewForUser($id, $this->userA);
        $this->assertCount(0, $result['logEntries']);
    }
}
