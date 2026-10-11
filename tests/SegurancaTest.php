<?php

declare(strict_types=1);

namespace Chamados\Tests;

use Chamados\App;
use Chamados\Auth\Secrets;
use Chamados\Auth\Totp;
use Chamados\Clock;
use Chamados\Http\Request;
use Chamados\Http\Response;
use Chamados\Repository\UserRepository;
use PDO;
use PHPUnit\Framework\TestCase;

/** 2FA, rate limit e senhas — pela API inteira em memória (ou no MySQL com TEST_DB_DSN). */
final class SegurancaTest extends TestCase
{
    private const SECRET = 'segredo-de-teste-com-mais-de-32-caracteres';
    private const PASS = 'senha-forte-123';

    private PDO $db;
    private Clock $clock;

    protected function setUp(): void
    {
        $this->db = ApiTest::freshDatabase();
        $this->clock = new Clock();
        $this->clock->set('2026-10-07 12:00:00');
        (new UserRepository($this->db))->create('Bia', 'bia@teste.com', self::PASS, 'solicitante', '2026-01-01 00:00:00');
    }

    /** @param array<string,int> $limits */
    private function app(array $limits = []): App
    {
        return new App($this->db, ['jwt_secret' => self::SECRET, 'totp_key' => str_repeat('ab', 32), 'rate_limits' => $limits], $this->clock);
    }

    private function call(App $app, string $method, string $path, ?string $token = null, array $body = [], string $ip = '10.0.0.1'): Response
    {
        $headers = $token ? ['authorization' => "Bearer $token"] : [];
        return $app->handle(new Request($method, $path, [], $headers, $body ? json_encode($body) : '', $ip));
    }

    private function login(App $app, string $email = 'bia@teste.com', string $password = self::PASS): Response
    {
        return $this->call($app, 'POST', '/api/auth/login', body: ['email' => $email, 'password' => $password]);
    }

    // ---------- unidade ----------

    public function testTotpMatchesRfc6238Vectors(): void
    {
        $secret = Totp::base32Encode('12345678901234567890');
        foreach ([59 => '94287082', 1111111109 => '07081804', 1234567890 => '89005924', 2000000000 => '69279037'] as $t => $expected) {
            $this->assertSame($expected, Totp::codeForStep($secret, Totp::stepAt($t), 8));
        }
        $this->assertSame('12345678901234567890', Totp::base32Decode($secret));
    }

    public function testTotpWindowAndReuse(): void
    {
        $secret = Totp::generateSecret();
        $now = 1_791_400_000;
        $step = Totp::stepAt($now);
        $this->assertSame($step + 1, Totp::verify($secret, Totp::codeForStep($secret, $step + 1), $now));
        $this->assertNull(Totp::verify($secret, Totp::codeForStep($secret, $step - 2), $now));
        $this->assertNull(Totp::verify($secret, Totp::codeForStep($secret, $step), $now, $step), 'código já usado');
        $this->assertNull(Totp::verify($secret, 'abcdef', $now));
    }

    public function testSecretsEncryptAndDetectTampering(): void
    {
        $s = new Secrets(base64_encode(random_bytes(32))); // formato do generateValue do Render
        $enc = $s->encrypt('JBSWY3DPEHPK3PXP');
        $this->assertStringNotContainsString('JBSWY3DPEHPK3PXP', $enc);
        $this->assertNotSame($enc, $s->encrypt('JBSWY3DPEHPK3PXP'));
        $this->assertSame('JBSWY3DPEHPK3PXP', $s->decrypt($enc));
        $parts = explode(':', $enc);
        $parts[3][0] = $parts[3][0] === 'A' ? 'B' : 'A';
        $this->expectException(\RuntimeException::class);
        $s->decrypt(implode(':', $parts));
    }

    public function testInvalidKeyIsRejected(): void
    {
        $this->assertNull(Secrets::decodeKey('curta'));
        $this->assertNull(Secrets::decodeKey(str_repeat('ab', 16)));
        $this->expectException(\InvalidArgumentException::class);
        new Secrets('curta');
    }

