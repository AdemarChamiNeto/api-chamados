<?php

declare(strict_types=1);

namespace Chamados\Repository;

use PDO;

/** Credencial do 2FA e códigos de recuperação. Única porta de acesso a essas tabelas. */
final class TwoFactorRepository
{
    public function __construct(private readonly PDO $db)
    {
    }

    /** @return array{user_id: int, secret_encrypted: string, enabled_at: ?string, last_used_step: ?int}|null */
    public function find(int $userId): ?array
    {
        $st = $this->db->prepare('SELECT user_id, secret_encrypted, enabled_at, last_used_step FROM user_totp WHERE user_id = ?');
        $st->execute([$userId]);
        $row = $st->fetch();
        if (!$row) {
            return null;
        }
        $row['user_id'] = (int) $row['user_id'];
        $row['last_used_step'] = $row['last_used_step'] === null ? null : (int) $row['last_used_step'];
        return $row;
    }

    public function isEnabled(int $userId): bool
    {
        return ($this->find($userId)['enabled_at'] ?? null) !== null;
    }

    /** Grava (ou troca) o segredo ainda não confirmado. */
    public function savePending(int $userId, string $secretEncrypted, string $now): void
    {
        $this->db->prepare('DELETE FROM user_totp WHERE user_id = ? AND enabled_at IS NULL')->execute([$userId]);
        $this->db->prepare('INSERT INTO user_totp (user_id, secret_encrypted, created_at, updated_at) VALUES (?, ?, ?, ?)')
            ->execute([$userId, $secretEncrypted, $now, $now]);
    }

    /** Ativa e troca os códigos de recuperação, na mesma transação. @param list<string> $codeHashes */
    public function enable(int $userId, int $step, array $codeHashes, string $now): void
    {
        $this->db->beginTransaction();
        try {
            $this->db->prepare('UPDATE user_totp SET enabled_at = ?, last_used_step = ?, updated_at = ? WHERE user_id = ?')
                ->execute([$now, $step, $now, $userId]);
            $this->db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
            $ins = $this->db->prepare('INSERT INTO recovery_codes (id, user_id, code_hash, created_at, updated_at) VALUES (?, ?, ?, ?, ?)');
            foreach ($codeHashes as $hash) {
                $ins->execute([self::uuid(), $userId, $hash, $now, $now]);
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }

    /** Grava o passo usado só se ninguém usou um igual ou maior no meio tempo (duas requisições ao mesmo tempo). */
    public function claimStep(int $userId, int $step, string $now): bool
    {
        $st = $this->db->prepare('UPDATE user_totp SET last_used_step = ?, updated_at = ?
            WHERE user_id = ? AND enabled_at IS NOT NULL AND (last_used_step IS NULL OR last_used_step < ?)');
        $st->execute([$step, $now, $userId, $step]);
        return $st->rowCount() === 1;
    }

    /** Marca o código de recuperação como usado; false se não existe ou já foi usado. */
    public function useRecoveryCode(int $userId, string $codeHash, string $now): bool
    {
        $st = $this->db->prepare('UPDATE recovery_codes SET used_at = ?, updated_at = ? WHERE user_id = ? AND code_hash = ? AND used_at IS NULL');
        $st->execute([$now, $now, $userId, $codeHash]);
        return $st->rowCount() === 1;
    }

    public function recoveryCodesLeft(int $userId): int
    {
        $st = $this->db->prepare('SELECT COUNT(*) FROM recovery_codes WHERE user_id = ? AND used_at IS NULL');
        $st->execute([$userId]);
        return (int) $st->fetchColumn();
    }

    public function disable(int $userId): void
    {
        $this->db->prepare('DELETE FROM recovery_codes WHERE user_id = ?')->execute([$userId]);
        $this->db->prepare('DELETE FROM user_totp WHERE user_id = ?')->execute([$userId]);
    }

    private static function uuid(): string
    {
        $b = random_bytes(16);
        $b[6] = chr((ord($b[6]) & 0x0f) | 0x40); // versão 4
        $b[8] = chr((ord($b[8]) & 0x3f) | 0x80); // variante RFC 4122
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($b), 4));
    }
}
