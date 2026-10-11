<?php

declare(strict_types=1);

namespace Chamados\Auth;

/**
 * Segredo do 2FA criptografado no banco com AES-256-GCM (cifra + autenticação: se alguém mexer no
 * valor salvo, a decifragem falha) e códigos de recuperação guardados só como SHA-256.
 */
final class Secrets
{
    private readonly string $key;

    public function __construct(string $key)
    {
        $this->key = self::decodeKey($key) ?? throw new \InvalidArgumentException(
            'TOTP_ENCRYPTION_KEY deve ter 32 bytes: 64 caracteres hex (openssl rand -hex 32) ou base64'
        );
    }

    /** Chave de 32 bytes em hex ou base64; null se não for válida. */
    public static function decodeKey(string $s): ?string
    {
        if (preg_match('/^[0-9a-fA-F]{64}$/', $s)) {
            return (string) hex2bin($s);
        }
        if (!preg_match('#^[A-Za-z0-9+/_-]{43}=?$#', $s)) {
            return null;
        }
        $raw = base64_decode(strtr($s, '-_', '+/'), true);
        return $raw !== false && strlen($raw) === 32 ? $raw : null;
    }

    /** Formato salvo: "v1:<iv>:<tag>:<cifra>" em base64url. */
    public function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $data = openssl_encrypt($plain, 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($data === false) {
            throw new \RuntimeException('Falha ao criptografar');
        }
        return implode(':', ['v1', self::b64($iv), self::b64($tag), self::b64($data)]);
    }

    public function decrypt(string $stored): string
    {
        $parts = explode(':', $stored);
        if (count($parts) !== 4 || $parts[0] !== 'v1') {
            throw new \RuntimeException('Segredo em formato desconhecido');
        }
        $plain = openssl_decrypt(self::unb64($parts[3]), 'aes-256-gcm', $this->key, OPENSSL_RAW_DATA, self::unb64($parts[1]), self::unb64($parts[2]));
        if ($plain === false) {
            throw new \RuntimeException('Segredo adulterado ou chave errada');
        }
        return $plain;
    }

    /**
     * 10 códigos de 10 caracteres (≈ 50 bits cada), uso único. Guardados com SHA-256: são aleatórios
     * e longos, então não precisam do custo do bcrypt (que existe para senhas escolhidas por pessoas).
     *
     * @return list<string>
     */
    public static function generateRecoveryCodes(int $n = 10): array
    {
        $alphabet = 'abcdefghjkmnpqrstuvwxyz23456789'; // sem 0/o, 1/l/i
        $codes = [];
        for ($i = 0; $i < $n; $i++) {
            $c = '';
            for ($j = 0; $j < 10; $j++) {
                $c .= $alphabet[random_int(0, strlen($alphabet) - 1)];
            }
            $codes[] = substr($c, 0, 5) . '-' . substr($c, 5);
        }
        return $codes;
    }

    public static function hashRecoveryCode(string $code): string
    {
        return hash('sha256', (string) preg_replace('/[^a-z0-9]/', '', strtolower($code)));
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/'), true);
    }
}
