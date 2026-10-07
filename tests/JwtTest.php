<?php

declare(strict_types=1);

namespace Chamados\Tests;

use Chamados\Auth\InvalidToken;
use Chamados\Auth\Jwt;
use PHPUnit\Framework\TestCase;

final class JwtTest extends TestCase
{
    private const SECRET = 'segredo-de-teste-com-mais-de-32-caracteres';

    public function testRoundTrip(): void
    {
        $jwt = new Jwt(self::SECRET, 3600);
        $claims = $jwt->verify($jwt->issue(['sub' => 7, 'role' => 'admin'], 1000), 2000);
        $this->assertSame(7, $claims['sub']);
        $this->assertSame(4600, $claims['exp']);
    }

    public function testExpired(): void
    {
        $jwt = new Jwt(self::SECRET, 60);
        $this->expectException(InvalidToken::class);
        $this->expectExceptionMessage('expirado');
        $jwt->verify($jwt->issue(['sub' => 1], 1000), 1060);
    }

    public function testTamperedPayloadIsRejected(): void
    {
        $jwt = new Jwt(self::SECRET);
        [$h, , $s] = explode('.', $jwt->issue(['sub' => 1, 'role' => 'solicitante']));
        $forged = rtrim(strtr(base64_encode(json_encode(['sub' => 1, 'role' => 'admin', 'exp' => time() + 999])), '+/', '-_'), '=');
        $this->expectException(InvalidToken::class);
        $jwt->verify("$h.$forged.$s");
    }

    public function testAlgNoneIsRejected(): void
    {
        $jwt = new Jwt(self::SECRET);
        $b64 = fn (array $x) => rtrim(strtr(base64_encode(json_encode($x)), '+/', '-_'), '=');
        $this->expectException(InvalidToken::class);
        $this->expectExceptionMessage('Algoritmo');
        $jwt->verify($b64(['alg' => 'none']) . '.' . $b64(['sub' => 1, 'exp' => time() + 99]) . '.');
    }

    public function testOtherSecretIsRejected(): void
    {
        $token = (new Jwt(str_repeat('a', 32)))->issue(['sub' => 1]);
        $this->expectException(InvalidToken::class);
        (new Jwt(str_repeat('b', 32)))->verify($token);
    }

    public function testShortSecretIsRefused(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new Jwt('curto');
    }
}
