<?php

declare(strict_types=1);

namespace App\Batches;

use App\Batches\Exception\BatchAccessDeniedException;
use App\Batches\Exception\BatchNotFoundException;
use App\Recipes\Exception\RecipeAccessDeniedException;
use App\Recipes\Exception\RecipeNotFoundException;
use App\Recipes\RecipeRepository;

/**
 * Batch CRUD, diary log-entry CRUD, ownership enforcement, and the
 * dual-mode visibility access pattern shared by view routes across the
 * app (identical to App\Recipes\RecipeService): the owner always has full
 * access; any other authenticated user or anonymous visitor gets
 * read-only access iff `is_public`, otherwise a "not found" (never a
 * "forbidden" — see BatchNotFoundException's docblock).
 */
final class BatchService
{
    /** @var list<string> */
    public const STATUSES = ['planning', 'fermenting', 'conditioning', 'bottled', 'completed', 'archived'];

    public function __construct(
        private readonly BatchRepository $batches,
        private readonly BatchLogEntryRepository $logEntries,
        private readonly RecipeRepository $recipes,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOwn(int $userId): array
    {
        return $this->batches->findAllByUserId($userId);
    }

    /**
     * Recipes owned by the given user, for populating the "create batch
     * under which recipe" dropdown.
     *
     * @return list<array<string, mixed>>
     */
    public function recipesOwnedBy(int $userId): array
    {
        return $this->recipes->findAllByUserId($userId);
    }

    /**
     * Creates a batch. The given recipe id must belong to the creating
     * user — a user cannot create a batch under someone else's recipe.
     * Reuses RecipeNotFoundException/RecipeAccessDeniedException (rather
     * than inventing batch-specific equivalents for this check) since the
     * thing being validated here is recipe ownership, not batch
     * ownership, and callers already know how to map those two exceptions
     * to 404/403 respectively.
     *
     * @param array<string, mixed> $data
     */
    public function create(int $userId, int $recipeId, array $data): int
    {
        $recipe = $this->recipes->findById($recipeId);

        if ($recipe === null) {
            throw new RecipeNotFoundException("Recipe {$recipeId} not found.");
        }

        if ((int) $recipe['user_id'] !== $userId) {
            throw new RecipeAccessDeniedException("User {$userId} does not own recipe {$recipeId}.");
        }

        return $this->batches->insert([
            ...$data,
            'recipe_id' => $recipeId,
            'user_id' => $userId,
            'is_public' => false,
        ]);
    }

    /**
     * @return array{batch: array<string, mixed>, logEntries: list<array<string, mixed>>}
     */
    public function viewForUser(int $batchId, ?int $viewerUserId): array
    {
        $batch = $this->batches->findById($batchId);

        if ($batch === null || !$this->isViewable($batch, $viewerUserId)) {
            throw new BatchNotFoundException("Batch {$batchId} not found.");
        }

        return [
            'batch' => $batch,
            'logEntries' => $this->logEntries->findAllByBatchId($batchId),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getForEdit(int $batchId, int $userId): array
    {
        return $this->requireOwned($batchId, $userId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function update(int $batchId, int $userId, array $data): void
    {
        $batch = $this->requireOwned($batchId, $userId);

        $this->batches->update($batchId, [
            ...$data,
            'recipe_id' => (int) $batch['recipe_id'],
            'user_id' => (int) $batch['user_id'],
            'is_public' => (bool) $batch['is_public'],
        ]);
    }

    /**
     * Deletes a batch. Its log entries are removed via the
     * `batch_log_entries.batch_id` foreign key's `ON DELETE CASCADE`
     * (db/migrations/0006_create_batch_log_entries_table.sql) rather than
     * an explicit delete here — the same approach RecipeService takes for
     * recipe_ingredients when a recipe is deleted.
     */
    public function delete(int $batchId, int $userId): void
    {
        $this->requireOwned($batchId, $userId);
        $this->batches->delete($batchId);
    }

    /**
     * Flips is_public and returns the new value.
     */
    public function toggleVisibility(int $batchId, int $userId): bool
    {
        $batch = $this->requireOwned($batchId, $userId);
        $newValue = !((bool) $batch['is_public']);
        $this->batches->setIsPublic($batchId, $newValue);

        return $newValue;
    }

    /**
     * @param array<string, mixed> $data
     */
    public function addLogEntry(int $batchId, int $userId, array $data): int
    {
        $this->requireOwned($batchId, $userId);

        return $this->logEntries->insert([...$data, 'batch_id' => $batchId]);
    }

    /**
     * @return array<string, mixed>
     */
    public function getLogEntryForEdit(int $batchId, int $logEntryId, int $userId): array
    {
        $this->requireOwned($batchId, $userId);

        return $this->requireLogEntryBelongsToBatch($batchId, $logEntryId);
    }

    /**
     * @param array<string, mixed> $data
     */
    public function updateLogEntry(int $batchId, int $logEntryId, int $userId, array $data): void
    {
        $this->requireOwned($batchId, $userId);
        $this->requireLogEntryBelongsToBatch($batchId, $logEntryId);

        $this->logEntries->update($logEntryId, [...$data, 'batch_id' => $batchId]);
    }

    public function deleteLogEntry(int $batchId, int $logEntryId, int $userId): void
    {
        $this->requireOwned($batchId, $userId);
        $this->requireLogEntryBelongsToBatch($batchId, $logEntryId);

        $this->logEntries->delete($logEntryId);
    }

    /**
     * The "day N" figure for a diary entry: the whole-day difference
     * between the entry's date and the batch's started_at date, computed
     * at render/query time (never stored, so it can't drift if
     * started_at is edited later — see the migration's docblock).
     *
     * Day 0 is the start date itself. An entry dated before started_at
     * (e.g. a backdated pre-pitch measurement) yields a negative number
     * rather than erroring — a sane, informative value for a user who
     * intentionally logged something before the batch officially started.
     *
     * @param array<string, mixed> $batch must contain a 'started_at' key
     */
    public function dayNumberFor(array $batch, \DateTimeInterface $entryDate): int
    {
        $startedAt = new \DateTimeImmutable(substr((string) $batch['started_at'], 0, 10));
        $entry = new \DateTimeImmutable($entryDate->format('Y-m-d'));

        $diff = $startedAt->diff($entry);

        return (int) $diff->format('%r%a');
    }

    /**
     * @param array<string, mixed> $batch
     */
    private function isViewable(array $batch, ?int $viewerUserId): bool
    {
        if ($viewerUserId !== null && (int) $batch['user_id'] === $viewerUserId) {
            return true;
        }

        return (bool) $batch['is_public'];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwned(int $batchId, int $userId): array
    {
        $batch = $this->batches->findById($batchId);

        if ($batch === null) {
            throw new BatchNotFoundException("Batch {$batchId} not found.");
        }

        if ((int) $batch['user_id'] !== $userId) {
            throw new BatchAccessDeniedException("User {$userId} does not own batch {$batchId}.");
        }

        return $batch;
    }

    /**
     * @return array<string, mixed>
     */
    private function requireLogEntryBelongsToBatch(int $batchId, int $logEntryId): array
    {
        $entry = $this->logEntries->findById($logEntryId);

        if ($entry === null || (int) $entry['batch_id'] !== $batchId) {
            throw new BatchNotFoundException("Log entry {$logEntryId} not found on batch {$batchId}.");
        }

        return $entry;
    }
}
