<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Shared create/edit recipe form.
 *
 * Ingredient rows: a small fixed set of rows render server-side (blank
 * rows are ignored on submit, same as before); the "Add ingredient"
 * button below the fieldsets is a small, unobtrusive vanilla-JS
 * progressive enhancement (see /js/recipe-form.js) that clones an extra
 * blank row client-side — it degrades gracefully (the button simply does
 * nothing without JS, and the form is fully submittable either way). The
 * same script also drives the live OG/FG -> estimated ABV output.
 *
 * @var string $formAction
 * @var string $submitLabel
 * @var array<string, string> $errors
 * @var array<string, mixed> $recipe
 * @var list<array<string, mixed>> $ingredientRows
 * @var list<string> $categories
 * @var list<string> $ingredientTypes
 * @var list<string> $units
 * @var list<string> $yeastUnits
 * @var list<string> $sugarTypes
 * @var list<string> $yeastTypes
 * @var string $csrfField
 */
$errors ??= [];
$recipe ??= [];
$ingredientRows ??= [];
$units ??= [];
$yeastUnits ??= [];
$sugarTypes ??= [];
$yeastTypes ??= [];

/**
 * Renders a <select> populated from a fixed list of allowed values, with
 * a blank "not set" option first (all of these backing columns are
 * nullable) and the current value (if any) pre-selected.
 *
 * @param list<string> $options
 */
