<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Auth\UserRepository;
use App\Db\FakeDb;
use Tests\TestCase;

final class UserRepositoryTest extends TestCase
{
    public function testInsertReturnsNewIdAndRowIsFetchableById(): void
    {
        $db = new FakeDb();
        $repo = new UserRepository($db);

        $id = $repo->insert('alice', 'alice@example.com', 'hashed-pw');

        $this->assertSame(1, $id);
        $this->assertSame(
            ['id' => 1, 'username' => 'alice', 'email' => 'alice@example.com', 'password_hash' => 'hashed-pw'],
            $repo->findById($id),
        );
    }

    public function testFindByUsernameReturnsNullWhenNotFound(): void
    {
        $repo = new UserRepository(new FakeDb());

        $this->assertNull($repo->findByUsername('nobody'));
    }

    public function testFindByEmailReturnsMatchingRow(): void
    {
        $db = new FakeDb();
        $repo = new UserRepository($db);
        $repo->insert('bob', 'bob@example.com', 'hash');

        $found = $repo->findByEmail('bob@example.com');

        $this->assertNotNull($found);
        $this->assertSame('bob', $found['username']);
    }

    public function testFindByIdReturnsNullWhenNotFound(): void
    {
        $repo = new UserRepository(new FakeDb());

        $this->assertNull($repo->findById(999));
    }
}
