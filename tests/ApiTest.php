<?php

declare(strict_types=1);

namespace Chamados\Tests;

use Chamados\App;
use Chamados\Clock;
use Chamados\Database;
use Chamados\Http\Request;
use Chamados\Http\Response;
use Chamados\Repository\UserRepository;
use Chamados\Seeder;
use PDO;
use PHPUnit\Framework\TestCase;

/** Testes de integração: a API inteira em memória (SQLite), sem servidor HTTP. */
final class ApiTest extends TestCase
{
    private const SECRET = 'segredo-de-teste-com-mais-de-32-caracteres';
    private const PASS = 'senha-forte-123';

    private PDO $db;
    private Clock $clock;
    private App $app;
    /** @var array<string,string> */
    private array $tokens = [];

    protected function setUp(): void
    {
        $this->db = self::freshDatabase();
        $this->clock = new Clock();
        $this->clock->set('2026-10-07 12:00:00'); // quarta, 09:00 em São Paulo
        $this->app = new App($this->db, ['jwt_secret' => self::SECRET, 'holidays' => ['2026-10-12']], $this->clock);

        $users = new UserRepository($this->db);
        foreach ([['Admin', 'admin', 'admin'], ['Téc 1', 'tec1', 'tecnico'], ['Téc 2', 'tec2', 'tecnico'], ['Sol 1', 'sol1', 'solicitante'], ['Sol 2', 'sol2', 'solicitante']] as [$name, $key, $role]) {
            $users->create($name, "$key@teste.com", self::PASS, $role, '2026-01-01 00:00:00');
        }
    }

    // ---------- helpers ----------

    /**
     * SQLite em memória por padrão. Com TEST_DB_DSN (ex.: MySQL no CI) roda os mesmos testes no banco real,
     * recriando as tabelas a cada teste.
     */
    public static function freshDatabase(): PDO
    {
        $dsn = getenv('TEST_DB_DSN') ?: 'sqlite::memory:';
        $db = Database::connect($dsn, getenv('TEST_DB_USER') ?: null, getenv('TEST_DB_PASSWORD') ?: null);
        if (Database::driver($db) === 'mysql') {
            foreach (['comments', 'ticket_events', 'tickets', 'login_attempts', 'users'] as $table) {
                $db->exec("DROP TABLE IF EXISTS $table");
            }
        }
        Database::migrate($db);
        return $db;
    }

    private function call(string $method, string $path, ?string $as = null, array $body = [], array $query = []): Response
    {
        $headers = [];
        if ($as !== null) {
            $this->tokens[$as] ??= $this->login($as);
            $headers['authorization'] = 'Bearer ' . $this->tokens[$as];
        }
        return $this->app->handle(new Request($method, $path, $query, $headers, $body ? json_encode($body) : ''));
    }

    private function login(string $who): string
    {
        $res = $this->app->handle(new Request('POST', '/api/auth/login', body: json_encode(['email' => "$who@teste.com", 'password' => self::PASS])));
        $this->assertSame(200, $res->status, $res->body);
        return $res->data()['token'];
    }

    private function open(string $as = 'sol1', string $impact = 'medio', string $urgency = 'medio'): array
    {
        $res = $this->call('POST', '/api/tickets', $as, [
            'title' => 'Computador não liga',
            'description' => 'Apertei o botão e nada acontece.',
            'category' => 'hardware',
            'impact' => $impact,
            'urgency' => $urgency,
        ]);
        $this->assertSame(201, $res->status, $res->body);
        return $res->data();
    }

    private function mudarStatus(int $id, string $as, string $status, ?string $note = null): Response
    {
        return $this->call('PATCH', "/api/tickets/$id/status", $as, array_filter(['status' => $status, 'note' => $note]));
    }

    private function userId(string $key): int
    {
        return (int) $this->db->query("SELECT id FROM users WHERE email = '$key@teste.com'")->fetchColumn();
    }

    // ---------- autenticação ----------

