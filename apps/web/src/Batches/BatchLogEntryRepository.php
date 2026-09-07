<?php

declare(strict_types=1);

namespace App\Batches;

use App\Db\DbInterface;

/**
 * PDO-backed (via DbInterface) access to the `batch_log_entries` table —
 * the dated diary entries under a batch. Unlike
 * App\Recipes\RecipeIngredientRepository's bulk delete-then-reinsert
 * approach (suited to a small fixed repeatable-row form), log entries are
 * individually managed CRUD rows (add/edit/delete one at a time), so this
 * repository exposes per-row insert/update/delete, mirroring
 * App\Recipes\RecipeRepository's shape instead.
 */
final class BatchLogEntryRepository
{
    /** @var list<string> */
    private const COLUMNS = [
        'batch_id',
        'entry_date',
        'note',
        'specific_gravity',
        'acidity_ph',
        'temperature',
        'temperature_unit',
        'stage_vessel',
    ];

    public function __construct(private readonly DbInterface $db)
    {
    }

    /**
     * @param array<string, mixed> $data must contain every key in self::COLUMNS
     */
    public function insert(array $data): int
    {
        $columns = self::COLUMNS;
        $placeholders = implode(', ', array_fill(0, count($columns), '?'));

        $this->db->execute(
            'INSERT INTO batch_log_entries (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
            array_map(static fn (string $c): mixed => $data[$c] ?? null, $columns),
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM batch_log_entries WHERE id = ?', [$id]);
    }

    /**
     * Ordered ascending by entry_date, regardless of insertion order, so
     * the diary timeline always reads chronologically.
     *
     * @return list<array<string, mixed>>
     */
    public function findAllByBatchId(int $batchId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM batch_log_entries WHERE batch_id = ? ORDER BY entry_date ASC',
            [$batchId],
        );
    }

    /**
     * @param array<string, mixed> $data must contain every key in self::COLUMNS
     */
    public function update(int $id, array $data): void
    {
        $columns = self::COLUMNS;
        $setClause = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", $columns));
        $values = array_map(static fn (string $c): mixed => $data[$c] ?? null, $columns);
        $values[] = $id;

        $this->db->execute("UPDATE batch_log_entries SET {$setClause} WHERE id = ?", $values);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM batch_log_entries WHERE id = ?', [$id]);
    }
}
