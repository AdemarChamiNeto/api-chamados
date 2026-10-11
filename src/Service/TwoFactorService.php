<?php

declare(strict_types=1);

namespace Chamados\Service;

use Chamados\Auth\Secrets;
use Chamados\Auth\Totp;
use Chamados\Clock;
use Chamados\Http\HttpException;
use Chamados\Http\Validator;
use Chamados\Repository\TwoFactorRepository;
use Chamados\Repository\UserRepository;

/** 2FA opcional: o próprio usuário ativa (QR code + primeiro código) e desativa (senha + código). */
final class TwoFactorService
{
    public function __construct(
        private readonly TwoFactorRepository $repo,
        private readonly UserRepository $users,
        private readonly AuthService $auth,
        private readonly Secrets $secrets,
        private readonly Clock $clock,
    ) {
    }

    /** @param array<string,mixed> $user @return array{enabled: bool} */
    public function status(array $user): array
    {
        return ['enabled' => $this->repo->isEnabled($user['id'])];
    }

    /**
     * Passo 1: gera o segredo e a URL do QR code. Só passa a valer depois de confirmado com um código.
     *
     * @param array<string,mixed> $user
     * @return array{secret: string, otpauth_url: string}
     */
    public function setup(array $user): array
    {
        if ($this->repo->isEnabled($user['id'])) {
            throw new HttpException(409, 'already_enabled', 'O 2FA já está ativo');
        }
        $secret = Totp::generateSecret();
        $this->repo->savePending($user['id'], $this->secrets->encrypt($secret), Clock::db($this->clock->now()));
        return ['secret' => $secret, 'otpauth_url' => Totp::otpauthUrl($secret, $user['email'])];
    }

    /**
     * Passo 2: confirma com o primeiro código do app e devolve os códigos de recuperação (mostrados uma vez só).
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array{recovery_codes: list<string>}
     */
    public function enable(array $user, array $input): array
    {
        $v = new Validator($input);
        $code = $v->string('code', 6, 6);
        $v->validate();

        $t = $this->repo->find($user['id']) ?? throw new HttpException(409, 'no_setup', 'Gere o QR code primeiro');
        if ($t['enabled_at'] !== null) {
            throw new HttpException(409, 'already_enabled', 'O 2FA já está ativo');
        }
        $now = $this->clock->now();
        $step = Totp::verify($this->secrets->decrypt($t['secret_encrypted']), $code, $now->getTimestamp());
        if ($step === null) {
            throw HttpException::validation(['code' => 'Código inválido. Confira o horário do celular e tente de novo.']);
        }
        $codes = Secrets::generateRecoveryCodes();
        $this->repo->enable($user['id'], $step, array_map(Secrets::hashRecoveryCode(...), $codes), Clock::db($now));
        return ['recovery_codes' => $codes];
    }

    /**
     * Desativar pede a senha e um código (do app ou de recuperação): quem pegou só o token não consegue.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     */
    public function disable(array $user, array $input): void
    {
        $v = new Validator($input);
        $password = $v->string('password', 1, 200);
        $code = $v->string('code', 6, 20);
        $v->validate();

        $hash = $this->users->passwordHash($user['id']) ?? '';
        if (!password_verify($password, $hash) || !$this->auth->checkSecondFactor($user['id'], $code)) {
            throw new HttpException(401, 'invalid_credentials', 'Senha ou código incorretos');
        }
        $this->repo->disable($user['id']);
    }
}
