<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Single-batch detail view with its diary log timeline. Reachable by the
 * owner (full access), any other authenticated user, or an anonymous
 * visitor — but the controller only renders this template at all if the
 * viewer is allowed to see the batch (owner, or is_public); everyone else
 * gets a 404 upstream. Mirrors templates/pages/recipes/show.php.
 *
 * @var array<string, mixed> $batch
 * @var list<array<string, mixed>> $logEntries each augmented with a 'dayNumber' int
 * @var bool $isOwner
 * @var string $csrfField
 */
$logEntries ??= [];
$label = $batch['label'] !== null && $batch['label'] !== '' ? $batch['label'] : 'Batch #' . $batch['id'];
?>
<h1><?= Renderer::e($label) ?></h1>
<p class="card-meta">
    <?= Renderer::e($batch['status']) ?>
    &middot;
    started <?= Renderer::e($batch['started_at']) ?>
    &middot;
    <?= ((int) $batch['is_public'] === 1) ? 'Public' : 'Private' ?>
</p>

<?php if ($isOwner): ?>
    <p>
        <a class="btn" href="/diaries/<?= (int) $batch['id'] ?>/edit">Edit</a>

        <form method="post" action="/diaries/<?= (int) $batch['id'] ?>/toggle-visibility" style="display:inline-block">
            <?= $csrfField ?>
            <button type="submit" class="btn-secondary">
                <?= ((int) $batch['is_public'] === 1) ? 'Make Private' : 'Make Public' ?>
            </button>
        </form>

        <form method="post" action="/diaries/<?= (int) $batch['id'] ?>/delete" style="display:inline-block">
            <?= $csrfField ?>
            <button type="submit" class="btn-secondary">Delete</button>
        </form>
    </p>
<?php endif; ?>

<h2>Diary</h2>

<?php if ($isOwner): ?>
    <p><a class="btn" href="/diaries/<?= (int) $batch['id'] ?>/log-entries/new">Add Log Entry</a></p>
<?php endif; ?>

<?php if ($logEntries === []): ?>
    <p class="empty-state">No log entries yet.</p>
<?php else: ?>
    <ul class="diary-timeline">
        <?php foreach ($logEntries as $entry): ?>
            <li>
                <strong>Day <?= (int) $entry['dayNumber'] ?></strong>
                &mdash; <?= Renderer::e($entry['entry_date']) ?>

                <?php if ($entry['specific_gravity'] !== null): ?>
                    &middot; SG <?= Renderer::e($entry['specific_gravity']) ?>
                <?php endif; ?>
                <?php if ($entry['acidity_ph'] !== null): ?>
                    &middot; pH <?= Renderer::e($entry['acidity_ph']) ?>
                <?php endif; ?>
                <?php if ($entry['temperature'] !== null): ?>
                    &middot; <?= Renderer::e($entry['temperature']) ?><?= Renderer::e($entry['temperature_unit']) ?>
                <?php endif; ?>
                <?php if (!empty($entry['stage_vessel'])): ?>
                    &middot; <?= Renderer::e($entry['stage_vessel']) ?>
                <?php endif; ?>

                <?php if (!empty($entry['note'])): ?>
                    <p><?= nl2br(Renderer::e($entry['note'])) ?></p>
                <?php endif; ?>

                <?php if ($isOwner): ?>
                    <p>
                        <a href="/diaries/<?= (int) $batch['id'] ?>/log-entries/<?= (int) $entry['id'] ?>/edit">Edit</a>
                        <form method="post" action="/diaries/<?= (int) $batch['id'] ?>/log-entries/<?= (int) $entry['id'] ?>/delete" style="display:inline-block">
                            <?= $csrfField ?>
                            <button type="submit" class="btn-secondary">Delete</button>
                        </form>
                    </p>
                <?php endif; ?>
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>
