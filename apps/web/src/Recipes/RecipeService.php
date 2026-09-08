<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Db\DbInterface;
use App\Recipes\Exception\RecipeAccessDeniedException;
use App\Recipes\Exception\RecipeNotFoundException;

/**
 * Recipe CRUD, ownership enforcement, and the dual-mode visibility
 * access pattern shared by view routes across the app: the owner always
 * has full access; any other authenticated user or anonymous visitor
 * gets read-only access iff `is_public`, otherwise a "not found" (never a
 * "forbidden" — see RecipeNotFoundException's docblock).
 */
final class RecipeService
{
    /** @var list<string> */
    public const CATEGORIES = ['beer', 'wine', 'mead', 'cider', 'other'];

    /** @var list<string> */
    public const INGREDIENT_TYPES = ['fruit', 'grain', 'hop', 'other'];

    /** @var list<string> */
    public const UNITS = ['L', 'mL', 'gal', 'qt', 'kg', 'g', 'lb', 'oz'];

    /** @var list<string> */
    public const YEAST_UNITS = ['packet', 'g', 'mL'];

    /** @var list<string> */
    public const SUGAR_TYPES = ['table_sugar', 'dextrose', 'honey', 'dme', 'lme', 'other'];

    /** @var list<string> */
    public const YEAST_TYPES = ['ale', 'lager', 'wine', 'champagne', 'wild', 'other'];

    public const TARGET_GRAVITY_MIN = 0.990;

    public const TARGET_GRAVITY_MAX = 1.300;

    public function __construct(
        private readonly DbInterface $db,
        private readonly RecipeRepository $recipes,
        private readonly RecipeIngredientRepository $ingredients,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listOwn(int $userId): array
    {
        return $this->recipes->findAllByUserId($userId);
    }

    /**
     * The N most-recently-updated public recipes, across all users — the
     * data behind the homepage showcase (App\Home\HomeController). Private
     * recipes, regardless of how recently updated, are never included.
     *
     * @return list<array<string, mixed>>
     */
    public function recentPublic(int $limit): array
    {
        return $this->recipes->findRecentPublic($limit);
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $ingredients
     */
    public function create(int $userId, array $data, array $ingredients): int
    {
        $this->db->beginTransaction();

        try {
            $id = $this->recipes->insert([...$data, 'user_id' => $userId, 'is_public' => false]);
            $this->ingredients->replaceAllForRecipe($id, $ingredients);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $id;
    }

    /**
     * @return array{recipe: array<string, mixed>, ingredients: list<array<string, mixed>>}
     */
    public function viewForUser(int $recipeId, ?int $viewerUserId): array
    {
        $recipe = $this->recipes->findById($recipeId);

        if ($recipe === null || !$this->isViewable($recipe, $viewerUserId)) {
            throw new RecipeNotFoundException("Recipe {$recipeId} not found.");
        }

        return [
            'recipe' => $recipe,
            'ingredients' => $this->ingredients->findAllByRecipeId($recipeId),
        ];
    }

    /**
     * @return array{recipe: array<string, mixed>, ingredients: list<array<string, mixed>>}
     */
    public function getForEdit(int $recipeId, int $userId): array
    {
        $recipe = $this->requireOwned($recipeId, $userId);

        return [
            'recipe' => $recipe,
            'ingredients' => $this->ingredients->findAllByRecipeId($recipeId),
        ];
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $ingredients
     */
    public function update(int $recipeId, int $userId, array $data, array $ingredients): void
    {
        $recipe = $this->requireOwned($recipeId, $userId);

        $this->db->beginTransaction();

        try {
            $this->recipes->update($recipeId, [
                ...$data,
                'user_id' => (int) $recipe['user_id'],
                'is_public' => (bool) $recipe['is_public'],
            ]);
            $this->ingredients->replaceAllForRecipe($recipeId, $ingredients);
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    public function delete(int $recipeId, int $userId): void
    {
        $this->requireOwned($recipeId, $userId);
        $this->recipes->delete($recipeId);
    }

    /**
     * Flips is_public and returns the new value.
     */
    public function toggleVisibility(int $recipeId, int $userId): bool
    {
        $recipe = $this->requireOwned($recipeId, $userId);
        $newValue = !((bool) $recipe['is_public']);
        $this->recipes->setIsPublic($recipeId, $newValue);

        return $newValue;
    }

    /**
     * @param array<string, mixed> $recipe
     */
    private function isViewable(array $recipe, ?int $viewerUserId): bool
    {
        if ($viewerUserId !== null && (int) $recipe['user_id'] === $viewerUserId) {
            return true;
        }

        return (bool) $recipe['is_public'];
    }

    /**
     * @return array<string, mixed>
     */
    private function requireOwned(int $recipeId, int $userId): array
    {
        $recipe = $this->recipes->findById($recipeId);

        if ($recipe === null) {
            throw new RecipeNotFoundException("Recipe {$recipeId} not found.");
        }

        if ((int) $recipe['user_id'] !== $userId) {
            throw new RecipeAccessDeniedException("User {$userId} does not own recipe {$recipeId}.");
        }

        return $recipe;
    }
}
