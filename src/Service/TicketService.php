<?php

declare(strict_types=1);

namespace Chamados\Service;

use Chamados\Clock;
use Chamados\Domain\Category;
use Chamados\Domain\Level;
use Chamados\Domain\Priority;
use Chamados\Domain\Role;
use Chamados\Domain\Sla;
use Chamados\Domain\TicketStatus;
use Chamados\Http\HttpException;
use Chamados\Http\Validator;
use Chamados\Repository\TicketRepository;
use Chamados\Repository\UserRepository;
use DateTimeImmutable;
use DateTimeZone;
use PDO;

/** Regras do chamado: quem pode o quê, ciclo de vida, SLA e histórico. */
final class TicketService
{
    public function __construct(
        private readonly PDO $db,
        private readonly TicketRepository $tickets,
        private readonly UserRepository $users,
        private readonly Sla $sla,
        private readonly Clock $clock,
    ) {
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function create(array $user, array $input): array
    {
        $v = new Validator($input);
        $title = $v->string('title', 5, 150);
        $description = $v->string('description', 10, 5000);
        $category = $v->enum('category', Category::class);
        $impact = $v->enum('impact', Level::class);
        $urgency = $v->enum('urgency', Level::class);
        $v->validate();

        $priority = Priority::fromMatrix($impact, $urgency);
        $now = $this->clock->now();
        $due = $this->sla->deadlines($priority, $now);

        return $this->transaction(function () use ($user, $title, $description, $category, $impact, $urgency, $priority, $now, $due) {
            $id = $this->tickets->create([
                'title' => $title,
                'description' => $description,
                'category' => $category->value,
                'impact' => $impact->value,
                'urgency' => $urgency->value,
                'priority' => $priority->value,
                'status' => TicketStatus::Aberto->value,
                'requester_id' => $user['id'],
                'assignee_id' => null,
                'created_at' => Clock::db($now),
                'updated_at' => Clock::db($now),
                'paused_minutes' => 0,
                'response_due_at' => Clock::db($due['response_due_at']),
                'resolution_due_at' => Clock::db($due['resolution_due_at']),
            ]);
            $this->tickets->addEvent($id, $user['id'], 'created', null, TicketStatus::Aberto->value, null, Clock::db($now));
            return $this->show($user, $id);
        });
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $query parâmetros da URL
     * @return array<string,mixed>
     */
    public function list(array $user, array $query): array
    {
        $role = Role::from($user['role']);
        $f = [];
        $errors = [];

        foreach (['status' => TicketStatus::class, 'priority' => Priority::class] as $key => $enum) {
            if (isset($query[$key]) && is_string($query[$key]) && $query[$key] !== '') {
                $values = explode(',', $query[$key]);
                $invalid = array_filter($values, fn ($x) => $enum::tryFrom($x) === null);
                if ($invalid) {
                    $errors[$key] = 'Valor inválido: ' . implode(', ', $invalid);
                } else {
                    $f[$key] = $values;
                }
            }
        }
        if (isset($query['category']) && $query['category'] !== '') {
            if (Category::tryFrom((string) $query['category'])) {
                $f['category'] = (string) $query['category'];
            } else {
                $errors['category'] = 'Valor inválido';
            }
        }
        if (isset($query['assignee']) && $query['assignee'] !== '') {
            $a = (string) $query['assignee'];
            match (true) {
                $a === 'me' => $f['assignee'] = $user['id'],
                $a === 'none' => $f['assignee'] = 'none',
                ctype_digit($a) => $f['assignee'] = (int) $a,
                default => $errors['assignee'] = 'Use um id, "me" ou "none"',
            };
        }
        if (in_array($query['overdue'] ?? null, ['1', 'true'], true)) {
            $f['overdue'] = true;
        }
        if (isset($query['q']) && trim((string) $query['q']) !== '') {
            $f['q'] = mb_substr(trim((string) $query['q']), 0, 100);
        }
        // solicitante só enxerga os próprios chamados, qualquer que seja o filtro
        if (!$role->isStaff()) {
            $f['requester_id'] = $user['id'];
        }

        $page = max(1, (int) ($query['page'] ?? 1));
        $perPage = (int) ($query['per_page'] ?? 20);
        if ($perPage < 1 || $perPage > 100) {
            $errors['per_page'] = 'Entre 1 e 100';
        }
        $sort = (string) ($query['sort'] ?? 'created_at');
        $dir = 'desc';
        if (str_starts_with($sort, '-')) {
            $sort = substr($sort, 1);
        } else {
            $dir = isset($query['sort']) ? 'asc' : 'desc';
        }
        if (!isset(TicketRepository::SORTS[$sort])) {
            $errors['sort'] = 'Use: ' . implode(', ', array_keys(TicketRepository::SORTS)) . ' (prefixo "-" para decrescente)';
        }
        if ($errors) {
            throw HttpException::validation($errors);
        }

        $now = $this->clock->now();
        $result = $this->tickets->search($f, Clock::db($now), $sort, $dir, $page, $perPage);
        return [
            'data' => array_map(fn ($t) => $this->present($t, $role, $now), $result['data']),
            'meta' => [
                'page' => $page,
                'per_page' => $perPage,
                'total' => $result['total'],
                'last_page' => max(1, (int) ceil($result['total'] / $perPage)),
            ],
        ];
    }

    /**
     * Detalhe com comentários (os internos só para a equipe), histórico e ações possíveis.
     *
     * @param array<string,mixed> $user
     * @return array<string,mixed>
     */
    public function show(array $user, int $id): array
    {
        $t = $this->load($user, $id);
        $role = Role::from($user['role']);
        $out = $this->present($t, $role, $this->clock->now());
        $out['description'] = $t['description'];
        $out['comments'] = array_map(fn ($c) => [
            'id' => (int) $c['id'],
            'body' => $c['body'],
            'internal' => (bool) $c['internal'],
            'author' => ['id' => (int) $c['author_id'], 'name' => $c['author_name'], 'role' => $c['author_role']],
            'created_at' => self::iso($c['created_at']),
        ], $this->tickets->comments($id, $role->isStaff()));
        $out['history'] = array_map(fn ($e) => [
            'type' => $e['type'],
            'from' => $e['from_value'],
            'to' => $e['to_value'],
            'note' => $e['note'],
            'actor' => $e['actor_name'],
            'at' => self::iso($e['created_at']),
        ], $this->tickets->events($id));
        return $out;
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function changeStatus(array $user, int $id, array $input): array
    {
        $t = $this->load($user, $id);
        $role = Role::from($user['role']);
        $v = new Validator($input);
        $to = $v->enum('status', TicketStatus::class);
        $note = $v->string('note', 5, 2000, required: false);
        $v->validate();

        $from = TicketStatus::from($t['status']);
        if ($from === $to) {
            throw new HttpException(409, 'no_change', 'O chamado já está nesse status');
        }
        if (!$from->canTransitionTo($to, $role)) {
            $allowed = array_map(fn ($s) => $s->value, $from->nextFor($role));
            throw new HttpException(409, 'invalid_transition', sprintf(
                'Não é possível ir de "%s" para "%s"%s',
                $from->value,
                $to->value,
                $allowed ? ' (permitido: ' . implode(', ', $allowed) . ')' : '',
            ));
        }
        if ($to->requiresNote() && $note === null) {
            throw HttpException::validation(['note' => 'Obrigatória ao mudar para ' . $to->value]);
        }

        $now = $this->clock->now();
        $nowDb = Clock::db($now);
        $fields = ['status' => $to->value, 'updated_at' => $nowDb];

        // saindo de uma pausa: soma o tempo parado e empurra o prazo de solução
        if ($from->pausesSla() && $t['paused_at'] !== null) {
            $paused = (int) $t['paused_minutes'] + $this->sla->pauseLength(self::utc($t['paused_at']), $now);
            $fields['paused_at'] = null;
            $fields['paused_minutes'] = $paused;
            $fields['resolution_due_at'] = Clock::db(
                $this->sla->deadlines(Priority::from($t['priority']), self::utc($t['created_at']), $paused)['resolution_due_at'],
            );
        }
        if ($to->pausesSla()) {
            $fields['paused_at'] = $nowDb;
        }
        if ($role->isStaff() && $t['first_response_at'] === null) {
            $fields['first_response_at'] = $nowDb;
        }
        if ($to === TicketStatus::EmAtendimento && $t['assignee_id'] === null && $role === Role::Tecnico) {
            $fields['assignee_id'] = $user['id']; // quem pega o chamado vira o responsável
        }
        if ($to === TicketStatus::Resolvido) {
            $fields['resolved_at'] = $nowDb;
        }
        if ($from === TicketStatus::Resolvido && $to === TicketStatus::EmAtendimento) {
            $fields['resolved_at'] = null; // reaberto
        }
        if ($to->isFinal()) {
            $fields['closed_at'] = $nowDb;
        }

        return $this->transaction(function () use ($user, $id, $fields, $from, $to, $note, $nowDb) {
            $this->tickets->update($id, $fields);
            $this->tickets->addEvent($id, $user['id'], 'status', $from->value, $to->value, $note, $nowDb);
            if ($note !== null) {
                $this->tickets->addComment($id, $user['id'], $note, false, $nowDb);
            }
            return $this->show($user, $id);
        });
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function assign(array $user, int $id, array $input): array
    {
        $role = Role::from($user['role']);
        if (!$role->isStaff()) {
            throw HttpException::forbidden();
        }
        $t = $this->load($user, $id);
        $v = new Validator($input);
        $assigneeId = $v->nullableInt('assignee_id');
        $v->validate();

        if (TicketStatus::from($t['status'])->isFinal()) {
            throw new HttpException(409, 'ticket_closed', 'Chamado encerrado não pode ser reatribuído');
        }
        // técnico só pega para si ou se retira; redistribuir a fila é papel do admin
        if ($role === Role::Tecnico && $assigneeId !== null && $assigneeId !== $user['id']) {
            throw HttpException::forbidden('Técnico só pode atribuir o chamado a si mesmo');
        }
        if ($role === Role::Tecnico && $assigneeId === null && (int) $t['assignee_id'] !== $user['id']) {
            throw HttpException::forbidden('Só o responsável atual ou um admin pode remover a atribuição');
        }
        $assignee = null;
        if ($assigneeId !== null) {
            $assignee = $this->users->find($assigneeId);
            if (!$assignee || !$assignee['active'] || !Role::from($assignee['role'])->isStaff()) {
                throw HttpException::validation(['assignee_id' => 'Deve ser um técnico ou admin ativo']);
            }
        }

        $nowDb = Clock::db($this->clock->now());
        return $this->transaction(function () use ($user, $id, $t, $assigneeId, $assignee, $nowDb) {
            $this->tickets->update($id, ['assignee_id' => $assigneeId, 'updated_at' => $nowDb]);
            $this->tickets->addEvent($id, $user['id'], 'assignee', $t['assignee_name'], $assignee['name'] ?? null, null, $nowDb);
            return $this->show($user, $id);
        });
    }

    /**
     * Equipe pode corrigir a prioridade calculada; os prazos são recalculados a partir da abertura.
     *
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function changePriority(array $user, int $id, array $input): array
    {
        if (!Role::from($user['role'])->isStaff()) {
            throw HttpException::forbidden();
        }
        $t = $this->load($user, $id);
        $v = new Validator($input);
        $priority = $v->enum('priority', Priority::class);
        $reason = $v->string('reason', 5, 500);
        $v->validate();

        if ($priority->value === $t['priority']) {
            throw new HttpException(409, 'no_change', 'O chamado já tem essa prioridade');
        }
        $due = $this->sla->deadlines($priority, self::utc($t['created_at']), (int) $t['paused_minutes']);
        $nowDb = Clock::db($this->clock->now());
        return $this->transaction(function () use ($user, $id, $t, $priority, $reason, $due, $nowDb) {
            $this->tickets->update($id, [
                'priority' => $priority->value,
                'response_due_at' => Clock::db($due['response_due_at']),
                'resolution_due_at' => Clock::db($due['resolution_due_at']),
                'updated_at' => $nowDb,
            ]);
            $this->tickets->addEvent($id, $user['id'], 'priority', $t['priority'], $priority->value, $reason, $nowDb);
            return $this->show($user, $id);
        });
    }

    /**
     * @param array<string,mixed> $user
     * @param array<string,mixed> $input
     * @return array<string,mixed>
     */
    public function comment(array $user, int $id, array $input): array
    {
        $t = $this->load($user, $id);
        $role = Role::from($user['role']);
        $v = new Validator($input);
        $body = $v->string('body', 1, 5000);
        $internal = $v->bool('internal');
        if ($internal && !$role->isStaff()) {
            $v->addError('internal', 'Só a equipe pode fazer comentário interno');
        }
        $v->validate();

        $status = TicketStatus::from($t['status']);
        if ($status->isFinal()) {
            throw new HttpException(409, 'ticket_closed', 'Chamado encerrado não recebe comentários');
        }
        $now = $this->clock->now();
        $nowDb = Clock::db($now);

        return $this->transaction(function () use ($user, $id, $t, $role, $body, $internal, $status, $now, $nowDb) {
            $this->tickets->addComment($id, $user['id'], $body, $internal, $nowDb);
            $fields = ['updated_at' => $nowDb];
            if ($role->isStaff() && !$internal && $t['first_response_at'] === null) {
                $fields['first_response_at'] = $nowDb; // resposta pública conta como primeiro atendimento
            }
            // o solicitante respondeu o que faltava: o chamado volta para a fila e o relógio volta a correr
            if (!$role->isStaff() && $status === TicketStatus::AguardandoUsuario) {
                $paused = (int) $t['paused_minutes'] + $this->sla->pauseLength(self::utc($t['paused_at']), $now);
                $fields += [
                    'status' => TicketStatus::EmAtendimento->value,
                    'paused_at' => null,
                    'paused_minutes' => $paused,
                    'resolution_due_at' => Clock::db(
                        $this->sla->deadlines(Priority::from($t['priority']), self::utc($t['created_at']), $paused)['resolution_due_at'],
                    ),
                ];
                $this->tickets->addEvent($id, $user['id'], 'status', $status->value, TicketStatus::EmAtendimento->value, 'Resposta do solicitante', $nowDb);
            }
            $this->tickets->update($id, $fields);
            return $this->show($user, $id);
        });
    }

    // ---------------------------------------------------------------

    /** @param array<string,mixed> $user */
    private function load(array $user, int $id): array
    {
        $t = $this->tickets->find($id);
        // para o solicitante, chamado de outra pessoa "não existe" (não vaza nem o ID)
        if (!$t || (!Role::from($user['role'])->isStaff() && (int) $t['requester_id'] !== $user['id'])) {
            throw HttpException::notFound('Chamado');
        }
        return $t;
    }

    /** @param array<string,mixed> $t */
    private function present(array $t, Role $role, DateTimeImmutable $now): array
    {
        return [
            'id' => (int) $t['id'],
            'title' => $t['title'],
            'category' => $t['category'],
            'impact' => $t['impact'],
            'urgency' => $t['urgency'],
            'priority' => $t['priority'],
            'status' => $t['status'],
            'requester' => ['id' => (int) $t['requester_id'], 'name' => $t['requester_name']],
            'assignee' => $t['assignee_id'] === null ? null : ['id' => (int) $t['assignee_id'], 'name' => $t['assignee_name']],
            'created_at' => self::iso($t['created_at']),
            'updated_at' => self::iso($t['updated_at']),
            'first_response_at' => self::iso($t['first_response_at']),
            'resolved_at' => self::iso($t['resolved_at']),
            'closed_at' => self::iso($t['closed_at']),
            'sla' => $this->sla->evaluate($t, $now),
            'allowed_status' => array_map(fn ($s) => $s->value, TicketStatus::from($t['status'])->nextFor($role)),
        ];
    }

    private static function utc(string $v): DateTimeImmutable
    {
        return new DateTimeImmutable($v, new DateTimeZone('UTC'));
    }

    private static function iso(?string $v): ?string
    {
        return $v === null ? null : Sla::iso(self::utc($v));
    }

    /**
     * @template T
     * @param callable():T $fn
     * @return T
     */
    private function transaction(callable $fn): mixed
    {
        $this->db->beginTransaction();
        try {
            $result = $fn();
            $this->db->commit();
            return $result;
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }
    }
}
