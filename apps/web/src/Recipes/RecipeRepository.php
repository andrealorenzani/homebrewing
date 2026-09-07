<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Db\DbInterface;

/**
 * PDO-backed (via DbInterface) access to the `recipes` table.
 */
final class RecipeRepository
{
    /** @var list<string> */
    private const COLUMNS = [
        'user_id',
        'name',
        'category',
        'description',
        'batch_size',
        'batch_size_unit',
        'water_quantity',
        'water_unit',
        'sugar_quantity',
        'sugar_unit',
        'sugar_type',
        'yeast_type',
        'yeast_quantity',
        'yeast_unit',
        'target_og',
        'target_fg',
        'notes',
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
            'INSERT INTO recipes (' . implode(', ', $columns) . ') VALUES (' . $placeholders . ')',
            $this->bindValues($columns, $data),
        );

        return (int) $this->db->lastInsertId();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM recipes WHERE id = ?', [$id]);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByUserId(int $userId): array
    {
        return $this->db->fetchAll('SELECT * FROM recipes WHERE user_id = ? ORDER BY updated_at DESC', [$userId]);
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

        $this->db->execute("UPDATE recipes SET {$setClause} WHERE id = ?", $values);
    }

    public function delete(int $id): void
    {
        $this->db->execute('DELETE FROM recipes WHERE id = ?', [$id]);
    }

    public function setIsPublic(int $id, bool $isPublic): void
    {
        $this->db->execute('UPDATE recipes SET is_public = ? WHERE id = ?', [$isPublic ? 1 : 0, $id]);
    }

    /**
     * The N most-recently-updated public recipes, across all users — the
     * data behind the homepage showcase. Ordered `updated_at DESC` so a
     * freshly-edited recipe surfaces even if it was created long ago.
     *
     * @return list<array<string, mixed>>
     */
    public function findRecentPublic(int $limit): array
    {
        // LIMIT is embedded as a literal rather than bound as a `?`
        // placeholder: MySQL rejects a quoted-string LIMIT value, and PDO
        // (via DbInterface::execute()'s plain array of params) has no way
        // to mark this one parameter as PDO::PARAM_INT. Safe here because
        // $limit is a strictly-typed int, never raw user/request input.
        $limit = max(0, $limit);

        return $this->db->fetchAll(
            "SELECT * FROM recipes WHERE is_public = ? ORDER BY updated_at DESC LIMIT {$limit}",
            [1],
        );
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