    // ---------- 2FA pela API ----------

    public function testTwoFactorFullFlow(): void
    {
        $app = $this->app();
        $token = $this->login($app)->data()['token'];
        $this->assertFalse($this->call($app, 'GET', '/api/me/2fa', $token)->data()['enabled']);

        $setup = $this->call($app, 'POST', '/api/me/2fa/setup', $token)->data();
        $secret = $setup['secret'];
        $this->assertStringStartsWith('otpauth://totp/', $setup['otpauth_url']);
        $stored = $this->db->query('SELECT secret_encrypted FROM user_totp')->fetchColumn();
        $this->assertStringNotContainsString($secret, (string) $stored, 'segredo não pode ficar em texto puro');

        $this->assertSame(422, $this->call($app, 'POST', '/api/me/2fa/enable', $token, ['code' => '000000'])->status);
        $step = Totp::stepAt($this->clock->now()->getTimestamp());
        $enable = $this->call($app, 'POST', '/api/me/2fa/enable', $token, ['code' => Totp::codeForStep($secret, $step)]);
        $this->assertSame(200, $enable->status, $enable->body);
        $recovery = $enable->data()['recovery_codes'];
        $this->assertCount(10, $recovery);
        $hashes = $this->db->query('SELECT code_hash FROM recovery_codes')->fetchAll(PDO::FETCH_COLUMN);
        $this->assertNotContains($recovery[0], $hashes);

        // agora a senha certa só dá o token pendente, que não vale como sessão
        $login = $this->login($app)->data();
        $this->assertTrue($login['two_factor_required']);
        $this->assertArrayNotHasKey('token', $login);
        $this->assertSame(401, $this->call($app, 'GET', '/api/me', $login['pending_token'])->status);

        $second = fn (string $code) => $this->call($app, 'POST', '/api/auth/2fa', body: ['pending_token' => $login['pending_token'], 'code' => $code]);
        $this->assertSame(401, $second('000000')->status);
        $this->assertSame(401, $second(Totp::codeForStep($secret, $step))->status, 'código usado na ativação não vale de novo');
        $ok = $second(Totp::codeForStep($secret, $step + 1));
        $this->assertSame(200, $ok->status, $ok->body);
        $this->assertSame(200, $this->call($app, 'GET', '/api/me', $ok->data()['token'])->status);

        // código de recuperação: uma vez só, ignorando maiúsculas
        $pending = fn () => $this->login($app)->data()['pending_token'];
        $rec = $this->call($app, 'POST', '/api/auth/2fa', body: ['pending_token' => $pending(), 'code' => strtoupper($recovery[0])]);
        $this->assertSame(9, $rec->data()['recovery_codes_left']);
        $this->assertSame(401, $this->call($app, 'POST', '/api/auth/2fa', body: ['pending_token' => $pending(), 'code' => $recovery[0]])->status);

        // desativar pede senha e código
        $session = $rec->data()['token'];
        $this->assertSame(401, $this->call($app, 'POST', '/api/me/2fa/disable', $session, ['password' => 'errada-123', 'code' => $recovery[1]])->status);
        $this->assertSame(204, $this->call($app, 'POST', '/api/me/2fa/disable', $session, ['password' => self::PASS, 'code' => $recovery[2]])->status);
        $this->assertFalse($this->login($app)->data()['two_factor_required']);
    }

    public function testPendingTokenExpiresInFiveMinutes(): void
    {
        $app = $this->app();
        $token = $this->login($app)->data()['token'];
        $secret = $this->call($app, 'POST', '/api/me/2fa/setup', $token)->data()['secret'];
        $this->call($app, 'POST', '/api/me/2fa/enable', $token, ['code' => Totp::codeForStep($secret, Totp::stepAt($this->clock->now()->getTimestamp()))]);

        $pending = $this->login($app)->data()['pending_token'];
        $this->clock->advance('+6 minutes');
        $code = Totp::codeForStep($secret, Totp::stepAt($this->clock->now()->getTimestamp()));
        $this->assertSame(401, $this->call($app, 'POST', '/api/auth/2fa', body: ['pending_token' => $pending, 'code' => $code])->status);
    }

