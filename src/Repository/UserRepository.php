<?php

declare(strict_types=1);

namespace Chamados\Repository;

use PDO;

final class UserRepository
{
    private const PUBLIC = 'id, name, email, role, active, created_at';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array<string,mixed>|null inclui password_hash */
    public function findByEmailWithHash(string $email): ?array
    {
        $st = $this->db->prepare('SELECT * FROM users WHERE email = ?');
        $st->execute([$email]);
        return $st->fetch() ?: null;
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->db->prepare('SELECT ' . self::PUBLIC . ' FROM users WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch();
        return $row ? self::cast($row) : null;
    }

    /** @return list<array<string,mixed>> */
    public function all(?string $role = null): array
    {
        $sql = 'SELECT ' . self::PUBLIC . ' FROM users';
        $args = [];
        if ($role !== null) {
            $sql .= ' WHERE role = ?';
            $args[] = $role;
        }
        $st = $this->db->prepare($sql . ' ORDER BY name');
        $st->execute($args);
        return array_map(self::cast(...), $st->fetchAll());
    }

    public function create(string $name, string $email, string $password, string $role, string $now): int
    {
        $st = $this->db->prepare('INSERT INTO users (name, email, password_hash, role, active, created_at) VALUES (?, ?, ?, ?, 1, ?)');
        $st->execute([$name, $email, password_hash($password, PASSWORD_DEFAULT), $role, $now]);
        return (int) $this->db->lastInsertId();
    }

    public function emailExists(string $email): bool
    {
        $st = $this->db->prepare('SELECT 1 FROM users WHERE email = ?');
        $st->execute([$email]);
        return (bool) $st->fetchColumn();
    }

    public function setActive(int $id, bool $active): void
    {
        $this->db->prepare('UPDATE users SET active = ? WHERE id = ?')->execute([(int) $active, $id]);
    }

    // ---- tentativas de login (freio contra força bruta) ----

    public function recordFailedLogin(string $email, string $ip, string $now): void
    {
        $this->db->prepare('INSERT INTO login_attempts (email, ip, created_at) VALUES (?, ?, ?)')->execute([$email, $ip, $now]);
    }

    public function failedLoginsSince(string $email, string $since): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM login_attempts WHERE email = ? AND created_at >= ?');
        $st->execute([$email, $since]);
        return (int) $st->fetchColumn();
    }

    public function clearFailedLogins(string $email): void
    {
        $this->db->prepare('DELETE FROM login_attempts WHERE email = ?')->execute([$email]);
    }

    /** @param array<string,mixed> $row */
    public static function cast(array $row): array
    {
        $row['id'] = (int) $row['id'];
        $row['active'] = (bool) $row['active'];
        unset($row['password_hash']);
        return $row;
    }
}
