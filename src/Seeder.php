<?php

declare(strict_types=1);

namespace Chamados;

use Chamados\Http\Request;
use PDO;

/**
 * Dados de demonstração (fictícios). Tudo é criado pela própria API, com o relógio avançando,
 * então o histórico, os prazos e as pausas de SLA saem exatamente como sairiam no uso real.
 */
final class Seeder
{
    public const PASSWORD = 'senha-demo-123';

    /** As datas são relativas a $now: o histórico começa na segunda-feira de duas semanas atrás, 08:00 (SP). */
    public static function run(PDO $db, string $jwtSecret, ?\DateTimeImmutable $now = null): void
    {
        $now ??= new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $clock = new Clock();
        $clock->set($now->modify('-14 days')->modify('monday this week')->format('Y-m-d') . ' 11:00:00');
        $app = new App($db, ['jwt_secret' => $jwtSecret], $clock);
        $users = new Repository\UserRepository($db);

        $people = [
            ['Ana Admin', 'admin@exemplo.com', 'admin'],
            ['Bruno Técnico', 'bruno@exemplo.com', 'tecnico'],
            ['Carla Técnica', 'carla@exemplo.com', 'tecnico'],
            ['Diego Financeiro', 'diego@exemplo.com', 'solicitante'],
            ['Elisa RH', 'elisa@exemplo.com', 'solicitante'],
            ['Fábio Comercial', 'fabio@exemplo.com', 'solicitante'],
        ];
        foreach ($people as [$name, $email, $role]) {
            if (!$users->emailExists($email)) {
                $users->create($name, $email, self::PASSWORD, $role, Clock::db($clock->now()));
            }
        }
        // faz login a cada chamada: o relógio avança dias e o token expira no caminho, como na vida real
        $call = function (string $who, string $method, string $path, array $body = []) use ($app): array {
            $login = $app->handle(new Request('POST', '/api/auth/login', body: json_encode(['email' => "$who@exemplo.com", 'password' => self::PASSWORD])));
            $res = $app->handle(new Request($method, $path, headers: ['authorization' => 'Bearer ' . $login->data()['token']], body: json_encode($body)));
            if ($res->status >= 400) {
                throw new \RuntimeException("Seed falhou em $method $path: " . $res->body);
            }
            return $res->data();
        };
        $open = fn (string $who, string $title, string $desc, string $cat, string $imp, string $urg) =>
            $call($who, 'POST', '/api/tickets', ['title' => $title, 'description' => $desc, 'category' => $cat, 'impact' => $imp, 'urgency' => $urg])['id'];

        // 1. resolvido e fechado dentro do prazo
        $t = $open('diego', 'Excel trava ao abrir planilha de fechamento', 'O Excel fecha sozinho quando abro a planilha do fechamento mensal.', 'software', 'medio', 'alto');
        $clock->advance('+20 minutes');
        $call('bruno', 'PATCH', "/api/tickets/$t/status", ['status' => 'em_atendimento']);
        $clock->advance('+1 hour');
        $call('bruno', 'PATCH', "/api/tickets/$t/status", ['status' => 'resolvido', 'note' => 'Reparo do Office e remoção de suplemento antigo que causava o travamento.']);
        $clock->advance('+2 hours');
        $call('diego', 'PATCH', "/api/tickets/$t/status", ['status' => 'fechado']);

        // 2. aguardando usuário (SLA pausado) e retomado com a resposta
        $clock->advance('+1 day');
        $t = $open('elisa', 'Sem acesso à pasta compartilhada do RH', 'Desde ontem aparece "acesso negado" na pasta \\\\servidor\\rh.', 'acesso', 'medio', 'medio');
        $clock->advance('+30 minutes');
        $call('carla', 'PATCH', "/api/tickets/$t/status", ['status' => 'em_atendimento']);
        $call('carla', 'POST', "/api/tickets/$t/comments", ['body' => 'Grupo do AD conferido, usuária está no grupo correto.', 'internal' => true]);
        $call('carla', 'PATCH', "/api/tickets/$t/status", ['status' => 'aguardando_usuario', 'note' => 'Pode me dizer o nome do computador (etiqueta de patrimônio)?']);
        $clock->advance('+1 day');
        $call('elisa', 'POST', "/api/tickets/$t/comments", ['body' => 'É o PAT-01234.']);
        $clock->advance('+40 minutes');
        $call('carla', 'PATCH', "/api/tickets/$t/status", ['status' => 'resolvido', 'note' => 'Credencial antiga salva no Windows; removida no Gerenciador de Credenciais.']);

        // 3. crítico atribuído pelo admin, ainda em atendimento
        $clock->advance('+2 days');
        $t = $open('fabio', 'Sistema de vendas fora do ar para toda a equipe', 'Ninguém do comercial consegue acessar o sistema de pedidos.', 'rede', 'alto', 'alto');
        $bruno = $users->findByEmailWithHash('bruno@exemplo.com')['id'];
        $call('admin', 'PATCH', "/api/tickets/$t/assignee", ['assignee_id' => (int) $bruno]);
        $clock->advance('+15 minutes');
        $call('bruno', 'PATCH', "/api/tickets/$t/status", ['status' => 'em_atendimento']);

        // 4. prioridade corrigida pela equipe
        $clock->advance('+3 hours');
        $t = $open('diego', 'Impressora do financeiro imprimindo manchado', 'As impressões saem com faixas cinzas.', 'impressao', 'baixo', 'medio');
        $call('carla', 'PATCH', "/api/tickets/$t/priority", ['priority' => 'media', 'reason' => 'Impressora única do setor, emite boletos.']);

        // 5. aberto e atrasado (ninguém atendeu)
        $open('elisa', 'Mouse com clique duplo falhando', 'O botão esquerdo às vezes dá clique duplo sozinho.', 'hardware', 'baixo', 'baixo');

        // 6. cancelado pelo próprio solicitante
        $t = $open('fabio', 'Instalar leitor de PDF', 'Preciso de um leitor de PDF na máquina nova.', 'software', 'baixo', 'medio');
        $call('fabio', 'PATCH', "/api/tickets/$t/status", ['status' => 'cancelado', 'note' => 'Já veio instalado, não percebi.']);

        // 7. recém-aberto
        $clock->set(Clock::db($now->modify('-1 hour')));
        $open('diego', 'VPN desconecta a cada 10 minutos', 'Trabalhando de casa, a VPN cai várias vezes por hora.', 'rede', 'medio', 'alto');
    }
}
