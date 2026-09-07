<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Shared create/edit batch (diary) form. On create, the user picks one of
 * their own recipes from a dropdown; the recipe a batch is under can't be
 * changed afterward, so the edit form omits that field. Mirrors
 * templates/pages/recipes/form.php.
 *
 * @var string $formAction
 * @var string $submitLabel
 * @var array<string, string> $errors
 * @var array<string, mixed> $batch
 * @var list<string> $statuses
 * @var list<array<string, mixed>> $ownRecipes
 * @var bool $isEdit
 * @var string $csrfField
 */
$errors ??= [];
$batch ??= [];
$ownRecipes ??= [];
?>
<h1><?= Renderer::e($title ?? 'Diary') ?></h1>

<?php if ($errors !== []): ?>
    <ul class="form-errors">
        <?php foreach ($errors as $error): ?>
            <li><?= Renderer::e($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="<?= Renderer::e($formAction) ?>">
    <?= $csrfField ?>

    <?php if (!$isEdit): ?>
        <label for="recipe_id">Recipe</label>
        <select id="recipe_id" name="recipe_id" required>
            <option value="">-- choose a recipe --</option>
            <?php foreach ($ownRecipes as $recipe): ?>
                <option value="<?= (int) $recipe['id'] ?>" <?= (int) ($batch['recipe_id'] ?? 0) === (int) $recipe['id'] ? 'selected' : '' ?>>
                    <?= Renderer::e($recipe['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    <?php endif; ?>

    <label for="label">Label</label>
    <input type="text" id="label" name="label" value="<?= Renderer::e($batch['label'] ?? '') ?>">

    <label for="status">Status</label>
    <select id="status" name="status">
        <?php foreach ($statuses as $status): ?>
            <option value="<?= Renderer::e($status) ?>" <?= ($batch['status'] ?? 'planning') === $status ? 'selected' : '' ?>>
                <?= Renderer::e($status) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="started_at">Start Date</label>
    <input type="date" id="started_at" name="started_at" value="<?= Renderer::e($batch['started_at'] ?? '') ?>" required>

    <button type="submit"><?= Renderer::e($submitLabel) ?></button>
</form>
