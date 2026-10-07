<?php

declare(strict_types=1);

namespace Chamados\Service;

use Chamados\Auth\InvalidToken;
use Chamados\Auth\Jwt;
use Chamados\Clock;
use Chamados\Http\HttpException;
use Chamados\Http\Validator;
use Chamados\Repository\UserRepository;

final class AuthService
{
    public const MAX_ATTEMPTS = 5;
    public const WINDOW_MINUTES = 15;

    public function __construct(
        private readonly UserRepository $users,
        private readonly Jwt $jwt,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $input
     * @return array{token: string, user: array<string,mixed>}
     */
    public function login(array $input, string $ip): array
    {
        $v = new Validator($input);
        $email = $v->email('email');
        $password = $v->string('password', 1, 200);
        $v->validate();

        $now = $this->clock->now();
        $since = Clock::db($now->modify('-' . self::WINDOW_MINUTES . ' minutes'));
        if ($this->users->failedLoginsSince($email, $since) >= self::MAX_ATTEMPTS) {
            throw new HttpException(429, 'too_many_attempts', 'Muitas tentativas. Tente de novo em ' . self::WINDOW_MINUTES . ' minutos.');
        }

        $row = $this->users->findByEmailWithHash($email);
        // compara com um hash qualquer quando o email não existe: o tempo de resposta não denuncia quais emails são válidos
        $hash = $row['password_hash'] ?? '$2y$10$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';
        $ok = password_verify($password, $hash) && $row !== null && $row['active'];
        if (!$ok) {
            $this->users->recordFailedLogin($email, $ip, Clock::db($now));
            throw new HttpException(401, 'invalid_credentials', 'Email ou senha incorretos');
        }
        $this->users->clearFailedLogins($email);
        $user = UserRepository::cast($row);
        return [
            'token' => $this->jwt->issue(['sub' => $user['id'], 'role' => $user['role']], $now->getTimestamp()),
            'user' => $user,
        ];
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
        $user = $this->users->find((int) ($claims['sub'] ?? 0));
        if (!$user || !$user['active']) {
            throw new HttpException(401, 'invalid_token', 'Usuário inexistente ou desativado');
        }
        return $user;
    }
}
