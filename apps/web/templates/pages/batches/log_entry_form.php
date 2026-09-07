<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Shared add/edit diary log entry form.
 *
 * @var string $formAction
 * @var string $submitLabel
 * @var array<string, string> $errors
 * @var array<string, mixed> $entry
 * @var string $csrfField
 */
$errors ??= [];
$entry ??= [];
?>
<h1><?= Renderer::e($title ?? 'Log Entry') ?></h1>

<?php if ($errors !== []): ?>
    <ul class="form-errors">
        <?php foreach ($errors as $error): ?>
            <li><?= Renderer::e($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="<?= Renderer::e($formAction) ?>">
    <?= $csrfField ?>

    <label for="entry_date">Date</label>
    <input type="date" id="entry_date" name="entry_date" value="<?= Renderer::e($entry['entry_date'] ?? '') ?>" required>

    <label for="specific_gravity">Specific Gravity</label>
    <input type="text" id="specific_gravity" name="specific_gravity" value="<?= Renderer::e($entry['specific_gravity'] ?? '') ?>">

    <label for="acidity_ph">Acidity (pH)</label>
    <input type="text" id="acidity_ph" name="acidity_ph" value="<?= Renderer::e($entry['acidity_ph'] ?? '') ?>">

    <label for="temperature">Temperature</label>
    <input type="text" id="temperature" name="temperature" value="<?= Renderer::e($entry['temperature'] ?? '') ?>">

    <label for="temperature_unit">Temperature Unit</label>
    <input type="text" id="temperature_unit" name="temperature_unit" value="<?= Renderer::e($entry['temperature_unit'] ?? '') ?>">

    <label for="stage_vessel">Stage / Vessel</label>
    <input type="text" id="stage_vessel" name="stage_vessel" value="<?= Renderer::e($entry['stage_vessel'] ?? '') ?>">

    <label for="note">Note</label>
    <textarea id="note" name="note"><?= Renderer::e($entry['note'] ?? '') ?></textarea>

    <button type="submit"><?= Renderer::e($submitLabel) ?></button>
</form>
