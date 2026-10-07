<?php

declare(strict_types=1);

namespace Chamados\Auth;

/**
 * JWT HS256 (RFC 7519) — implementação pequena e sem dependência, só o necessário:
 * assinatura HMAC-SHA256, comparação em tempo constante, "alg" fixo e expiração obrigatória.
 */
final class Jwt
{
    public function __construct(private readonly string $secret, private readonly int $ttlSeconds = 8 * 3600)
    {
        if (strlen($secret) < 32) {
            throw new \InvalidArgumentException('JWT_SECRET precisa ter pelo menos 32 caracteres');
        }
    }

    /** @param array<string,mixed> $claims */
    public function issue(array $claims, ?int $now = null): string
    {
        $now ??= time();
        $header = self::b64(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        $payload = self::b64(json_encode($claims + ['iat' => $now, 'exp' => $now + $this->ttlSeconds], JSON_THROW_ON_ERROR));
        return "$header.$payload." . self::b64($this->sign("$header.$payload"));
    }

    /**
     * @return array<string,mixed> claims
     * @throws InvalidToken
     */
    public function verify(string $token, ?int $now = null): array
    {
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            throw new InvalidToken('Token malformado');
        }
        [$h, $p, $s] = $parts;
        $header = json_decode(self::unb64($h), true);
        // nunca confiar no "alg" do próprio token (ataque alg=none / troca de algoritmo)
        if (!is_array($header) || ($header['alg'] ?? null) !== 'HS256') {
            throw new InvalidToken('Algoritmo não aceito');
        }
        if (!hash_equals($this->sign("$h.$p"), self::unb64($s))) {
            throw new InvalidToken('Assinatura inválida');
        }
        $claims = json_decode(self::unb64($p), true);
        if (!is_array($claims) || !isset($claims['exp']) || !is_int($claims['exp'])) {
            throw new InvalidToken('Token sem expiração');
        }
        if (($now ?? time()) >= $claims['exp']) {
            throw new InvalidToken('Token expirado');
        }
        return $claims;
    }

    private function sign(string $data): string
    {
        return hash_hmac('sha256', $data, $this->secret, true);
    }

    private static function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private static function unb64(string $s): string
    {
        $raw = base64_decode(strtr($s, '-_', '+/'), true);
        return $raw === false ? '' : $raw;
    }
}
