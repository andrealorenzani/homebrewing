<?php

declare(strict_types=1);

namespace App\Recipes;

use App\Http\Csrf\CsrfTokenManager;
use App\Http\Request;
use App\Http\Response;
use App\Recipes\Exception\RecipeAccessDeniedException;
use App\Recipes\Exception\RecipeNotFoundException;
use App\View\Renderer;

/**
 * Recipe CRUD + ownership + visibility-toggle routes glue.
 *
 * `show()` is reachable by anonymous visitors and other logged-in users
 * (registered behind OptionalAuthMiddleware in Kernel); every other
 * action requires an authenticated user (RequireAuthMiddleware) and all
 * state-changing actions are CSRF-protected (CsrfMiddleware).
 */
final class RecipeController
{
    private const MIN_INGREDIENT_ROWS = 3;

    public function __construct(
        private readonly RecipeService $recipes,
        private readonly CsrfTokenManager $csrf,
        private readonly Renderer $renderer,
    ) {
    }

    public function listOwn(Request $request): Response
    {
        $userId = (int) $request->getAttribute('auth_user_id');
        $recipes = $this->recipes->listOwn($userId);

        return $this->renderPage($request, 'pages/recipes/index', [
            'title' => 'My Recipes',
            'recipes' => $recipes,
        ]);
    }

    public function showCreateForm(Request $request): Response
    {
        return $this->renderForm($request, '/recipes', 'Create Recipe', 'Save Recipe');
    }

    public function create(Request $request): Response
    {
        $userId = (int) $request->getAttribute('auth_user_id');
        $data = $this->parseRecipeDataFromRequest($request);
        $ingredients = $this->parseIngredientsFromRequest($request);
        $errors = $this->validateRecipe($data);

        if ($errors !== []) {
            return $this->renderForm($request, '/recipes', 'Create Recipe', 'Save Recipe', $errors, $data, $ingredients, 422);
        }

        $id = $this->recipes->create($userId, $data, $ingredients);

        return Response::redirect("/recipes/{$id}");
    }

    public function show(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $viewerIdRaw = $request->getAttribute('auth_user_id');
        $viewerId = $viewerIdRaw === null ? null : (int) $viewerIdRaw;

        try {
            $result = $this->recipes->viewForUser($id, $viewerId);
        } catch (RecipeNotFoundException) {
            return Response::notFound();
        }

        $isOwner = $viewerId !== null && $viewerId === (int) $result['recipe']['user_id'];

        return $this->renderPage($request, 'pages/recipes/show', [
            'title' => (string) $result['recipe']['name'],
            'recipe' => $result['recipe'],
            'ingredients' => $result['ingredients'],
            'isOwner' => $isOwner,
        ]);
    }

    public function showEditForm(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $result = $this->recipes->getForEdit($id, $userId);
        } catch (RecipeNotFoundException) {
            return Response::notFound();
        } catch (RecipeAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return $this->renderForm(
            $request,
            "/recipes/{$id}/edit",
            'Edit Recipe',
            'Update Recipe',
            [],
            $result['recipe'],
            $result['ingredients'],
        );
    }

