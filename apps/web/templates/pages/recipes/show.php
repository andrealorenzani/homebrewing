<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Single-recipe detail view. Reachable by the owner (full access), any
 * other authenticated user, or an anonymous visitor — but the controller
 * only renders this template at all if the viewer is allowed to see the
 * recipe (owner, or is_public); everyone else gets a 404 upstream.
 *
 * @var array<string, mixed> $recipe
 * @var list<array<string, mixed>> $ingredients
 * @var bool $isOwner
 * @var string $csrfField
 */
$ingredients ??= [];
?>
<h1><?= Renderer::e($recipe['name']) ?></h1>
<p class="card-meta">
    <?= Renderer::e($recipe['category']) ?>
    &middot;
    <?= ((int) $recipe['is_public'] === 1) ? 'Public' : 'Private' ?>
</p>

<?php if ($isOwner): ?>
    <p>
        <a class="btn" href="/recipes/<?= (int) $recipe['id'] ?>/edit">Edit</a>

        <form method="post" action="/recipes/<?= (int) $recipe['id'] ?>/toggle-visibility" style="display:inline-block">
            <?= $csrfField ?>
            <button type="submit" class="btn-secondary">
                <?= ((int) $recipe['is_public'] === 1) ? 'Make Private' : 'Make Public' ?>
            </button>
        </form>

        <form method="post" action="/recipes/<?= (int) $recipe['id'] ?>/delete" style="display:inline-block">
            <?= $csrfField ?>
            <button type="submit" class="btn-secondary">Delete</button>
        </form>
    </p>
<?php endif; ?>

<?php if (!empty($recipe['description'])): ?>
    <p><?= nl2br(Renderer::e($recipe['description'])) ?></p>
<?php endif; ?>

<h2>Ingredients</h2>
<?php if ($ingredients === []): ?>
    <p class="empty-state">No ingredients listed.</p>
<?php else: ?>
    <ul>
        <?php foreach ($ingredients as $ingredient): ?>
            <li>
                <?= Renderer::e($ingredient['name']) ?>
                <?php if ($ingredient['quantity'] !== null): ?>
                    &mdash; <?= Renderer::e($ingredient['quantity']) ?> <?= Renderer::e($ingredient['unit']) ?>
                <?php endif; ?>
                (<?= Renderer::e($ingredient['ingredient_type']) ?>)
            </li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<?php if (!empty($recipe['notes'])): ?>
    <h2>Notes</h2>
    <p><?= nl2br(Renderer::e($recipe['notes'])) ?></p>
<?php endif; ?>
