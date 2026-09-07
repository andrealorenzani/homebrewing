<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Logged-in user's own recipe listing (reachable via the nav's "Recipes"
 * link). The public homepage showcase (public recipes only, all users)
 * is a separate template built in a later task.
 *
 * @var list<array<string, mixed>> $recipes
 */
$recipes ??= [];
?>
<h1>My Recipes</h1>
<p><a class="btn" href="/recipes/new">New Recipe</a></p>

<?php if ($recipes === []): ?>
    <p class="empty-state">You haven't added any recipes yet.</p>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($recipes as $recipe): ?>
            <article class="card">
                <h2 class="card-title">
                    <a href="/recipes/<?= (int) $recipe['id'] ?>"><?= Renderer::e($recipe['name']) ?></a>
                </h2>
                <p class="card-meta"><?= Renderer::e($recipe['category']) ?></p>
                <p class="card-meta"><?= ((int) $recipe['is_public'] === 1) ? 'Public' : 'Private' ?></p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
