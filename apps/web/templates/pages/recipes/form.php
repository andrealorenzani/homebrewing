<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Shared create/edit recipe form. A basic fixed-size set of repeatable
 * ingredient rows (blank rows are ignored on submit) — deliberately not
 * a JS-powered add/remove-row widget, per the plan's "don't
 * over-engineer" guidance for this server-rendered, no-JS-framework app.
 *
 * @var string $formAction
 * @var string $submitLabel
 * @var array<string, string> $errors
 * @var array<string, mixed> $recipe
 * @var list<array<string, mixed>> $ingredientRows
 * @var list<string> $categories
 * @var list<string> $ingredientTypes
 * @var string $csrfField
 */
$errors ??= [];
$recipe ??= [];
$ingredientRows ??= [];
?>
<h1><?= Renderer::e($title ?? 'Recipe') ?></h1>

<?php if ($errors !== []): ?>
    <ul class="form-errors">
        <?php foreach ($errors as $error): ?>
            <li><?= Renderer::e($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="<?= Renderer::e($formAction) ?>">
    <?= $csrfField ?>

    <label for="name">Name</label>
    <input type="text" id="name" name="name" value="<?= Renderer::e($recipe['name'] ?? '') ?>" required>

    <label for="category">Category</label>
    <select id="category" name="category">
        <?php foreach ($categories as $category): ?>
            <option value="<?= Renderer::e($category) ?>" <?= ($recipe['category'] ?? 'other') === $category ? 'selected' : '' ?>>
                <?= Renderer::e($category) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <label for="description">Description</label>
    <textarea id="description" name="description"><?= Renderer::e($recipe['description'] ?? '') ?></textarea>

    <label for="batch_size">Batch Size</label>
    <input type="text" id="batch_size" name="batch_size" value="<?= Renderer::e($recipe['batch_size'] ?? '') ?>">

    <label for="batch_size_unit">Batch Size Unit</label>
    <input type="text" id="batch_size_unit" name="batch_size_unit" value="<?= Renderer::e($recipe['batch_size_unit'] ?? '') ?>">

    <label for="water_quantity">Water Quantity</label>
    <input type="text" id="water_quantity" name="water_quantity" value="<?= Renderer::e($recipe['water_quantity'] ?? '') ?>">

    <label for="water_unit">Water Unit</label>
    <input type="text" id="water_unit" name="water_unit" value="<?= Renderer::e($recipe['water_unit'] ?? '') ?>">

    <label for="sugar_quantity">Sugar Quantity</label>
    <input type="text" id="sugar_quantity" name="sugar_quantity" value="<?= Renderer::e($recipe['sugar_quantity'] ?? '') ?>">

    <label for="sugar_unit">Sugar Unit</label>
    <input type="text" id="sugar_unit" name="sugar_unit" value="<?= Renderer::e($recipe['sugar_unit'] ?? '') ?>">

    <label for="sugar_type">Sugar Type</label>
    <input type="text" id="sugar_type" name="sugar_type" value="<?= Renderer::e($recipe['sugar_type'] ?? '') ?>">

    <label for="yeast_type">Yeast Type</label>
    <input type="text" id="yeast_type" name="yeast_type" value="<?= Renderer::e($recipe['yeast_type'] ?? '') ?>">

    <label for="yeast_quantity">Yeast Quantity</label>
    <input type="text" id="yeast_quantity" name="yeast_quantity" value="<?= Renderer::e($recipe['yeast_quantity'] ?? '') ?>">

    <label for="yeast_unit">Yeast Unit</label>
    <input type="text" id="yeast_unit" name="yeast_unit" value="<?= Renderer::e($recipe['yeast_unit'] ?? '') ?>">

    <label for="target_og">Target OG</label>
    <input type="text" id="target_og" name="target_og" value="<?= Renderer::e($recipe['target_og'] ?? '') ?>">

    <label for="target_fg">Target FG</label>
    <input type="text" id="target_fg" name="target_fg" value="<?= Renderer::e($recipe['target_fg'] ?? '') ?>">

    <label for="notes">Notes</label>
    <textarea id="notes" name="notes"><?= Renderer::e($recipe['notes'] ?? '') ?></textarea>

    <h2>Ingredients</h2>
    <?php foreach ($ingredientRows as $i => $row): ?>
        <fieldset>
            <legend>Ingredient <?= $i + 1 ?></legend>

            <label for="ingredient_name_<?= $i ?>">Name</label>
            <input type="text" id="ingredient_name_<?= $i ?>" name="ingredient_name[]" value="<?= Renderer::e($row['name']) ?>">

            <label for="ingredient_type_<?= $i ?>">Type</label>
            <select id="ingredient_type_<?= $i ?>" name="ingredient_type[]">
                <?php foreach ($ingredientTypes as $type): ?>
                    <option value="<?= Renderer::e($type) ?>" <?= $row['ingredient_type'] === $type ? 'selected' : '' ?>>
                        <?= Renderer::e($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="ingredient_quantity_<?= $i ?>">Quantity</label>
            <input type="text" id="ingredient_quantity_<?= $i ?>" name="ingredient_quantity[]" value="<?= Renderer::e($row['quantity']) ?>">

            <label for="ingredient_unit_<?= $i ?>">Unit</label>
            <input type="text" id="ingredient_unit_<?= $i ?>" name="ingredient_unit[]" value="<?= Renderer::e($row['unit']) ?>">

            <label for="ingredient_timing_note_<?= $i ?>">Timing note</label>
            <input type="text" id="ingredient_timing_note_<?= $i ?>" name="ingredient_timing_note[]" value="<?= Renderer::e($row['timing_note']) ?>">
        </fieldset>
    <?php endforeach; ?>

    <button type="submit"><?= Renderer::e($submitLabel) ?></button>
</form>
