<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Exception\DuplicateEmailException;
use App\Auth\Exception\DuplicateUsernameException;
use App\Auth\Exception\InvalidCredentialsException;
use App\Http\Session\SessionInterface;

/**
 * Session-based registration/login/logout. No antispam (honeypot,
 * challenge, rate limiting) — explicitly deferred per the v1.0.1 plan.
 */
final class AuthService
{
    private const SESSION_KEY = 'user_id';

    public function __construct(
        private readonly UserRepository $users,
        private readonly SessionInterface $session,
    ) {
    }

    /**
     * Creates a new user with a hashed password. Throws
     * DuplicateUsernameException/DuplicateEmailException if either is
     * already taken. Does not log the new user in — the caller decides
     * whether to redirect to the login form or call login() directly.
     *
     * @return array<string, mixed> the newly created user row
     */
    public function register(string $username, string $email, string $password): array
    {
        $username = trim($username);
        $email = trim($email);

        if ($this->users->findByUsername($username) !== null) {
            throw new DuplicateUsernameException('That username is already taken.');
        }

        if ($this->users->findByEmail($email) !== null) {
            throw new DuplicateEmailException('That email address is already registered.');
        }

        $hash = password_hash($password, PASSWORD_DEFAULT);
        $id = $this->users->insert($username, $email, $hash);

        $user = $this->users->findById($id);

        if ($user === null) {
            // Should be unreachable outside of a broken Db implementation;
            // guards against a misleading null-returning login() call.
            throw new \RuntimeException('Failed to load newly registered user.');
        }

        return $user;
    }

    /**
     * Verifies credentials (by username or email) and, on success, stores
     * the user id in the session and rotates the session id (to mitigate
     * session fixation). Throws InvalidCredentialsException on any
     * failure — deliberately the same exception whether the
     * username/email was unknown or the password was wrong, so a caller
     * can't distinguish "unknown account" from "wrong password".
     *
     * @return array<string, mixed> the authenticated user row
     */
    public function login(string $usernameOrEmail, string $password): array
    {
        $usernameOrEmail = trim($usernameOrEmail);

        $user = $this->users->findByUsername($usernameOrEmail)
            ?? $this->users->findByEmail($usernameOrEmail);

        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            throw new InvalidCredentialsException('Incorrect username/email or password.');
        }

        $this->session->set(self::SESSION_KEY, (int) $user['id']);
        $this->session->regenerateId();

        return $user;
    }

    public function logout(): void
    {
        $this->session->remove(self::SESSION_KEY);
        $this->session->regenerateId();
    }
}