$renderSelect = static function (string $id, string $name, array $options, mixed $current): void {
    ?>
    <select id="<?= Renderer::e($id) ?>" name="<?= Renderer::e($name) ?>">
        <option value="">&mdash;</option>
        <?php foreach ($options as $option): ?>
            <option value="<?= Renderer::e($option) ?>" <?= (string) $current === $option ? 'selected' : '' ?>>
                <?= Renderer::e($option) ?>
            </option>
        <?php endforeach; ?>
    </select>
    <?php
};
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

    <label for="description">Description <span class="field-hint-inline">(optional)</span></label>
    <textarea id="description" name="description"><?= Renderer::e($recipe['description'] ?? '') ?></textarea>

    <p class="field-hint">
        <strong>Batch Size</strong> is the total finished-product volume this recipe yields.
        <strong>Water Quantity</strong> is the water actually used during the brew &mdash; typically more
        than the batch size, since some is lost to boil-off and grain absorption.
    </p>

    <div class="quantity-unit-row">
        <div class="field">
            <label for="batch_size">Batch Size</label>
            <input type="text" id="batch_size" name="batch_size" value="<?= Renderer::e($recipe['batch_size'] ?? '') ?>">
        </div>
        <div class="field field--unit">
            <label for="batch_size_unit">Unit</label>
            <?php $renderSelect('batch_size_unit', 'batch_size_unit', $units, $recipe['batch_size_unit'] ?? null); ?>
        </div>
    </div>

    <div class="quantity-unit-row">
        <div class="field">
            <label for="water_quantity">Water Quantity</label>
            <input type="text" id="water_quantity" name="water_quantity" value="<?= Renderer::e($recipe['water_quantity'] ?? '') ?>">
        </div>
        <div class="field field--unit">
            <label for="water_unit">Unit</label>
            <?php $renderSelect('water_unit', 'water_unit', $units, $recipe['water_unit'] ?? null); ?>
        </div>
    </div>

    <div class="quantity-unit-row">
        <div class="field">
            <label for="sugar_quantity">Sugar Quantity</label>
            <input type="text" id="sugar_quantity" name="sugar_quantity" value="<?= Renderer::e($recipe['sugar_quantity'] ?? '') ?>">
        </div>
        <div class="field field--unit">
            <label for="sugar_unit">Unit</label>
            <?php $renderSelect('sugar_unit', 'sugar_unit', $units, $recipe['sugar_unit'] ?? null); ?>
        </div>
        <div class="field field--type">
            <label for="sugar_type">Type</label>
            <?php $renderSelect('sugar_type', 'sugar_type', $sugarTypes, $recipe['sugar_type'] ?? null); ?>
        </div>
    </div>

    <div class="quantity-unit-row">
        <div class="field">
            <label for="yeast_quantity">Yeast Quantity</label>
            <input type="text" id="yeast_quantity" name="yeast_quantity" value="<?= Renderer::e($recipe['yeast_quantity'] ?? '') ?>">
        </div>
        <div class="field field--unit">
            <label for="yeast_unit">Unit</label>
            <?php $renderSelect('yeast_unit', 'yeast_unit', $yeastUnits, $recipe['yeast_unit'] ?? null); ?>
        </div>
        <div class="field field--type">
            <label for="yeast_type">Type</label>
            <?php $renderSelect('yeast_type', 'yeast_type', $yeastTypes, $recipe['yeast_type'] ?? null); ?>
        </div>
    </div>

    <div class="quantity-unit-row">
        <div class="field">
            <label for="target_og">Target OG</label>
            <input
                type="number"
                id="target_og"
                name="target_og"
                step="0.001"
                min="0.990"
                max="1.300"
                value="<?= Renderer::e($recipe['target_og'] ?? '') ?>"
            >
        </div>
        <div class="field">
            <label for="target_fg">Target FG</label>
            <input
                type="number"
                id="target_fg"
                name="target_fg"
                step="0.001"
                min="0.990"
                max="1.300"
                value="<?= Renderer::e($recipe['target_fg'] ?? '') ?>"
            >
        </div>
        <div class="field field--abv">
            <span class="field-hint-inline">Estimated ABV</span>
            <output id="abv-estimate" for="target_og target_fg">&mdash;</output>
        </div>
    </div>

    <label for="notes">Notes</label>
    <textarea id="notes" name="notes"><?= Renderer::e($recipe['notes'] ?? '') ?></textarea>

    <h2>Ingredients</h2>
    <div id="ingredient-rows">
        <?php foreach ($ingredientRows as $i => $row): ?>
            <fieldset class="ingredient-fieldset">
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
                <?php $renderSelect('ingredient_unit_' . $i, 'ingredient_unit[]', $units, $row['unit']); ?>

                <label for="ingredient_timing_note_<?= $i ?>">Timing note</label>
                <input type="text" id="ingredient_timing_note_<?= $i ?>" name="ingredient_timing_note[]" value="<?= Renderer::e($row['timing_note']) ?>">
            </fieldset>
        <?php endforeach; ?>
    </div>

    <template id="ingredient-row-template">
        <fieldset class="ingredient-fieldset">
            <legend>Ingredient</legend>

            <label for="ingredient_name___INDEX__">Name</label>
            <input type="text" id="ingredient_name___INDEX__" name="ingredient_name[]" value="">

            <label for="ingredient_type___INDEX__">Type</label>
            <select id="ingredient_type___INDEX__" name="ingredient_type[]">
                <?php foreach ($ingredientTypes as $type): ?>
                    <option value="<?= Renderer::e($type) ?>" <?= $type === 'other' ? 'selected' : '' ?>>
                        <?= Renderer::e($type) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <label for="ingredient_quantity___INDEX__">Quantity</label>
            <input type="text" id="ingredient_quantity___INDEX__" name="ingredient_quantity[]" value="">

            <label for="ingredient_unit___INDEX__">Unit</label>
            <?php $renderSelect('ingredient_unit___INDEX__', 'ingredient_unit[]', $units, null); ?>

            <label for="ingredient_timing_note___INDEX__">Timing note</label>
            <input type="text" id="ingredient_timing_note___INDEX__" name="ingredient_timing_note[]" value="">
        </fieldset>
    </template>

    <button type="button" id="add-ingredient-row" class="btn-secondary">Add ingredient</button>

    <button type="submit"><?= Renderer::e($submitLabel) ?></button>
</form>

<script src="/js/recipe-form.js" defer></script>
