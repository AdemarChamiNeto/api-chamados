<?php

declare(strict_types=1);

namespace Chamados\Domain;

use DateTimeImmutable;
use DateTimeZone;

/**
 * Regras de SLA. Prazos ficam gravados no chamado (para filtrar "atrasados" direto no SQL)
 * e são recalculados quando a prioridade muda ou quando uma pausa termina.
 */
final class Sla
{
    public function __construct(private readonly BusinessCalendar $calendar)
    {
    }

    /** @return array{response_due_at: DateTimeImmutable, resolution_due_at: DateTimeImmutable} */
    public function deadlines(Priority $priority, DateTimeImmutable $createdAt, int $pausedMinutes = 0): array
    {
        [$response, $resolution] = $priority->slaTargets();
        return [
            'response_due_at' => $this->calendar->addBusinessMinutes($createdAt, $response),
            'resolution_due_at' => $this->calendar->addBusinessMinutes($createdAt, $resolution + $pausedMinutes),
        ];
    }

    /** Minutos úteis de pausa entre o início da espera e a retomada. */
    public function pauseLength(DateTimeImmutable $pausedAt, DateTimeImmutable $resumedAt): int
    {
        return $this->calendar->businessMinutesBetween($pausedAt, $resumedAt);
    }

    /**
     * Situação do SLA de um chamado num instante.
     *
     * @param array<string,mixed> $t linha do banco (datas em UTC 'Y-m-d H:i:s')
     * @return array<string,mixed>
     */
    public function evaluate(array $t, DateTimeImmutable $now): array
    {
        $utc = new DateTimeZone('UTC');
        $d = static fn (?string $v) => $v === null ? null : new DateTimeImmutable($v, $utc);

        $responseDue = $d($t['response_due_at']);
        $resolutionDue = $d($t['resolution_due_at']);
        $firstResponse = $d($t['first_response_at'] ?? null);
        $resolved = $d($t['resolved_at'] ?? null);
        $paused = $t['paused_at'] !== null;
        $status = TicketStatus::from($t['status']);

        $responseBreached = $firstResponse ? $firstResponse > $responseDue : ($status->isActive() && $now > $responseDue);
        $resolutionBreached = match (true) {
            $resolved !== null => $resolved > $resolutionDue,
            $paused, !$status->isActive() => false,
            default => $now > $resolutionDue,
        };

        return [
            'response_due_at' => self::iso($responseDue),
            'resolution_due_at' => self::iso($resolutionDue),
            'paused' => $paused,
            'response_breached' => $responseBreached,
            'resolution_breached' => $resolutionBreached,
            'business_minutes_left' => ($paused || $resolved || !$status->isActive())
                ? null
                : ($now < $resolutionDue
                    ? $this->calendar->businessMinutesBetween($now, $resolutionDue)
                    : -$this->calendar->businessMinutesBetween($resolutionDue, $now)),
        ];
    }

    public static function iso(?DateTimeImmutable $d): ?string
    {
        return $d?->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d\TH:i:s\Z');
    }
}