    public function update(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');
        $data = $this->parseRecipeDataFromRequest($request);
        $ingredients = $this->parseIngredientsFromRequest($request);
        $errors = $this->validateRecipe($data);

        if ($errors !== []) {
            return $this->renderForm($request, "/recipes/{$id}/edit", 'Edit Recipe', 'Update Recipe', $errors, $data, $ingredients, 422);
        }

        try {
            $this->recipes->update($id, $userId, $data, $ingredients);
        } catch (RecipeNotFoundException) {
            return Response::notFound();
        } catch (RecipeAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect("/recipes/{$id}");
    }

    public function delete(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $this->recipes->delete($id, $userId);
        } catch (RecipeNotFoundException) {
            return Response::notFound();
        } catch (RecipeAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect('/recipes');
    }

    public function toggleVisibility(Request $request): Response
    {
        $id = (int) $request->getRouteParam('id');
        $userId = (int) $request->getAttribute('auth_user_id');

        try {
            $this->recipes->toggleVisibility($id, $userId);
        } catch (RecipeNotFoundException) {
            return Response::notFound();
        } catch (RecipeAccessDeniedException) {
            return Response::text('Forbidden', 403);
        }

        return Response::redirect("/recipes/{$id}");
    }

    /**
     * @param array<string, string> $errors
     * @param array<string, mixed> $recipe
     * @param list<array<string, mixed>> $ingredients
     */
    private function renderForm(
        Request $request,
        string $action,
        string $title,
        string $submitLabel,
        array $errors = [],
        array $recipe = [],
        array $ingredients = [],
        int $status = 200,
    ): Response {
        return $this->renderPage($request, 'pages/recipes/form', [
            'title' => $title,
            'formAction' => $action,
            'submitLabel' => $submitLabel,
            'errors' => $errors,
            'recipe' => $recipe,
            'ingredientRows' => $this->buildIngredientRows($ingredients),
            'categories' => RecipeService::CATEGORIES,
            'ingredientTypes' => RecipeService::INGREDIENT_TYPES,
            'units' => RecipeService::UNITS,
            'yeastUnits' => RecipeService::YEAST_UNITS,
            'sugarTypes' => RecipeService::SUGAR_TYPES,
            'yeastTypes' => RecipeService::YEAST_TYPES,
        ], $status);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPage(Request $request, string $template, array $data, int $status = 200): Response
    {
        $html = $this->renderer->renderWithLayout($template, [
            'authUserId' => $request->getAttribute('auth_user_id'),
            'csrfField' => $this->csrf->hiddenField(),
            'errors' => [],
            ...$data,
        ]);

        return Response::html($html, $status);
    }

    /**
     * @param list<array<string, mixed>> $ingredients
     * @return list<array<string, mixed>>
     */
    private function buildIngredientRows(array $ingredients): array
    {
        $rows = array_map(static fn (array $i): array => [
            'ingredient_type' => $i['ingredient_type'] ?? 'other',
            'name' => $i['name'] ?? '',
            'quantity' => $i['quantity'] ?? '',
            'unit' => $i['unit'] ?? '',
            'timing_note' => $i['timing_note'] ?? '',
        ], array_values($ingredients));

        $blank = ['ingredient_type' => 'other', 'name' => '', 'quantity' => '', 'unit' => '', 'timing_note' => ''];

        while (count($rows) < self::MIN_INGREDIENT_ROWS) {
            $rows[] = $blank;
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function parseRecipeDataFromRequest(Request $request): array
    {
        $category = (string) $request->getBodyParam('category', 'other');

        if (!in_array($category, RecipeService::CATEGORIES, true)) {
            $category = 'other';
        }

        return [
            'name' => trim((string) $request->getBodyParam('name', '')),
            'category' => $category,
            'description' => $this->nullableString($request->getBodyParam('description')),
            'batch_size' => $this->nullableNumeric($request->getBodyParam('batch_size')),
            'batch_size_unit' => $this->nullableInList($request->getBodyParam('batch_size_unit'), RecipeService::UNITS),
            'water_quantity' => $this->nullableNumeric($request->getBodyParam('water_quantity')),
            'water_unit' => $this->nullableInList($request->getBodyParam('water_unit'), RecipeService::UNITS),
            'sugar_quantity' => $this->nullableNumeric($request->getBodyParam('sugar_quantity')),
            'sugar_unit' => $this->nullableInList($request->getBodyParam('sugar_unit'), RecipeService::UNITS),
            'sugar_type' => $this->nullableInList($request->getBodyParam('sugar_type'), RecipeService::SUGAR_TYPES),
            'yeast_type' => $this->nullableInList($request->getBodyParam('yeast_type'), RecipeService::YEAST_TYPES),
            'yeast_quantity' => $this->nullableNumeric($request->getBodyParam('yeast_quantity')),
            'yeast_unit' => $this->nullableInList($request->getBodyParam('yeast_unit'), RecipeService::YEAST_UNITS),
            'target_og' => $this->nullableNumeric($request->getBodyParam('target_og')),
            'target_fg' => $this->nullableNumeric($request->getBodyParam('target_fg')),
            'notes' => $this->nullableString($request->getBodyParam('notes')),
        ];
    }

    /**
     * @return list<array{ingredient_type: string, name: string, quantity: mixed, unit: mixed, timing_note: mixed}>
     */
    private function parseIngredientsFromRequest(Request $request): array
    {
        $types = (array) $request->getBodyParam('ingredient_type', []);
        $names = (array) $request->getBodyParam('ingredient_name', []);
        $quantities = (array) $request->getBodyParam('ingredient_quantity', []);
        $units = (array) $request->getBodyParam('ingredient_unit', []);
        $timingNotes = (array) $request->getBodyParam('ingredient_timing_note', []);

        $ingredients = [];

        foreach ($names as $i => $name) {
            $name = trim((string) $name);

            if ($name === '') {
                continue; // Blank rows are simply ignored.
            }

            $type = (string) ($types[$i] ?? 'other');

            if (!in_array($type, RecipeService::INGREDIENT_TYPES, true)) {
                $type = 'other';
            }

            $ingredients[] = [
                'ingredient_type' => $type,
                'name' => $name,
                'quantity' => $this->nullableNumeric($quantities[$i] ?? null),
                'unit' => $this->nullableInList($units[$i] ?? null, RecipeService::UNITS),
                'timing_note' => $this->nullableString($timingNotes[$i] ?? null),
            ];
        }

        return $ingredients;
    }

    /**
     * @return array<string, string>
     */
    private function validateRecipe(array $data): array
    {
        $errors = [];

        if ($data['name'] === '') {
            $errors['name'] = 'Name is required.';
        }

        foreach (['target_og' => 'Target OG', 'target_fg' => 'Target FG'] as $field => $label) {
            $value = $data[$field];

            if ($value === null) {
                continue;
            }

            if (!is_numeric($value)) {
                $errors[$field] = "{$label} must be a number.";

                continue;
            }

            $numeric = (float) $value;

            if ($numeric < RecipeService::TARGET_GRAVITY_MIN || $numeric > RecipeService::TARGET_GRAVITY_MAX) {
                $errors[$field] = sprintf(
                    '%s must be between %.3f and %.3f.',
                    $label,
                    RecipeService::TARGET_GRAVITY_MIN,
                    RecipeService::TARGET_GRAVITY_MAX,
                );
            }
        }

        return $errors;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function nullableNumeric(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return (string) $value;
    }

    /**
     * @param list<string> $allowed
     */
    private function nullableInList(mixed $value, array $allowed): ?string
    {
        $value = $this->nullableString($value);

        if ($value === null || !in_array($value, $allowed, true)) {
            return null;
        }

        return $value;
    }
}
