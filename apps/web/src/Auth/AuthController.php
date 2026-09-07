<?php

declare(strict_types=1);

namespace App\Auth;

use App\Auth\Exception\DuplicateEmailException;
use App\Auth\Exception\DuplicateUsernameException;
use App\Auth\Exception\InvalidCredentialsException;
use App\Http\Csrf\CsrfTokenManager;
use App\Http\Request;
use App\Http\Response;
use App\View\Renderer;

/**
 * Registration/login/logout routes glue. Forms are CSRF-protected via
 * CsrfMiddleware on the corresponding POST routes (wired in Kernel).
 */
final class AuthController
{
    public function __construct(
        private readonly AuthService $auth,
        private readonly CsrfTokenManager $csrf,
        private readonly Renderer $renderer,
    ) {
    }

    public function showRegisterForm(Request $request): Response
    {
        return $this->renderPage($request, 'pages/auth/register', ['title' => 'Register']);
    }

    public function register(Request $request): Response
    {
        $username = trim((string) $request->getBodyParam('username', ''));
        $email = trim((string) $request->getBodyParam('email', ''));
        $password = (string) $request->getBodyParam('password', '');

        $errors = $this->validateRegistration($username, $email, $password);

        if ($errors === []) {
            try {
                $this->auth->register($username, $email, $password);

                return Response::redirect('/login');
            } catch (DuplicateUsernameException $e) {
                $errors['username'] = $e->getMessage();
            } catch (DuplicateEmailException $e) {
                $errors['email'] = $e->getMessage();
            }
        }

        return $this->renderPage($request, 'pages/auth/register', [
            'title' => 'Register',
            'errors' => $errors,
            'old' => ['username' => $username, 'email' => $email],
        ], 422);
    }

    public function showLoginForm(Request $request): Response
    {
        return $this->renderPage($request, 'pages/auth/login', ['title' => 'Log in']);
    }

    public function login(Request $request): Response
    {
        $usernameOrEmail = trim((string) $request->getBodyParam('username', ''));
        $password = (string) $request->getBodyParam('password', '');

        try {
            $this->auth->login($usernameOrEmail, $password);

            return Response::redirect('/recipes');
        } catch (InvalidCredentialsException $e) {
            return $this->renderPage($request, 'pages/auth/login', [
                'title' => 'Log in',
                'errors' => ['login' => $e->getMessage()],
                'old' => ['username' => $usernameOrEmail],
            ], 422);
        }
    }

    public function logout(Request $request): Response
    {
        $this->auth->logout();

        return Response::redirect('/login');
    }

    /**
     * @param array<string, mixed> $data
     */
    private function renderPage(Request $request, string $template, array $data, int $status = 200): Response
    {
        $html = $this->renderer->renderWithLayout($template, [
            'authUserId' => $request->getAttribute('auth_user_id'),
            'csrfField' => $this->csrf->hiddenField(),
            'errors' => [],
            'old' => [],
            ...$data,
        ]);

        return Response::html($html, $status);
    }

    /**
     * @return array<string, string>
     */
    private function validateRegistration(string $username, string $email, string $password): array
    {
        $errors = [];

        if ($username === '' || strlen($username) < 3 || strlen($username) > 60) {
            $errors['username'] = 'Username must be between 3 and 60 characters.';
        }

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Please enter a valid email address.';
        }

        if (strlen($password) < 8) {
            $errors['password'] = 'Password must be at least 8 characters.';
        }

        return $errors;
    }
}
