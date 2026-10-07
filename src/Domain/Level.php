<?php

declare(strict_types=1);

namespace Chamados\Domain;

/** Nível de impacto ou de urgência informado na abertura. */
enum Level: string
{
    case Alto = 'alto';
    case Medio = 'medio';
    case Baixo = 'baixo';

    public function weight(): int
    {
        return match ($this) {
            self::Alto => 3, self::Medio => 2, self::Baixo => 1,
        };
    }
}
