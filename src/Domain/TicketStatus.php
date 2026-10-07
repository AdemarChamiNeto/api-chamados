<?php

declare(strict_types=1);

namespace Chamados\Domain;

/**
 * Ciclo de vida do chamado. As transições permitidas dependem de quem está agindo:
 * a equipe conduz o atendimento; o solicitante pode cancelar, confirmar a solução ou reabrir.
 */
enum TicketStatus: string
{
    case Aberto = 'aberto';
    case EmAtendimento = 'em_atendimento';
    case AguardandoUsuario = 'aguardando_usuario';
    case Resolvido = 'resolvido';
    case Fechado = 'fechado';
    case Cancelado = 'cancelado';

    private const STAFF = [
        'aberto' => ['em_atendimento', 'cancelado'],
        'em_atendimento' => ['aguardando_usuario', 'resolvido'],
        'aguardando_usuario' => ['em_atendimento', 'resolvido'],
        'resolvido' => ['em_atendimento', 'fechado'],
    ];

    private const REQUESTER = [
        'aberto' => ['cancelado'],
        'resolvido' => ['fechado', 'em_atendimento'], // confirmar ou reabrir
    ];

    public function canTransitionTo(self $to, Role $role): bool
    {
        $map = $role->isStaff() ? self::STAFF : self::REQUESTER;
        return in_array($to->value, $map[$this->value] ?? [], true);
    }

    /** @return list<self> */
    public function nextFor(Role $role): array
    {
        $map = $role->isStaff() ? self::STAFF : self::REQUESTER;
        return array_map(fn (string $s) => self::from($s), $map[$this->value] ?? []);
    }

    /** Ao entrar nesses status é obrigatório escrever uma nota (o que falta / o que foi feito). */
    public function requiresNote(): bool
    {
        return $this === self::AguardandoUsuario || $this === self::Resolvido || $this === self::Cancelado;
    }

    /** O relógio do SLA de solução para enquanto espera o usuário. */
    public function pausesSla(): bool
    {
        return $this === self::AguardandoUsuario;
    }

    public function isFinal(): bool
    {
        return $this === self::Fechado || $this === self::Cancelado;
    }

    /** Status em que o chamado conta como "em aberto" para a fila. */
    public function isActive(): bool
    {
        return in_array($this, [self::Aberto, self::EmAtendimento, self::AguardandoUsuario], true);
    }
}
