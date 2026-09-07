<?php

declare(strict_types=1);

namespace Tests\Integration\Auth;

use App\Auth\AuthService;
use App\Auth\Exception\DuplicateEmailException;
use App\Auth\Exception\DuplicateUsernameException;
use App\Auth\Exception\InvalidCredentialsException;
use App\Auth\UserRepository;
use App\Http\Session\ArraySession;
use Tests\Integration\IntegrationTestCase;

/**
 * Exercises AuthService/UserRepository against the real Dockerized MySQL
 * test database (task V4), proving the SQL actually works against MySQL's
 * dialect (unique constraints, real password hashing round-trip), not
 * just against FakeDb's emulation.
 */
final class AuthServiceIntegrationTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Each test starts from a clean `users` table so username/email
        // uniqueness assertions are independent of prior test runs.
        self::db()->execute('DELETE FROM users');
    }

    private function makeService(ArraySession $session = new ArraySession()): AuthService
    {
        return new AuthService(new UserRepository(self::db()), $session);
    }

    public function testRegisterPersistsUserWithHashedPasswordInRealMysql(): void
    {
        $service = $this->makeService();

        $user = $service->register('alice', 'alice@example.com', 'correct-horse-battery');

        $row = self::db()->fetchOne('SELECT * FROM users WHERE id = ?', [$user['id']]);

        $this->assertNotNull($row);
        $this->assertSame('alice', $row['username']);
        $this->assertSame('alice@example.com', $row['email']);
        $this->assertTrue(password_verify('correct-horse-battery', (string) $row['password_hash']));
    }

    public function testRegisterRejectsDuplicateUsernameAgainstRealMysql(): void
    {
        $service = $this->makeService();
        $service->register('alice', 'alice@example.com', 'correct-horse-battery');

        $this->expectException(DuplicateUsernameException::class);
        $service->register('alice', 'different@example.com', 'another-password');
    }

    public function testRegisterRejectsDuplicateEmailAgainstRealMysql(): void
    {
        $service = $this->makeService();
        $service->register('alice', 'alice@example.com', 'correct-horse-battery');

        $this->expectException(DuplicateEmailException::class);
        $service->register('different-username', 'alice@example.com', 'another-password');
    }

    public function testLoginSucceedsAgainstRealMysqlAndSetsSession(): void
    {
        $session = new ArraySession();
        $service = $this->makeService($session);
        $user = $service->register('alice', 'alice@example.com', 'correct-horse-battery');

        $service->login('alice', 'correct-horse-battery');

        $this->assertSame((int) $user['id'], $session->get('user_id'));
    }

    public function testLoginFailsWithWrongPasswordAgainstRealMysql(): void
    {
        $service = $this->makeService();
        $service->register('alice', 'alice@example.com', 'correct-horse-battery');

        $this->expectException(InvalidCredentialsException::class);
        $service->login('alice', 'wrong-password');
    }

    public function testLogoutClearsSessionAfterRealMysqlLogin(): void
    {
        $session = new ArraySession();
        $service = $this->makeService($session);
        $service->register('alice', 'alice@example.com', 'correct-horse-battery');
        $service->login('alice', 'correct-horse-battery');

        $service->logout();

        $this->assertNull($session->get('user_id'));
    }
}
