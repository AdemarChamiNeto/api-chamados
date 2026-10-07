<?php

declare(strict_types=1);

namespace Chamados\Tests;

use Chamados\Domain\Level;
use Chamados\Domain\Priority;
use Chamados\Domain\Role;
use Chamados\Domain\TicketStatus as S;
use PHPUnit\Framework\TestCase;

final class DomainTest extends TestCase
{
    public function testItilMatrix(): void
    {
        $p = fn (Level $i, Level $u) => Priority::fromMatrix($i, $u);
        $this->assertSame(Priority::Critica, $p(Level::Alto, Level::Alto));
        $this->assertSame(Priority::Alta, $p(Level::Alto, Level::Medio));
        $this->assertSame(Priority::Alta, $p(Level::Medio, Level::Alto));
        $this->assertSame(Priority::Media, $p(Level::Medio, Level::Medio));
        $this->assertSame(Priority::Media, $p(Level::Alto, Level::Baixo));
        $this->assertSame(Priority::Baixa, $p(Level::Medio, Level::Baixo));
        $this->assertSame(Priority::Baixa, $p(Level::Baixo, Level::Baixo));
    }

    public function testSlaTargetsGetLongerAsPriorityDrops(): void
    {
        $prev = [0, 0];
        foreach ([Priority::Critica, Priority::Alta, Priority::Media, Priority::Baixa] as $p) {
            [$resp, $res] = $p->slaTargets();
            $this->assertGreaterThan($prev[0], $resp);
            $this->assertGreaterThan($prev[1], $res);
            $this->assertGreaterThan($resp, $res);
            $prev = [$resp, $res];
        }
    }

    public function testStaffTransitions(): void
    {
        $t = Role::Tecnico;
        $this->assertTrue(S::Aberto->canTransitionTo(S::EmAtendimento, $t));
        $this->assertTrue(S::EmAtendimento->canTransitionTo(S::AguardandoUsuario, $t));
        $this->assertTrue(S::Resolvido->canTransitionTo(S::EmAtendimento, $t));
        $this->assertFalse(S::Aberto->canTransitionTo(S::Resolvido, $t), 'precisa atender antes de resolver');
        $this->assertFalse(S::Fechado->canTransitionTo(S::EmAtendimento, Role::Admin), 'fechado é final');
        $this->assertFalse(S::Cancelado->canTransitionTo(S::Aberto, Role::Admin));
    }

    public function testRequesterTransitions(): void
    {
        $r = Role::Solicitante;
        $this->assertTrue(S::Aberto->canTransitionTo(S::Cancelado, $r));
        $this->assertTrue(S::Resolvido->canTransitionTo(S::Fechado, $r));
        $this->assertTrue(S::Resolvido->canTransitionTo(S::EmAtendimento, $r), 'reabrir');
        $this->assertFalse(S::Aberto->canTransitionTo(S::EmAtendimento, $r));
        $this->assertFalse(S::EmAtendimento->canTransitionTo(S::Resolvido, $r));
        $this->assertSame([], S::AguardandoUsuario->nextFor($r), 'responde comentando, não mudando o status');
    }

    public function testFlags(): void
    {
        $this->assertTrue(S::AguardandoUsuario->pausesSla());
        $this->assertTrue(S::Resolvido->requiresNote());
        $this->assertFalse(S::EmAtendimento->requiresNote());
        $this->assertTrue(Role::Admin->isStaff());
        $this->assertFalse(Role::Solicitante->isStaff());
    }
}
