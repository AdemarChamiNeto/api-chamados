<?php

declare(strict_types=1);

namespace Chamados\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Horário comercial: seg–sex, 08:00–12:00 e 13:00–18:00 (9 h/dia), no fuso de São Paulo.
 * Fins de semana e feriados configurados não contam. É o relógio do SLA.
 */
final class BusinessCalendar
{
    /** @var list<array{int,int}> janelas do dia em minutos desde 00:00 */
    private const WINDOWS = [[8 * 60, 12 * 60], [13 * 60, 18 * 60]];

    private readonly DateTimeZone $tz;
    /** @var array<string,true> */
    private readonly array $holidays;

    /** @param list<string> $holidays datas AAAA-MM-DD */
    public function __construct(array $holidays = [], string $timezone = 'America/Sao_Paulo')
    {
        $this->tz = new DateTimeZone($timezone);
        $this->holidays = array_fill_keys($holidays, true);
    }

    public function isWorkingDay(DateTimeImmutable $day): bool
    {
        $local = $day->setTimezone($this->tz);
        return (int) $local->format('N') <= 5 && !isset($this->holidays[$local->format('Y-m-d')]);
    }

    /** Soma minutos úteis a partir de $start. Se $start cai fora do expediente, conta a partir da próxima janela. */
    public function addBusinessMinutes(DateTimeImmutable $start, int $minutes): DateTimeImmutable
    {
        if ($minutes < 0) {
            throw new \InvalidArgumentException('minutes deve ser >= 0');
        }
        $cursor = $start->setTimezone($this->tz);
        $left = $minutes;

        for ($guard = 0; $guard < 3660; $guard++) { // ~10 anos de dias: proteção contra laço infinito
            if ($this->isWorkingDay($cursor)) {
                $now = $this->minuteOfDay($cursor);
                foreach (self::WINDOWS as [$from, $to]) {
                    if ($now >= $to) {
                        continue;
                    }
                    $begin = max($now, $from);
                    $available = $to - $begin;
                    if ($left <= $available) {
                        return $this->atMinute($cursor, $begin + $left)->setTimezone($start->getTimezone());
                    }
                    $left -= $available;
                    $now = $to;
                }
            }
            $cursor = $cursor->modify('+1 day')->setTime(0, 0);
        }
        throw new \RuntimeException('Não foi possível calcular o prazo (calendário sem dias úteis?)');
    }

    /** Minutos úteis entre dois instantes (0 se $to <= $from). */
    public function businessMinutesBetween(DateTimeImmutable $from, DateTimeImmutable $to): int
    {
        if ($to <= $from) {
            return 0;
        }
        $a = $from->setTimezone($this->tz);
        $b = $to->setTimezone($this->tz);
        $total = 0;
        $day = $a->setTime(0, 0);
        $lastDay = $b->format('Y-m-d');

        while ($day->format('Y-m-d') <= $lastDay) {
            if ($this->isWorkingDay($day)) {
                $start = $day->format('Y-m-d') === $a->format('Y-m-d') ? $this->minuteOfDay($a) : 0;
                $end = $day->format('Y-m-d') === $lastDay ? $this->minuteOfDay($b) : 24 * 60;
                foreach (self::WINDOWS as [$from_, $to_]) {
                    $total += max(0, min($end, $to_) - max($start, $from_));
                }
            }
            $day = $day->modify('+1 day');
        }
        return $total;
    }

    private function minuteOfDay(DateTimeImmutable $d): int
    {
        return (int) $d->format('G') * 60 + (int) $d->format('i');
    }

    private function atMinute(DateTimeImmutable $day, int $minute): DateTimeImmutable
    {
        return $day->setTime(intdiv($minute, 60), $minute % 60);
    }
}