    public function testLoginAndMe(): void
    {
        $res = $this->call('GET', '/api/me', 'tec1');
        $this->assertSame(200, $res->status);
        $this->assertSame('tecnico', $res->data()['role']);
        $this->assertArrayNotHasKey('password_hash', $res->data());
    }

    public function testRejectsMissingAndBadTokens(): void
    {
        $this->assertSame('unauthenticated', $this->call('GET', '/api/tickets')->data()['error']['code']);
        $res = $this->app->handle(new Request('GET', '/api/tickets', headers: ['authorization' => 'Bearer abc.def.ghi']));
        $this->assertSame(401, $res->status);
    }

    public function testTokenExpires(): void
    {
        $this->call('GET', '/api/me', 'sol1');
        $this->clock->advance('+9 hours');
        $this->assertSame('invalid_token', $this->call('GET', '/api/me', 'sol1')->data()['error']['code']);
    }

    public function testDeactivatedUserLosesAccessImmediately(): void
    {
        $this->assertSame(200, $this->call('GET', '/api/me', 'sol1')->status);
        $this->call('PATCH', '/api/users/' . $this->userId('sol1'), 'admin', ['active' => false]);
        $this->assertSame(401, $this->call('GET', '/api/me', 'sol1')->status);
    }

    public function testLoginRateLimit(): void
    {
        $try = fn (string $pass) => $this->app->handle(new Request('POST', '/api/auth/login', body: json_encode(['email' => 'sol1@teste.com', 'password' => $pass])));
        for ($i = 0; $i < 5; $i++) {
            $this->assertSame(401, $try('errada')->status);
        }
        $this->assertSame(429, $try(self::PASS)->status, 'bloqueia mesmo com a senha certa');
        $this->clock->advance('+16 minutes');
        $this->assertSame(200, $try(self::PASS)->status);
    }

    public function testUnknownEmailGivesSameError(): void
    {
        $res = $this->app->handle(new Request('POST', '/api/auth/login', body: json_encode(['email' => 'ninguem@teste.com', 'password' => 'x'])));
        $this->assertSame(401, $res->status);
        $this->assertSame('Email ou senha incorretos', $res->data()['error']['message']);
    }

    // ---------- abertura e SLA ----------

    public function testCreateComputesPriorityAndDeadlines(): void
    {
        $t = $this->open('sol1', 'alto', 'medio');
        $this->assertSame('alta', $t['priority']);
        $this->assertSame('aberto', $t['status']);
        // alta: 1 h para responder e 9 h úteis para resolver, a partir de quarta 09:00 (SP)
        $this->assertSame('2026-10-07T13:00:00Z', $t['sla']['response_due_at']);
        $this->assertSame('2026-10-08T12:00:00Z', $t['sla']['resolution_due_at']);
        $this->assertSame(['cancelado'], $t['allowed_status']);
        $this->assertSame('created', $t['history'][0]['type']);
    }

    public function testValidationErrorsAreCollected(): void
    {
        $res = $this->call('POST', '/api/tickets', 'sol1', ['title' => 'oi', 'impact' => 'enorme']);
        $this->assertSame(422, $res->status);
        $details = $res->data()['error']['details'];
        $this->assertEqualsCanonicalizing(['title', 'description', 'category', 'impact', 'urgency'], array_keys($details));
    }

    public function testInvalidJson(): void
    {
        $this->tokens['sol1'] = $this->login('sol1');
        $res = $this->app->handle(new Request('POST', '/api/tickets', headers: ['authorization' => 'Bearer ' . $this->tokens['sol1']], body: '{nope'));
        $this->assertSame(400, $res->status);
    }

