<?php

declare(strict_types=1);

namespace Chamados\Domain;

enum Priority: string
{
    case Critica = 'critica';
    case Alta = 'alta';
    case Media = 'media';
    case Baixa = 'baixa';

    /**
     * Matriz impacto × urgência do ITIL: quem abre o chamado informa o quanto afeta e a pressa,
     * e a prioridade sai daqui — evita todo mundo marcar "urgente".
     */
    public static function fromMatrix(Level $impact, Level $urgency): self
    {
        return match ($impact->weight() + $urgency->weight()) {
            6 => self::Critica,
            5 => self::Alta,
            4 => self::Media,
            default => self::Baixa,
        };
    }

    /** Metas de SLA em minutos úteis: [primeira resposta, solução]. */
    public function slaTargets(): array
    {
        return match ($this) {
            self::Critica => [30, 4 * 60],
            self::Alta => [60, 9 * 60],
            self::Media => [4 * 60, 18 * 60],
            self::Baixa => [9 * 60, 45 * 60],
        };
    }

    public function weight(): int
    {
        return match ($this) {
            self::Critica => 4, self::Alta => 3, self::Media => 2, self::Baixa => 1,
        };
    }
}
