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
<h1>Register</h1>

<?php if ($errors !== []): ?>
    <ul class="form-errors">
        <?php foreach ($errors as $error): ?>
            <li><?= Renderer::e($error) ?></li>
        <?php endforeach; ?>
    </ul>
<?php endif; ?>

<form method="post" action="/register">
    <?= $csrfField ?>

    <label for="username">Username</label>
    <input type="text" id="username" name="username" value="<?= Renderer::e($old['username'] ?? '') ?>" required>

    <label for="email">Email</label>
    <input type="email" id="email" name="email" value="<?= Renderer::e($old['email'] ?? '') ?>" required>

    <label for="password">Password</label>
    <input type="password" id="password" name="password" required>

    <button type="submit">Create account</button>
</form>

<p>Already have an account? <a href="/login">Log in</a></p>
