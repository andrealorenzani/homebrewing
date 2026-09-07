<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Homepage recipe-showcase template, wired to `GET /` via
 * App\Home\HomeController. Card grid of the N most-recently-updated
 * **public** recipes across all users (N is config-driven — see
 * HOMEPAGE_RECIPE_COUNT in .env.example / Kernel::homeController()).
 *
 * @var string|null $title
 * @var list<array<string, mixed>> $recipes most-recently-updated public recipes
 */
$recipes ??= [];
?>
<h1><?= Renderer::e($title ?? 'Latest recipes') ?></h1>
<?php if ($recipes === []): ?>
    <p class="empty-state">No public recipes yet &mdash; check back soon.</p>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($recipes as $recipe): ?>
            <article class="card">
                <h2 class="card-title">
                    <a href="/recipes/<?= (int) $recipe['id'] ?>"><?= Renderer::e($recipe['name']) ?></a>
                </h2>
                <p class="card-meta"><?= Renderer::e($recipe['category']) ?></p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
