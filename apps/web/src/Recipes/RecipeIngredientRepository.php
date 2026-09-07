<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Db\DbInterface;

/**
 * PDO-backed (via DbInterface) access to the `recipe_ingredients` table.
 */
final class RecipeIngredientRepository
{
    public function __construct(private readonly DbInterface $db)
    {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function findAllByRecipeId(int $recipeId): array
    {
        return $this->db->fetchAll(
            'SELECT * FROM recipe_ingredients WHERE recipe_id = ? ORDER BY sort_order ASC',
            [$recipeId],
        );
    }

    /**
     * Replaces all ingredient rows for a recipe with the given list
     * (delete-then-insert). This is the simplest correct approach for the
     * small, server-rendered repeatable-row form this app uses — see the
     * V6 task notes in docs/plans/0002-v1.0.1-release.md.
     *
     * @param list<array{ingredient_type: string, name: string, quantity: mixed, unit: mixed, timing_note: mixed}> $ingredients
     */
    public function replaceAllForRecipe(int $recipeId, array $ingredients): void
    {
        $this->db->execute('DELETE FROM recipe_ingredients WHERE recipe_id = ?', [$recipeId]);

        foreach (array_values($ingredients) as $sortOrder => $ingredient) {
            $this->db->execute(
                'INSERT INTO recipe_ingredients ' .
                '(recipe_id, ingredient_type, name, quantity, unit, timing_note, sort_order) ' .
                'VALUES (?, ?, ?, ?, ?, ?, ?)',
                [
                    $recipeId,
                    $ingredient['ingredient_type'],
                    $ingredient['name'],
                    $ingredient['quantity'],
                    $ingredient['unit'],
                    $ingredient['timing_note'],
                    $sortOrder,
                ],
            );
        }
    }
}
