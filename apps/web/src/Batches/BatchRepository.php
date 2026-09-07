<?php

declare(strict_types=1);

namespace App\Batches;

use App\Db\DbInterface;

/**
 * PDO-backed (via DbInterface) access to the `batches` table.
 * Mirrors App\Recipes\RecipeRepository's conventions.
 */
final class BatchRepository
{
    /** @var list<string> */
    private const COLUMNS = [
        'recipe_id',
        'user_id',
        'label',
        'status',
        'started_at',
        'is_public',
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
            'INSERT INTO batches (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
            $this->bindValues($columns, $data),
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM batches WHERE id = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByUserId(int $userId): array
    {
        return $this->db->fetchAll('SELECT * FROM batches WHERE user_id = ? ORDER BY updated_at DESC', [$userId]);
    }

    /**
     * @param array<string, mixed> $data must contain every key in self::COLUMNS
     */
    public function update(int $id, array $data): void
    {
        $columns = self::COLUMNS;
        $setClause = implode(', ', array_map(static fn (string $c): string => "{$c} = ?", $columns));
        $values = $this->bindValues($columns, $data);
        $values[] = $id;

        $this->db->execute("UPDATE batches SET {$setClause} WHERE id = ?", $values);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM batches WHERE id = ?', [$id]);
    }

    public function setIsPublic(int $id, bool $isPublic): void
    {
        $this->db->execute('UPDATE batches SET is_public = ? WHERE id = ?', [$isPublic ? 1 : 0, $id]);
    }

    /**
     * @param list<string> $columns
     * @param array<string, mixed> $data
     * @return list<mixed>
     */
    private function bindValues(array $columns, array $data): array
    {
        return array_map(static function (string $column) use ($data): mixed {
            $value = $data[$column] ?? null;

            // Normalize booleans to 0/1 explicitly rather than relying on
            // PDO's (driver-dependent) bool-to-string coercion.
            if ($column === 'is_public') {
                return $value ? 1 : 0;
            }

            return $value;
        }, $columns);
    }
}
