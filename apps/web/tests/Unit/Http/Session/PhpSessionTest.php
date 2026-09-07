<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Session;

use App\Http\Session\PhpSession;
use Tests\TestCase;

final class PhpSessionTest extends TestCase
{
    protected function tearDown(): void
    {
        // Fully end the session (not just clear $_SESSION) so each test
        // method gets a fresh session_start() call — this keeps tests
        // independent of execution order and exercises the "not already
        // active" branch of PhpSession::__construct() every time.
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_write_close();
        }

        $_SESSION = [];
    }

    public function testGetSetHasRemoveWrapUnderlyingSuperglobal(): void
    {
        $session = new PhpSession();

        $this->assertFalse($session->has('user_id'));
        $this->assertNull($session->get('user_id'));
        $this->assertSame('default', $session->get('user_id', 'default'));

        $session->set('user_id', 9);

        $this->assertTrue($session->has('user_id'));
        $this->assertSame(9, $session->get('user_id'));
        $this->assertSame(9, $_SESSION['user_id']);

        $session->remove('user_id');

        $this->assertFalse($session->has('user_id'));
        $this->assertArrayNotHasKey('user_id', $_SESSION);
    }

    public function testConstructingTwiceReusesActiveSession(): void
    {
        $first = new PhpSession();
        $first->set('shared', 'value');

        $second = new PhpSession();

        $this->assertSame('value', $second->get('shared'));
    }

    public function testRegenerateIdDoesNotThrow(): void
    {
        $session = new PhpSession();
        $session->set('keep', 'me');
        $session->regenerateId();

        $this->assertSame('me', $session->get('keep'));
    }

    public function testConstructorAcceptsCustomSessionNameOnFirstStart(): void
    {
        $session = new PhpSession('custom_session_name');
        $session->set('x', 1);

        $this->assertSame(1, $session->get('x'));
        $this->assertSame('custom_session_name', session_name());
    }
}
