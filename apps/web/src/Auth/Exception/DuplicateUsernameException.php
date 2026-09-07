<?php

declare(strict_types=1);

namespace App\Auth\Exception;

/**
 * Thrown by AuthService::register() when the requested username is
 * already taken by another user.
 */
final class DuplicateUsernameException extends \RuntimeException
{
}
