<?php

declare(strict_types=1);

namespace Chamados;

use Chamados\Auth\Jwt;
use Chamados\Domain\BusinessCalendar;
use Chamados\Domain\Role;
use Chamados\Domain\Sla;
use Chamados\Http\HttpException;
use Chamados\Http\Request;
use Chamados\Http\Response;
use Chamados\Http\Router;
use Chamados\Http\Validator;
use Chamados\Repository\TicketRepository;
use Chamados\Repository\UserRepository;
use Chamados\Service\AuthService;
use Chamados\Service\MetricsService;
use Chamados\Service\TicketService;
use PDO;

/** Monta as dependências e as rotas. handle() não toca em globais: os testes chamam direto, sem servidor. */
final class App
{
    private readonly Router $router;
    private readonly AuthService $auth;
    private readonly TicketService $tickets;
    private readonly MetricsService $metrics;
    private readonly UserRepository $users;

    /** @param array{jwt_secret: string, jwt_ttl_minutes?: int, holidays?: list<string>, cors_origin?: string} $config */
    public function __construct(PDO $db, private readonly array $config, private readonly Clock $clock = new Clock())
    {
        $this->users = new UserRepository($db);
        $ticketRepo = new TicketRepository($db);
        $sla = new Sla(new BusinessCalendar($config['holidays'] ?? []));
        $jwt = new Jwt($config['jwt_secret'], 60 * ($config['jwt_ttl_minutes'] ?? 480));

        $this->auth = new AuthService($this->users, $jwt, $clock);
        $this->tickets = new TicketService($db, $ticketRepo, $this->users, $sla, $clock);
        $this->metrics = new MetricsService($ticketRepo, $clock);
        $this->router = new Router();
        $this->routes();
    }

    public static function fromEnv(): self
    {
        $env = static fn (string $k, ?string $d = null) => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;
        $secret = $env('JWT_SECRET') ?? throw new \RuntimeException('Defina JWT_SECRET (veja .env.example)');
        $db = Database::connect($env('DB_DSN', 'sqlite:' . __DIR__ . '/../database/chamados.sqlite'), $env('DB_USER'), $env('DB_PASSWORD'));
        return new self($db, [
            'jwt_secret' => $secret,
            'jwt_ttl_minutes' => (int) $env('JWT_TTL_MINUTES', '480'),
            'holidays' => array_values(array_filter(array_map('trim', explode(',', $env('SLA_HOLIDAYS', ''))))),
            'cors_origin' => $env('CORS_ORIGIN', '*'),
        ]);
    }

    public function handle(Request $req): Response
    {
        if ($req->method === 'OPTIONS') {
            return $this->withHeaders(new Response(204));
        }
        try {
            [$handler, $needsAuth, $params] = $this->router->match($req);
            $req->params = $params;
            if ($needsAuth) {
                $req->user = $this->auth->authenticate($req->bearerToken());
            }
            $res = $handler($req);
        } catch (HttpException $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->details) {
                $error['details'] = $e->details;
            }
            $res = Response::json(['error' => $error], $e->status);
        } catch (\Throwable $e) {
            error_log((string) $e);
            $res = Response::json(['error' => ['code' => 'internal_error', 'message' => 'Erro interno']], 500);
        }
        return $this->withHeaders($res);
    }

    private function withHeaders(Response $res): Response
    {
        $res->headers += [
            'Access-Control-Allow-Origin' => $this->config['cors_origin'] ?? '*',
            'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
            'Access-Control-Allow-Methods' => 'GET, POST, PATCH, OPTIONS',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store',
        ];
        return $res;
    }

    private function routes(): void
    {
        $r = $this->router;
        $id = static fn (Request $q): int => (int) $q->params['id'];

        $r->add('GET', '/api/health', fn () => Response::json(['ok' => true]), auth: false);
        $r->add('POST', '/api/auth/login', fn (Request $q) => Response::json($this->auth->login($q->json(), $q->ip)), auth: false);
        $r->add('GET', '/api/me', fn (Request $q) => Response::json($q->user));

        $r->add('GET', '/api/tickets', fn (Request $q) => Response::json($this->tickets->list($q->user, $q->query)));
        $r->add('POST', '/api/tickets', fn (Request $q) => Response::json($this->tickets->create($q->user, $q->json()), 201));
        $r->add('GET', '/api/tickets/{id}', fn (Request $q) => Response::json($this->tickets->show($q->user, $id($q))));
        $r->add('PATCH', '/api/tickets/{id}/status', fn (Request $q) => Response::json($this->tickets->changeStatus($q->user, $id($q), $q->json())));
        $r->add('PATCH', '/api/tickets/{id}/assignee', fn (Request $q) => Response::json($this->tickets->assign($q->user, $id($q), $q->json())));
        $r->add('PATCH', '/api/tickets/{id}/priority', fn (Request $q) => Response::json($this->tickets->changePriority($q->user, $id($q), $q->json())));
        $r->add('POST', '/api/tickets/{id}/comments', fn (Request $q) => Response::json($this->tickets->comment($q->user, $id($q), $q->json()), 201));

        $r->add('GET', '/api/metrics', function (Request $q) {
            $this->requireRole($q, Role::Tecnico, Role::Admin);
            $days = (int) ($q->query['days'] ?? 30);
            if ($days < 1 || $days > 365) {
                throw HttpException::validation(['days' => 'Entre 1 e 365']);
            }
            return Response::json($this->metrics->summary($days));
        });

        $r->add('GET', '/api/users', function (Request $q) {
            $this->requireRole($q, Role::Tecnico, Role::Admin);
            $role = isset($q->query['role']) ? Role::tryFrom((string) $q->query['role'])?->value : null;
            return Response::json(['data' => $this->users->all($role)]);
        });
        $r->add('POST', '/api/users', function (Request $q) {
            $this->requireRole($q, Role::Admin);
            $v = new Validator($q->json());
            $name = $v->string('name', 2, 120);
            $email = $v->email('email');
            $password = $v->string('password', 8, 200);
            $role = $v->enum('role', Role::class);
            if ($email !== null && $this->users->emailExists($email)) {
                $v->addError('email', 'Já cadastrado');
            }
            $v->validate();
            $newId = $this->users->create($name, $email, $password, $role->value, Clock::db($this->clock->now()));
            return Response::json($this->users->find($newId), 201);
        });
        $r->add('PATCH', '/api/users/{id}', function (Request $q) use ($id) {
            $this->requireRole($q, Role::Admin);
            $v = new Validator($q->json());
            $active = $v->bool('active', true);
            $v->validate();
            if ($id($q) === $q->user['id'] && !$active) {
                throw new HttpException(409, 'self_deactivation', 'Você não pode desativar a própria conta');
            }
            $this->users->find($id($q)) ?? throw HttpException::notFound('Usuário');
            $this->users->setActive($id($q), $active);
            return Response::json($this->users->find($id($q)));
        });
    }

    private function requireRole(Request $q, Role ...$roles): void
    {
        if (!in_array(Role::from($q->user['role']), $roles, true)) {
            throw HttpException::forbidden();
        }
    }
}
