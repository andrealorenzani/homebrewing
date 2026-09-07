<?php

declare(strict_types=1);

namespace App\Http\Csrf;

use App\Http\Session\SessionInterface;

/**
 * Generates, stores, and validates a single per-session CSRF token.
 */
final class CsrfTokenManager
{
    private const SESSION_KEY = '_csrf_token';
    private const FIELD_NAME = 'csrf_token';

    public function __construct(private readonly SessionInterface $session)
    {
    }

    /**
     * Returns the current token, generating and storing one if none exists yet.
     */
    public function getToken(): string
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '') {
            $token = $this->regenerate();
        }

        return $token;
    }

    public function isValid(?string $submitted): bool
    {
        $token = $this->session->get(self::SESSION_KEY);

        if (!is_string($token) || $token === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($token, $submitted);
    }

    public function regenerate(): string
    {
        $token = bin2hex(random_bytes(32));
        $this->session->set(self::SESSION_KEY, $token);

        return $token;
    }

    public function fieldName(): string
    {
        return self::FIELD_NAME;
    }

    /**
     * Renders a ready-to-use hidden `<input>` for state-changing forms.
     */
    public function hiddenField(): string
    {
        $token = $this->getToken();

        return sprintf(
            '<input type="hidden" name="%s" value="%s">',
            htmlspecialchars(self::FIELD_NAME, ENT_QUOTES, 'UTF-8'),
            htmlspecialchars($token, ENT_QUOTES, 'UTF-8'),
        );
    }
}