    public function testPauseWhileWaitingForUserExtendsDeadline(): void
    {
        $t = $this->open(); // média: 18 h úteis -> vence sexta 09:00 SP
        $this->assertSame('2026-10-09T12:00:00Z', $t['sla']['resolution_due_at']);

        $this->mudarStatus($t['id'], 'tec1', 'em_atendimento');
        $res = $this->mudarStatus($t['id'], 'tec1', 'aguardando_usuario', 'Qual o número do patrimônio?');
        $this->assertTrue($res->data()['sla']['paused']);

        // usuário responde 3 h úteis depois (09:00 -> 13:00 com almoço) e o chamado volta para a fila
        $this->clock->set('2026-10-07 16:00:00');
        $after = $this->call('POST', "/api/tickets/{$t['id']}/comments", 'sol1', ['body' => 'É o PAT-999'])->data();
        $this->assertSame('em_atendimento', $after['status']);
        $this->assertFalse($after['sla']['paused']);
        $this->assertSame('2026-10-09T15:00:00Z', $after['sla']['resolution_due_at'], 'prazo empurrado em 3 h úteis');
    }

    public function testOverdueFilterAndBreachFlags(): void
    {
        $late = $this->open('sol1', 'alto', 'alto'); // crítico: resposta em 30 min
        $this->open('sol2', 'baixo', 'baixo');
        $this->clock->set('2026-10-07 18:00:00'); // 15:00 SP, 5 h úteis depois: crítico estourou (4 h)

        $res = $this->call('GET', '/api/tickets', 'tec1', query: ['overdue' => '1'])->data();
        $this->assertSame(1, $res['meta']['total']);
        $this->assertSame($late['id'], $res['data'][0]['id']);
        $this->assertTrue($res['data'][0]['sla']['response_breached']);
        $this->assertTrue($res['data'][0]['sla']['resolution_breached']);
        $this->assertSame(-60, $res['data'][0]['sla']['business_minutes_left']);
    }

    public function testChangingPriorityRecomputesDeadlines(): void
    {
        $t = $this->open('sol1', 'baixo', 'baixo');
        $res = $this->call('PATCH', "/api/tickets/{$t['id']}/priority", 'tec1', ['priority' => 'critica', 'reason' => 'Afeta o caixa da loja']);
        $this->assertSame('critica', $res->data()['priority']);
        $this->assertSame('2026-10-07T17:00:00Z', $res->data()['sla']['resolution_due_at']); // 4 h úteis: 09:00 -> 14:00 SP
        $this->assertSame(403, $this->call('PATCH', "/api/tickets/{$t['id']}/priority", 'sol1', ['priority' => 'alta', 'reason' => 'urgente!!'])->status);
    }

    // ---------- ciclo de vida ----------

    public function testFullLifecycle(): void
    {
        $id = $this->open()['id'];
        $this->clock->advance('+10 minutes');

        $taken = $this->mudarStatus($id, 'tec1', 'em_atendimento')->data();
        $this->assertSame('Téc 1', $taken['assignee']['name'], 'quem atende vira responsável');
        $this->assertSame('2026-10-07T12:10:00Z', $taken['first_response_at']);

        $this->assertSame(422, $this->mudarStatus($id, 'tec1', 'resolvido')->status, 'resolver exige nota');
        $resolved = $this->mudarStatus($id, 'tec1', 'resolvido', 'Fonte trocada.')->data();
        $this->assertNotNull($resolved['resolved_at']);
        $this->assertFalse($resolved['sla']['resolution_breached']);

        $reopened = $this->mudarStatus($id, 'sol1', 'em_atendimento')->data();
        $this->assertNull($reopened['resolved_at'], 'reabrir limpa a solução');
        $this->mudarStatus($id, 'tec1', 'resolvido', 'Fonte trocada de novo.');
        $closed = $this->mudarStatus($id, 'sol1', 'fechado')->data();
        $this->assertSame('fechado', $closed['status']);
        $this->assertSame([], $closed['allowed_status']);

        $types = array_map(fn ($h) => $h['to'] ?? $h['type'], $closed['history']);
        $this->assertSame(['aberto', 'em_atendimento', 'resolvido', 'em_atendimento', 'resolvido', 'fechado'], $types);
        $this->assertSame(409, $this->call('POST', "/api/tickets/$id/comments", 'sol1', ['body' => 'oi'])->status);
    }

    public function testInvalidTransitionExplainsWhatIsAllowed(): void
    {
        $id = $this->open()['id'];
        $res = $this->mudarStatus($id, 'tec1', 'resolvido', 'pulando etapas');
        $this->assertSame(409, $res->status);
        $this->assertStringContainsString('em_atendimento', $res->data()['error']['message']);
        $this->assertSame(409, $this->mudarStatus($id, 'sol1', 'em_atendimento')->status);
    }

