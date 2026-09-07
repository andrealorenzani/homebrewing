<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\AuthController;
use App\Auth\AuthService;
use App\Auth\UserRepository;
use App\Db\FakeDb;
use App\Http\Csrf\CsrfTokenManager;
use App\Http\Request;
use App\Http\Session\ArraySession;
use App\View\Renderer;
use Tests\TestCase;

final class AuthControllerTest extends TestCase
{
    private function makeController(FakeDb $db, ArraySession $session): AuthController
    {
        $renderer = new Renderer(dirname(__DIR__, 3) . '/templates');

        return new AuthController(
            new AuthService(new UserRepository($db), $session),
            new CsrfTokenManager($session),
            $renderer,
        );
    }

    public function testShowRegisterFormRendersForm(): void
    {
        $controller = $this->makeController(new FakeDb(), new ArraySession());

        $response = $controller->showRegisterForm(Request::create('GET', '/register'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<form', $response->getBody());
        $this->assertStringContainsString('name="username"', $response->getBody());
    }

    public function testRegisterWithValidDataRedirectsToLogin(): void
    {
        $controller = $this->makeController(new FakeDb(), new ArraySession());

        $response = $controller->register(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
    }

    public function testRegisterWithDuplicateUsernameReRendersFormWithError(): void
    {
        $db = new FakeDb();
        $session = new ArraySession();
        $controller = $this->makeController($db, $session);
        $controller->register(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
        ]));

        $response = $controller->register(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'someone-else@example.com',
            'password' => 'another-password',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('already taken', $response->getBody());
    }

    public function testRegisterWithDuplicateEmailReRendersFormWithError(): void
    {
        $db = new FakeDb();
        $session = new ArraySession();
        $controller = $this->makeController($db, $session);
        $controller->register(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
        ]));

        $response = $controller->register(Request::create('POST', '/register', [], [
            'username' => 'someone-else',
            'email' => 'alice@example.com',
            'password' => 'another-password',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('already registered', $response->getBody());
    }

    public function testRegisterWithInvalidDataReRendersFormWithValidationErrors(): void
    {
        $controller = $this->makeController(new FakeDb(), new ArraySession());

        $response = $controller->register(Request::create('POST', '/register', [], [
            'username' => 'ab',
            'email' => 'not-an-email',
            'password' => 'short',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Username must be', $response->getBody());
        $this->assertStringContainsString('valid email', $response->getBody());
        $this->assertStringContainsString('at least 8 characters', $response->getBody());
    }

    public function testShowLoginFormRendersForm(): void
    {
        $controller = $this->makeController(new FakeDb(), new ArraySession());

        $response = $controller->showLoginForm(Request::create('GET', '/login'));

        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('<form', $response->getBody());
    }

    public function testLoginWithValidCredentialsRedirectsToRecipes(): void
    {
        $db = new FakeDb();
        $session = new ArraySession();
        $controller = $this->makeController($db, $session);
        $controller->register(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
        ]));

        $response = $controller->login(Request::create('POST', '/login', [], [
            'username' => 'alice',
            'password' => 'correct-horse',
        ]));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/recipes', $response->getHeaders()['Location']);
        $this->assertNotNull($session->get('user_id'));
    }

    public function testLoginWithWrongPasswordReRendersFormWithGenericError(): void
    {
        $db = new FakeDb();
        $session = new ArraySession();
        $controller = $this->makeController($db, $session);
        $controller->register(Request::create('POST', '/register', [], [
            'username' => 'alice',
            'email' => 'alice@example.com',
            'password' => 'correct-horse',
        ]));

        $response = $controller->login(Request::create('POST', '/login', [], [
            'username' => 'alice',
            'password' => 'wrong-password',
        ]));

        $this->assertSame(422, $response->getStatusCode());
        $this->assertStringContainsString('Incorrect username/email or password', $response->getBody());
        $this->assertNull($session->get('user_id'));
    }

    public function testLogoutClearsSessionAndRedirectsToLogin(): void
    {
        $db = new FakeDb();
        $session = new ArraySession(['user_id' => 5]);
        $controller = $this->makeController($db, $session);

        $response = $controller->logout(Request::create('POST', '/logout'));

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame('/login', $response->getHeaders()['Location']);
        $this->assertNull($session->get('user_id'));
    }
}
