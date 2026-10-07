<?php

declare(strict_types=1);

namespace Chamados\Repository;

use PDO;

final class TicketRepository
{
    public const SORTS = [
        'created_at' => 't.created_at',
        'updated_at' => 't.updated_at',
        'resolution_due_at' => 't.resolution_due_at',
        // prioridade pelo peso, não pela ordem alfabética
        'priority' => "CASE t.priority WHEN 'critica' THEN 4 WHEN 'alta' THEN 3 WHEN 'media' THEN 2 ELSE 1 END",
    ];

    private const SELECT = 'SELECT t.*, r.name AS requester_name, a.name AS assignee_name
        FROM tickets t
        JOIN users r ON r.id = t.requester_id
        LEFT JOIN users a ON a.id = t.assignee_id';

    public function __construct(private readonly PDO $db)
    {
    }

    /** @param array<string,mixed> $data */
    public function create(array $data): int
    {
        $cols = array_keys($data);
        $sql = sprintf('INSERT INTO tickets (%s) VALUES (%s)', implode(', ', $cols), implode(', ', array_fill(0, count($cols), '?')));
        $this->db->prepare($sql)->execute(array_values($data));
        return (int) $this->db->lastInsertId();
    }

    /** @return array<string,mixed>|null */
    public function find(int $id): ?array
    {
        $st = $this->db->prepare(self::SELECT . ' WHERE t.id = ?');
        $st->execute([$id]);
        return $st->fetch() ?: null;
    }

    /** @param array<string,mixed> $fields */
    public function update(int $id, array $fields): void
    {
        $set = implode(', ', array_map(fn ($c) => "$c = ?", array_keys($fields)));
        $this->db->prepare("UPDATE tickets SET $set WHERE id = ?")->execute([...array_values($fields), $id]);
    }

    /**
     * Lista com filtros e paginação. Os nomes de coluna vêm só de listas fixas (nunca do usuário).
     *
     * @param array{status?: list<string>, priority?: list<string>, category?: string, assignee?: int|string,
     *              requester_id?: int, overdue?: bool, q?: string} $f
     * @return array{data: list<array<string,mixed>>, total: int}
     */
    public function search(array $f, string $now, string $sort, string $dir, int $page, int $perPage): array
    {
        $where = [];
        $args = [];
        if (!empty($f['status'])) {
            $where[] = 't.status IN (' . implode(',', array_fill(0, count($f['status']), '?')) . ')';
            array_push($args, ...$f['status']);
        }
        if (!empty($f['priority'])) {
            $where[] = 't.priority IN (' . implode(',', array_fill(0, count($f['priority']), '?')) . ')';
            array_push($args, ...$f['priority']);
        }
        if (isset($f['category'])) {
            $where[] = 't.category = ?';
            $args[] = $f['category'];
        }
        if (isset($f['assignee'])) {
            if ($f['assignee'] === 'none') {
                $where[] = 't.assignee_id IS NULL';
            } else {
                $where[] = 't.assignee_id = ?';
                $args[] = $f['assignee'];
            }
        }
        if (isset($f['requester_id'])) {
            $where[] = 't.requester_id = ?';
            $args[] = $f['requester_id'];
        }
        if (!empty($f['overdue'])) {
            // atrasado = ativo, fora de pausa e com prazo de solução vencido
            $where[] = "t.status IN ('aberto', 'em_atendimento') AND t.paused_at IS NULL AND t.resolution_due_at < ?";
            $args[] = $now;
        }
        if (isset($f['q'])) {
            // "!" como caractere de escape funciona igual no MySQL e no SQLite
            $where[] = "(t.title LIKE ? ESCAPE '!' OR t.description LIKE ? ESCAPE '!')";
            $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $f['q']) . '%';
            array_push($args, $like, $like);
        }
        $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

        $count = $this->db->prepare('SELECT COUNT(*) FROM tickets t' . $whereSql);
        $count->execute($args);
        $total = (int) $count->fetchColumn();

