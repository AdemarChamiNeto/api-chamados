<?php

declare(strict_types=1);

namespace Chamados\Service;

use Chamados\Auth\InvalidToken;
use Chamados\Auth\Jwt;
use Chamados\Auth\Secrets;
use Chamados\Auth\Totp;
use Chamados\Clock;
use Chamados\Http\HttpException;
use Chamados\Http\Validator;
use Chamados\Repository\TwoFactorRepository;
use Chamados\Repository\UserRepository;

final class AuthService
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_MINUTES = 15;
    /** Erros de login por IP (qualquer email). Mais folgado que o por conta: atrás de um BFF, vários usuários dividem o IP. */
    public const MAX_ATTEMPTS_PER_IP = 20;
    /** Tempo para digitar o código do 2FA depois da senha certa. */
    public const PENDING_TTL_SECONDS = 300;

    // bcrypt custo 12 de uma senha que ninguém usa: quando o email não existe, o tempo de resposta fica igual
    private const DUMMY_HASH = '$2y$12$6wPlJDoMJ90bA05Xbv6qpeQu.2VXL3xC51Clp8lRL8C2ES1oOx7z.';

    public function __construct(
        private readonly UserRepository $users,
        private readonly TwoFactorRepository $twoFactor,
        private readonly Secrets $secrets,
        private readonly Jwt $jwt,
        private readonly Clock $clock,
    ) {
    }

    /**
     * Sem 2FA: devolve o token da sessão. Com 2FA: devolve só um token pendente (5 min) que serve
     * apenas para POST /api/auth/2fa.
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function login(array $input, string $ip): array
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $password = $v->string('password', 1, 200);
        $v->validate();

        $now = $this->clock->now();
        $since = Clock::db($now->modify('-' . self::WINDOW_MINUTES . ' minutes'));
        if ($this->users->failedLoginsSince($email, $since) >= self::MAX_ATTEMPTS
            || $this->users->failedLoginsFromIpSince($ip, $since) >= self::MAX_ATTEMPTS_PER_IP) {
            throw new HttpException(429, 'too_many_attempts', 'Muitas tentativas. Tente de novo em ' . self::WINDOW_MINUTES . ' minutos.', [], [
                'Retry-After' => (string) (self::WINDOW_MINUTES * 60),
            ]);
        }

        $row = $this->users->findByEmailWithHash($email);
        $hash = $row['password_hash'] ?? self::DUMMY_HASH;
        $ok = password_verify($password, $hash) && $row !== null && $row['active'];
        if (!$ok) {
            $this->users->recordFailedLogin($email, $ip, Clock::db($now));
            throw new HttpException(401, 'invalid_credentials', 'Email ou senha incorretos');
        }
        $this->users->clearFailedLogins($email);
        $user = UserRepository::cast($row);
        if (password_needs_rehash($hash, PASSWORD_BCRYPT, UserRepository::BCRYPT_OPTIONS)) {
            $this->users->updatePasswordHash($user['id'], UserRepository::hashPassword($password));
        }

        if ($this->twoFactor->isEnabled($user['id'])) {
            return [
                'two_factor_required' => true,
                'pending_token' => $this->jwt->issue(
                    ['sub' => $user['id'], 'purpose' => '2fa', 'exp' => $now->getTimestamp() + self::PENDING_TTL_SECONDS],
                    $now->getTimestamp(),
                ),
            ];
        }
        return ['two_factor_required' => false] + $this->session($user);
    }

    /**
     * Segundo passo do login: código de 6 dígitos do app ou um código de recuperação (uso único).
     *
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function loginSecondFactor(array $input): array
    {
        $v = new Validator($input);
        $pending = $v->string('pending_token', 10, 2000);
        $code = $v->string('code', 6, 20);
        $v->validate();

        try {
            $claims = $this->jwt->verify($pending, $this->clock->now()->getTimestamp());
        } catch (InvalidToken) {
            throw new HttpException(401, 'invalid_token', 'Faça o login de novo');
        }
        $user = ($claims['purpose'] ?? null) === '2fa' ? $this->users->find((int) ($claims['sub'] ?? 0)) : null;
        if (!$user || !$user['active']) {
            throw new HttpException(401, 'invalid_token', 'Faça o login de novo');
        }
        if (!$this->checkSecondFactor($user['id'], $code)) {
            throw new HttpException(401, 'invalid_code', 'Código inválido');
        }
        return $this->session($user) + ['recovery_codes_left' => $this->twoFactor->recoveryCodesLeft($user['id'])];
    }

    /** Confere o código do app (não pode ser um já usado) ou um código de recuperação (marca como usado). */
    public function checkSecondFactor(int $userId, string $code): bool
    {
        $t = $this->twoFactor->find($userId);
        if ($t === null || $t['enabled_at'] === null) {
            return false;
        }
        $now = $this->clock->now();
        $code = trim($code);
        if (preg_match('/^\d{6}$/', $code)) {
            $step = Totp::verify($this->secrets->decrypt($t['secret_encrypted']), $code, $now->getTimestamp(), $t['last_used_step']);
            return $step !== null && $this->twoFactor->claimStep($userId, $step, Clock::db($now));
        }
        return $this->twoFactor->useRecoveryCode($userId, Secrets::hashRecoveryCode($code), Clock::db($now));
    }

    /** @return array<string,mixed> usuário do token (relido do banco: desativar a conta corta o acesso na hora) */
    public function authenticate(?string $token): array
    {
        if ($token === null) {
            throw new HttpException(401, 'unauthenticated', 'Envie o header Authorization: Bearer <token>');
        }
        try {
            $claims = $this->jwt->verify($token, $this->clock->now()->getTimestamp());
        } catch (InvalidToken $e) {
            throw new HttpException(401, 'invalid_token', $e->getMessage());
        }
        // o token pendente do 2FA nunca vale como sessão
        if (($claims['purpose'] ?? 'session') !== 'session') {
            throw new HttpException(401, 'invalid_token', 'Token não é de sessão');
        }
        $user = $this->users->find((int) ($claims['sub'] ?? 0));
        if (!$user || !$user['active']) {
            throw new HttpException(401, 'invalid_token', 'Usuário inexistente ou desativado');
        }
        return $user;
    }

    /** @param array<string,mixed> $user @return array{token: string, user: array<string,mixed>} */
    private function session(array $user): array
    {
        return [
            'token' => $this->jwt->issue(['sub' => $user['id'], 'role' => $user['role'], 'purpose' => 'session'], $this->clock->now()->getTimestamp()),
            'user' => $user,
        ];
    }
}