    // ---------- permissões ----------

    public function testRequesterOnlySeesOwnTickets(): void
    {
        $mine = $this->open('sol1');
        $this->open('sol2');
        $list = $this->call('GET', '/api/tickets', 'sol1')->data();
        $this->assertSame(1, $list['meta']['total']);
        $this->assertSame(404, $this->call('GET', "/api/tickets/{$mine['id']}", 'sol2')->status, '404, não 403: não vaza que existe');
        $this->assertSame(2, $this->call('GET', '/api/tickets', 'tec2')->data()['meta']['total']);
    }

    public function testInternalCommentsAreHiddenFromRequester(): void
    {
        $id = $this->open()['id'];
        $this->call('POST', "/api/tickets/$id/comments", 'tec1', ['body' => 'Suspeita de fonte queimada', 'internal' => true]);
        $this->call('POST', "/api/tickets/$id/comments", 'tec1', ['body' => 'Vamos verificar hoje.']);

        $this->assertCount(2, $this->call('GET', "/api/tickets/$id", 'tec2')->data()['comments']);
        $forRequester = $this->call('GET', "/api/tickets/$id", 'sol1')->data()['comments'];
        $this->assertCount(1, $forRequester);
        $this->assertSame('Vamos verificar hoje.', $forRequester[0]['body']);
        $this->assertSame(422, $this->call('POST', "/api/tickets/$id/comments", 'sol1', ['body' => 'x', 'internal' => true])->status);
    }

    public function testInternalCommentDoesNotCountAsFirstResponse(): void
    {
        $id = $this->open()['id'];
        $this->clock->advance('+5 minutes');
        $this->call('POST', "/api/tickets/$id/comments", 'tec1', ['body' => 'nota interna', 'internal' => true]);
        $this->assertNull($this->call('GET', "/api/tickets/$id", 'tec1')->data()['first_response_at']);
        $this->call('POST', "/api/tickets/$id/comments", 'tec1', ['body' => 'Olá, estou verificando.']);
        $this->assertSame('2026-10-07T12:05:00Z', $this->call('GET', "/api/tickets/$id", 'tec1')->data()['first_response_at']);
    }

    public function testAssignmentRules(): void
    {
        $id = $this->open()['id'];
        $tec1 = $this->userId('tec1');
        $tec2 = $this->userId('tec2');

        $this->assertSame(403, $this->call('PATCH', "/api/tickets/$id/assignee", 'tec1', ['assignee_id' => $tec2])->status);
        $this->assertSame(200, $this->call('PATCH', "/api/tickets/$id/assignee", 'tec1', ['assignee_id' => $tec1])->status);
        $this->assertSame(403, $this->call('PATCH', "/api/tickets/$id/assignee", 'tec2', ['assignee_id' => null])->status);
        $this->assertSame(200, $this->call('PATCH', "/api/tickets/$id/assignee", 'admin', ['assignee_id' => $tec2])->status);
        $this->assertSame(422, $this->call('PATCH', "/api/tickets/$id/assignee", 'admin', ['assignee_id' => $this->userId('sol2')])->status);
        $this->assertSame(403, $this->call('PATCH', "/api/tickets/$id/assignee", 'sol1', ['assignee_id' => null])->status);

        $mine = $this->call('GET', '/api/tickets', 'tec2', query: ['assignee' => 'me'])->data();
        $this->assertSame(1, $mine['meta']['total']);
    }

    public function testOnlyAdminManagesUsers(): void
    {
        $body = ['name' => 'Nova', 'email' => 'nova@teste.com', 'password' => 'senha-1234', 'role' => 'tecnico'];
        $this->assertSame(403, $this->call('POST', '/api/users', 'tec1', $body)->status);
        $this->assertSame(201, $this->call('POST', '/api/users', 'admin', $body)->status);
        $this->assertSame('Já cadastrado', $this->call('POST', '/api/users', 'admin', $body)->data()['error']['details']['email']);
        $this->assertSame(409, $this->call('PATCH', '/api/users/' . $this->userId('admin'), 'admin', ['active' => false])->status);
        $this->assertSame(403, $this->call('GET', '/api/users', 'sol1')->status);
    }

