<?php

declare(strict_types=1);

namespace App\Auth;

use App\Db\DbInterface;

/**
 * PDO-backed (via DbInterface) access to the `users` table.
 */
final class UserRepository
{
    public function __construct(private readonly DbInterface $db)
    {
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByUsername(string $username): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE username = ?', [$username]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findByEmail(string $email): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE email = ?', [$email]);
    }

    /**
     * @return array<string, mixed>|null
     */
    public function findById(int $id): ?array
    {
        return $this->db->fetchOne('SELECT * FROM users WHERE id = ?', [$id]);
    }

    public function insert(string $username, string $email, string $passwordHash): int
    {
        $this->db->execute(
            'INSERT INTO users (username, email, password_hash) VALUES (?, ?, ?)',
            [$username, $email, $passwordHash],
        );

        return (int) $this->db->lastInsertId();
    }
}
