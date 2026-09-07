<?php

declare(strict_types=1);

namespace App\Auth\Exception;

/**
 * Thrown by AuthService::login() when the supplied username/email and
 * password do not match a known user. Deliberately generic — never
 * indicates whether the username/email itself was recognized.
 */
final class InvalidCredentialsException extends \RuntimeException
{
}
