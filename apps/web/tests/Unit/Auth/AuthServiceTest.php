<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\AuthService;
use App\Auth\Exception\DuplicateEmailException;
use App\Auth\Exception\DuplicateUsernameException;
use App\Auth\Exception\InvalidCredentialsException;
use App\Auth\UserRepository;
use App\Db\FakeDb;
use App\Http\Session\ArraySession;
use Tests\TestCase;

final class AuthServiceTest extends TestCase
{
    private function makeService(FakeDb $db, ArraySession $session): AuthService
    {
        return new AuthService(new UserRepository($db), $session);
    }

    public function testRegisterCreatesUserWithHashedPassword(): void
    {
        $db = new FakeDb();
        $service = $this->makeService($db, new ArraySession());

        $user = $service->register('alice', 'alice@example.com', 'correct-horse');

        $this->assertSame('alice', $user['username']);
        $this->assertSame('alice@example.com', $user['email']);
        $this->assertNotSame('correct-horse', $user['password_hash']);
        $this->assertTrue(password_verify('correct-horse', (string) $user['password_hash']));
    }

    public function testRegisterRejectsDuplicateUsername(): void
    {
        $db = new FakeDb();
        $service = $this->makeService($db, new ArraySession());
        $service->register('alice', 'alice@example.com', 'correct-horse');

        $this->expectException(DuplicateUsernameException::class);
        $service->register('alice', 'someone-else@example.com', 'another-pass');
    }

    public function testRegisterRejectsDuplicateEmail(): void
    {
        $db = new FakeDb();
        $service = $this->makeService($db, new ArraySession());
        $service->register('alice', 'alice@example.com', 'correct-horse');

        $this->expectException(DuplicateEmailException::class);
        $service->register('someone-else', 'alice@example.com', 'another-pass');
    }

    public function testLoginByUsernameSetsSessionUserIdAndRegeneratesSessionId(): void
    {
        $db = new FakeDb();
        $session = new ArraySession();
        $service = $this->makeService($db, $session);
        $user = $service->register('alice', 'alice@example.com', 'correct-horse');

        $result = $service->login('alice', 'correct-horse');

        $this->assertSame((int) $user['id'], $session->get('user_id'));
        $this->assertSame((int) $user['id'], (int) $result['id']);
    }

    public function testLoginByEmailAlsoSucceeds(): void
    {
        $db = new FakeDb();
        $session = new ArraySession();
        $service = $this->makeService($db, $session);
        $user = $service->register('alice', 'alice@example.com', 'correct-horse');

        $service->login('alice@example.com', 'correct-horse');

        $this->assertSame((int) $user['id'], $session->get('user_id'));
    }

    public function testLoginRejectsWrongPassword(): void
    {
        $db = new FakeDb();
        $session = new ArraySession();
        $service = $this->makeService($db, $session);
        $service->register('alice', 'alice@example.com', 'correct-horse');

        $this->expectException(InvalidCredentialsException::class);

        try {
            $service->login('alice', 'wrong-password');
        } finally {
            $this->assertNull($session->get('user_id'), 'A failed login must not set the session user.');
        }
    }

    public function testLoginRejectsUnknownUsernameOrEmail(): void
    {
        $service = $this->makeService(new FakeDb(), new ArraySession());

        $this->expectException(InvalidCredentialsException::class);
        $service->login('nobody', 'whatever');
    }

    public function testLogoutClearsSessionUserId(): void
    {
        $db = new FakeDb();
        $session = new ArraySession();
        $service = $this->makeService($db, $session);
        $service->register('alice', 'alice@example.com', 'correct-horse');
        $service->login('alice', 'correct-horse');

        $service->logout();

        $this->assertNull($session->get('user_id'));
    }
}
