<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * Base layout. Expects:
 * @var string $content pre-rendered HTML for the page body (trusted, not re-escaped)
 * @var string|null $title optional page title
 * @var int|null $authUserId current session user id, if any (controllers
 *      pass this through so the nav can show Login/Register or Logout)
 * @var string|null $csrfField pre-rendered hidden CSRF field, needed for
 *      the nav's logout form when $authUserId is set
 */
?><!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= Renderer::e($title ?? 'Homebrewing') ?></title>
    <link rel="stylesheet" href="/css/app.css">
</head>
<body>
<header class="site-header">
    <div class="container site-header__inner">
        <a class="brand" href="/">Homebrewing</a>
        <nav class="main-nav" aria-label="Main">
            <a href="/recipes" class="nav-link">Recipes</a>
            <a href="/diaries" class="nav-link">Diaries</a>
            <?php if (!empty($authUserId)): ?>
                <form method="post" action="/logout" class="nav-logout-form">
                    <?= $csrfField ?? '' ?>
                    <button type="submit" class="nav-link nav-link--button">Logout</button>
                </form>
            <?php else: ?>
                <a href="/login" class="nav-link">Login</a>
                <a href="/register" class="nav-link">Register</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main class="container">
<?= $content ?>
</main>
<footer class="site-footer">
    <div class="container">
        <p>&copy; <?= (int) date('Y') ?> Homebrewing</p>
    </div>
</footer>
</body>
</html>
