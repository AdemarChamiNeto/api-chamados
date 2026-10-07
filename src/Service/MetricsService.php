<?php

declare(strict_types=1);

namespace Chamados\Service;

use Chamados\Clock;
use Chamados\Repository\TicketRepository;

/** Indicadores do service desk: fila, atrasos e cumprimento de SLA nos últimos N dias. */
final class MetricsService
{
    public function __construct(private readonly TicketRepository $tickets, private readonly Clock $clock)
    {
    }

    /** @return array<string,mixed> */
    public function summary(int $days = 30): array
    {
        $now = $this->clock->now();
        $resolved = $this->tickets->resolvedSince(Clock::db($now->modify("-$days days")));
        $withinResolution = count(array_filter($resolved, fn ($t) => $t['resolved_at'] <= $t['resolution_due_at']));
        $withinResponse = count(array_filter(
            $resolved,
            fn ($t) => $t['first_response_at'] !== null && $t['first_response_at'] <= $t['response_due_at'],
        ));
        $pct = fn (int $n) => $resolved ? round($n / count($resolved) * 100, 1) : null;

        return [
            'by_status' => $this->tickets->countBy('status'),
            'open_by_priority' => $this->tickets->countBy('priority', "'aberto','em_atendimento','aguardando_usuario'"),
            'open_by_category' => $this->tickets->countBy('category', "'aberto','em_atendimento','aguardando_usuario'"),
            'overdue' => $this->tickets->countOverdue(Clock::db($now)),
            'period_days' => $days,
            'resolved_in_period' => count($resolved),
            'response_sla_pct' => $pct($withinResponse),
            'resolution_sla_pct' => $pct($withinResolution),
        ];
    }
}