    // ---------- senhas ----------

    public function testNewPasswordsUseBcryptCost12AndOldOnesAreRehashed(): void
    {
        $hash = $this->db->query("SELECT password_hash FROM users WHERE email = 'bia@teste.com'")->fetchColumn();
        $this->assertSame(12, password_get_info((string) $hash)['options']['cost']);

        $this->db->prepare('UPDATE users SET password_hash = ? WHERE email = ?')
            ->execute([password_hash(self::PASS, PASSWORD_BCRYPT, ['cost' => 10]), 'bia@teste.com']);
        $this->assertSame(200, $this->login($this->app())->status);
        $hash = $this->db->query("SELECT password_hash FROM users WHERE email = 'bia@teste.com'")->fetchColumn();
        $this->assertSame(12, password_get_info((string) $hash)['options']['cost']);
    }

    // ---------- rate limit ----------

    public function testWriteLimitPerUserWithRetryAfter(): void
    {
        $app = $this->app(['write' => 2]);
        $token = $this->login($app)->data()['token'];
        $ticket = ['title' => 'Impressora', 'description' => 'Não imprime nada desde ontem', 'category' => 'hardware', 'impact' => 'baixo', 'urgency' => 'baixo'];
        $statuses = [];
        for ($i = 0; $i < 3; $i++) {
            $res = $this->call($app, 'POST', '/api/tickets', $token, $ticket);
            $statuses[] = $res->status;
        }
        $this->assertSame([201, 201, 429], $statuses);
        $this->assertGreaterThan(0, (int) $res->headers['Retry-After']);

        // janela nova: volta a aceitar
        $this->clock->advance('+1 minute');
        $this->assertSame(201, $this->call($app, 'POST', '/api/tickets', $token, $ticket)->status);
    }

    public function testReadLimitPerUser(): void
    {
        $app = $this->app(['read' => 3]);
        $token = $this->login($app)->data()['token'];
        $statuses = array_map(fn () => $this->call($app, 'GET', '/api/me', $token)->status, range(1, 4));
        $this->assertSame([200, 200, 200, 429], $statuses);
    }

    public function testTwoFactorCodesAreLimitedPerUserEvenFromManyIps(): void
    {
        $app = $this->app(['two_factor' => 3]);
        $token = $this->login($app)->data()['token'];
        $secret = $this->call($app, 'POST', '/api/me/2fa/setup', $token)->data()['secret'];
        $this->call($app, 'POST', '/api/me/2fa/enable', $token, ['code' => Totp::codeForStep($secret, Totp::stepAt($this->clock->now()->getTimestamp()))]);

        $pending = $this->login($app)->data()['pending_token'];
        $statuses = [];
        foreach (['10.0.0.2', '10.0.0.3', '10.0.0.4', '10.0.0.5'] as $ip) {
            $statuses[] = $this->call($app, 'POST', '/api/auth/2fa', body: ['pending_token' => $pending, 'code' => '000000'], ip: $ip)->status;
        }
        $this->assertSame([401, 401, 401, 429], $statuses);
    }

    public function testLoginFailuresAreLimitedPerIpAcrossAccounts(): void
    {
        $app = $this->app();
        $statuses = [];
        for ($i = 0; $i <= 20; $i++) {
            $statuses[] = $this->login($app, "naoexiste$i@teste.com", 'errada-123')->status;
        }
        $this->assertSame(401, $statuses[19]);
        $this->assertSame(429, $statuses[20]);
        $this->assertSame(200, $this->call($app, 'POST', '/api/auth/login', body: ['email' => 'bia@teste.com', 'password' => self::PASS], ip: '10.9.9.9')->status, 'outro IP não é afetado');
    }
}