        $order = (self::SORTS[$sort] ?? self::SORTS['created_at']) . ($dir === 'asc' ? ' ASC' : ' DESC') . ', t.id DESC';
        $st = $this->db->prepare(self::SELECT . $whereSql . " ORDER BY $order LIMIT ? OFFSET ?");
        $i = 1;
        foreach ($args as $a) {
            $st->bindValue($i++, $a);
        }
        $st->bindValue($i++, $perPage, PDO::PARAM_INT);
        $st->bindValue($i, ($page - 1) * $perPage, PDO::PARAM_INT);
        $st->execute();
        return ['data' => $st->fetchAll(), 'total' => $total];
    }

    // ---- comentários e histórico ----

    public function addComment(int $ticketId, int $authorId, string $body, bool $internal, string $now): int
    {
        $this->db->prepare('INSERT INTO comments (ticket_id, author_id, body, internal, created_at) VALUES (?, ?, ?, ?, ?)')
            ->execute([$ticketId, $authorId, $body, (int) $internal, $now]);
        return (int) $this->db->lastInsertId();
    }

    /** @return list<array<string,mixed>> */
    public function comments(int $ticketId, bool $includeInternal): array
    {
        $sql = 'SELECT c.id, c.body, c.internal, c.created_at, u.id AS author_id, u.name AS author_name, u.role AS author_role
                FROM comments c JOIN users u ON u.id = c.author_id WHERE c.ticket_id = ?';
        if (!$includeInternal) {
            $sql .= ' AND c.internal = 0';
        }
        $st = $this->db->prepare($sql . ' ORDER BY c.created_at, c.id');
        $st->execute([$ticketId]);
        return $st->fetchAll();
    }

    public function addEvent(int $ticketId, int $actorId, string $type, ?string $from, ?string $to, ?string $note, string $now): void
    {
        $this->db->prepare('INSERT INTO ticket_events (ticket_id, actor_id, type, from_value, to_value, note, created_at) VALUES (?, ?, ?, ?, ?, ?, ?)')
            ->execute([$ticketId, $actorId, $type, $from, $to, $note, $now]);
    }

    /** @return list<array<string,mixed>> */
    public function events(int $ticketId): array
    {
        $st = $this->db->prepare('SELECT e.type, e.from_value, e.to_value, e.note, e.created_at, u.name AS actor_name
            FROM ticket_events e JOIN users u ON u.id = e.actor_id WHERE e.ticket_id = ? ORDER BY e.created_at, e.id');
        $st->execute([$ticketId]);
        return $st->fetchAll();
    }

    // ---- indicadores ----

    /** @return array<string,int> */
    public function countBy(string $column, ?string $statusIn = null): array
    {
        $allowed = ['status', 'priority', 'category'];
        if (!in_array($column, $allowed, true)) {
            throw new \InvalidArgumentException('coluna inválida');
        }
        $sql = "SELECT $column AS k, COUNT(*) AS n FROM tickets";
        if ($statusIn !== null) {
            $sql .= " WHERE status IN ($statusIn)";
        }
        $out = [];
        foreach ($this->db->query("$sql GROUP BY $column") as $row) {
            $out[$row['k']] = (int) $row['n'];
        }
        return $out;
    }

    /** Chamados resolvidos desde $since, com prazo e data de solução (para calcular o % no SLA). */
    public function resolvedSince(string $since): array
    {
        $st = $this->db->prepare('SELECT created_at, first_response_at, response_due_at, resolved_at, resolution_due_at
            FROM tickets WHERE resolved_at IS NOT NULL AND resolved_at >= ?');
        $st->execute([$since]);
        return $st->fetchAll();
    }

    public function countOverdue(string $now): int
    {
        $st = $this->db->prepare("SELECT COUNT(*) FROM tickets WHERE status IN ('aberto', 'em_atendimento') AND paused_at IS NULL AND resolution_due_at < ?");
        $st->execute([$now]);
        return (int) $st->fetchColumn();
    }
}
