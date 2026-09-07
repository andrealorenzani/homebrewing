<?php

declare(strict_types=1);

namespace App\Auth\Exception;

/**
 * Thrown by AuthService::register() when the requested email address is
 * already registered to another user.
 */
final class DuplicateEmailException extends \RuntimeException
{
}