    // ---------- listagem ----------

    public function testFiltersSortAndPagination(): void
    {
        foreach ([['baixo', 'baixo'], ['alto', 'alto'], ['medio', 'medio'], ['alto', 'medio']] as [$i, $u]) {
            $this->open('sol1', $i, $u);
            $this->clock->advance('+1 minute');
        }
        $byPriority = $this->call('GET', '/api/tickets', 'tec1', query: ['sort' => '-priority'])->data()['data'];
        $this->assertSame(['critica', 'alta', 'media', 'baixa'], array_column($byPriority, 'priority'));

        $page = $this->call('GET', '/api/tickets', 'tec1', query: ['per_page' => '3', 'page' => '2'])->data();
        $this->assertSame(['page' => 2, 'per_page' => 3, 'total' => 4, 'last_page' => 2], $page['meta']);
        $this->assertCount(1, $page['data']);

        $f = $this->call('GET', '/api/tickets', 'tec1', query: ['priority' => 'critica,alta'])->data();
        $this->assertSame(2, $f['meta']['total']);

        $bad = $this->call('GET', '/api/tickets', 'tec1', query: ['status' => 'perdido', 'sort' => 'senha']);
        $this->assertSame(422, $bad->status);
        $this->assertArrayHasKey('sort', $bad->data()['error']['details']);
    }

    public function testSearchEscapesWildcards(): void
    {
        $this->open();
        $this->assertSame(1, $this->call('GET', '/api/tickets', 'tec1', query: ['q' => 'não liga'])->data()['meta']['total']);
        $this->assertSame(0, $this->call('GET', '/api/tickets', 'tec1', query: ['q' => '%'])->data()['meta']['total']);
    }

    // ---------- indicadores, rotas e seed ----------

    public function testMetrics(): void
    {
        $a = $this->open()['id'];
        $this->open('sol2', 'alto', 'alto');
        $this->mudarStatus($a, 'tec1', 'em_atendimento');
        $this->mudarStatus($a, 'tec1', 'resolvido', 'Resolvido rápido.');
        $this->clock->set('2026-10-08 18:00:00');

        $m = $this->call('GET', '/api/metrics', 'admin')->data();
        $this->assertEquals(['resolvido' => 1, 'aberto' => 1], $m['by_status']);
        $this->assertSame(1, $m['overdue']);
        $this->assertSame(100.0, $m['resolution_sla_pct']);
        $this->tokens = []; // o relógio pulou mais de 8 h: tokens antigos expiraram
        $this->assertSame(403, $this->call('GET', '/api/metrics', 'sol1')->status);
    }

    public function testRoutingErrors(): void
    {
        $this->assertSame(404, $this->call('GET', '/api/nada', 'sol1')->status);
        $this->assertSame(405, $this->call('DELETE', '/api/tickets', 'sol1')->status);
        $this->assertSame(404, $this->call('GET', '/api/tickets/999', 'tec1')->status);
        $this->assertSame(200, $this->call('GET', '/api/health')->status);
        $this->assertSame(204, $this->call('OPTIONS', '/api/tickets')->status);
    }

    public function testSeederRunsThroughTheApi(): void
    {
        Seeder::run($this->db, self::SECRET, $this->clock->now());
        $count = fn (string $sql) => (int) $this->db->query($sql)->fetchColumn();
        $this->assertSame(7, $count("SELECT COUNT(*) FROM tickets"));
        // ticket do RH ficou 1 dia útil inteiro aguardando a usuária
        $this->assertSame(540, $count("SELECT paused_minutes FROM tickets WHERE title LIKE 'Sem acesso%'"));
        $this->assertSame(1, $count("SELECT COUNT(*) FROM tickets WHERE status = 'fechado'"));
    }
}
