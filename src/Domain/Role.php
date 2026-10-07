<?php

declare(strict_types=1);

namespace Chamados\Domain;

enum Role: string
{
    case Solicitante = 'solicitante';
    case Tecnico = 'tecnico';
    case Admin = 'admin';

    /** Equipe de atendimento (vê todos os chamados e comentários internos). */
    public function isStaff(): bool
    {
        return $this !== self::Solicitante;
    }
}
