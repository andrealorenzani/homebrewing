<?php

declare(strict_types=1);

use App\View\Renderer;

/**
 * @var array<string, string> $errors
 * @var array<string, string> $old
 * @var string $csrfField
 */
$errors ??= [];
$old ??= [];
?>
<h1>Log in</h1>

<?php if ($errors !== []): ?>
    <ul class="form-errors">
        <?php foreach ($errors as $error): ?>
            <li><?= Renderer::e($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="/login">
    <?= $csrfField ?>

    <label for="username">Username or email</label>
    <input type="text" id="username" name="username" value="<?= Renderer::e($old['username'] ?? '') ?>" required>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Log in</button>
</form>

<p>Need an account? <a href="/register">Register</a></p>
