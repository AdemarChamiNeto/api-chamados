<?php

declare(strict_types=1);

namespace Chamados\Auth;

/**
 * TOTP (RFC 6238) com HMAC-SHA1, 6 dígitos e passo de 30 s — o padrão do Google Authenticator,
 * Authy e Microsoft Authenticator. Sem dependência: hash_hmac do próprio PHP.
 */
final class Totp
{
    public const STEP_SECONDS = 30;
    public const DIGITS = 6;
    private const ALPHABET = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';

    /** Segredo novo de 160 bits (o tamanho que a RFC recomenda para SHA-1), em base32. */
    public static function generateSecret(): string
    {
        return self::base32Encode(random_bytes(20));
    }

    public static function stepAt(int $unixTime): int
    {
        return intdiv($unixTime, self::STEP_SECONDS);
    }

    /** Código do passo (HOTP da RFC 4226 com o contador = passo de tempo). */
    public static function codeForStep(string $secret, int $step, int $digits = self::DIGITS): string
    {
        $hmac = hash_hmac('sha1', pack('J', $step), self::base32Decode($secret), true);
        $offset = ord($hmac[19]) & 0xf;
        $bin = unpack('N', substr($hmac, $offset, 4))[1] & 0x7fffffff;
        return str_pad((string) ($bin % 10 ** $digits), $digits, '0', STR_PAD_LEFT);
    }

    /**
     * Confere o código aceitando 1 passo antes/depois (relógio do celular adiantado/atrasado).
     * Devolve o passo usado; quem chama guarda e recusa passo igual ou anterior (o código não pode ser reusado).
     */
    public static function verify(string $secret, string $code, int $unixTime, ?int $lastUsedStep = null): ?int
    {
        if (!preg_match('/^\d{6}$/', $code)) {
            return null;
        }
        $current = self::stepAt($unixTime);
        foreach ([$current - 1, $current, $current + 1] as $step) {
            if ($lastUsedStep !== null && $step <= $lastUsedStep) {
                continue;
            }
            if (hash_equals(self::codeForStep($secret, $step), $code)) {
                return $step;
            }
        }
        return null;
    }

    /** URL que vira o QR code lido pelo app autenticador. */
    public static function otpauthUrl(string $secret, string $account, string $issuer = 'Portal de Chamados'): string
    {
        $label = rawurlencode("$issuer:$account");
        $query = http_build_query(['secret' => $secret, 'issuer' => $issuer, 'algorithm' => 'SHA1', 'digits' => self::DIGITS, 'period' => self::STEP_SECONDS], '', '&', PHP_QUERY_RFC3986);
        return "otpauth://totp/$label?$query";
    }

    public static function base32Encode(string $raw): string
    {
        $bits = '';
        foreach (str_split($raw) as $c) {
            $bits .= str_pad(decbin(ord($c)), 8, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec(str_pad($chunk, 5, '0'))];
        }
        return $out;
    }

    public static function base32Decode(string $s): string
    {
        $clean = strtoupper(preg_replace('/[\s=]/', '', $s) ?? '');
        $bits = '';
        foreach (str_split($clean) as $c) {
            $i = strpos(self::ALPHABET, $c);
            if ($i === false) {
                throw new \InvalidArgumentException('Segredo base32 inválido');
            }
            $bits .= str_pad(decbin($i), 5, '0', STR_PAD_LEFT);
        }
        $out = '';
        foreach (str_split($bits, 8) as $byte) {
            if (strlen($byte) === 8) {
                $out .= chr(bindec($byte));
            }
        }
        return $out;
    }
}
