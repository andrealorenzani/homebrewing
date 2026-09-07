<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Logged-in user's own batch/diary listing (reachable via the nav's
 * "Diaries" link). Mirrors templates/pages/recipes/index.php.
 *
 * @var list<array<string, mixed>> $batches
 */
$batches ??= [];
?>
<h1>My Diaries</h1>
<p><a class="btn" href="/diaries/new">New Diary</a></p>

<?php if ($batches === []): ?>
    <p class="empty-state">You haven't started any batch diaries yet.</p>
<?php else: ?>
    <div class="card-grid">
        <?php foreach ($batches as $batch): ?>
            <article class="card">
                <h2 class="card-title">
                    <a href="/diaries/<?= (int) $batch['id'] ?>">
                        <?= Renderer::e($batch['label'] !== null && $batch['label'] !== '' ? $batch['label'] : 'Batch #' . $batch['id']) ?>
                    </a>
                </h2>
                <p class="card-meta"><?= Renderer::e($batch['status']) ?> &middot; started <?= Renderer::e($batch['started_at']) ?></p>
                <p class="card-meta"><?= ((int) $batch['is_public'] === 1) ? 'Public' : 'Private' ?></p>
            </article>
        <?php endforeach; ?>
    </div>
<?php endif; ?>
