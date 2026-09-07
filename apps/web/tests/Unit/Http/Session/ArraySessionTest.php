<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Session;

use App\Http\Session\ArraySession;
use Tests\TestCase;

final class ArraySessionTest extends TestCase
{
    public function testGetSetHasRemove(): void
    {
        $session = new ArraySession();

        $this->assertFalse($session->has('user_id'));
        $this->assertNull($session->get('user_id'));
        $this->assertSame('default', $session->get('user_id', 'default'));

        $session->set('user_id', 5);

        $this->assertTrue($session->has('user_id'));
        $this->assertSame(5, $session->get('user_id'));

        $session->remove('user_id');

        $this->assertFalse($session->has('user_id'));
    }

    public function testConstructorAcceptsInitialData(): void
    {
        $session = new ArraySession(['foo' => 'bar']);

        $this->assertSame('bar', $session->get('foo'));
        $this->assertSame(['foo' => 'bar'], $session->all());
    }

    public function testRegenerateIdIsNoOp(): void
    {
        $session = new ArraySession(['foo' => 'bar']);
        $session->regenerateId();

        $this->assertSame('bar', $session->get('foo'));
    }
}
