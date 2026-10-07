<?php

declare(strict_types=1);

namespace Chamados;

use DateTimeImmutable;
use DateTimeZone;

/** Relógio injetável: em produção é o horário real; nos testes, um instante fixo que dá pra avançar. */
class Clock
{
    public function __construct(private ?DateTimeImmutable $fixed = null)
    {
    }

    public function now(): DateTimeImmutable
    {
        return $this->fixed ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    public function set(string $utc): void
    {
        $this->fixed = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    }

    public function advance(string $modifier): void
    {
        $this->fixed = $this->now()->modify($modifier);
    }

    /** Formato gravado no banco. */
    public static function db(DateTimeImmutable $d): string
    {
        return $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }
}
