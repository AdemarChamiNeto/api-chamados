<?php

declare(strict_types=1);

namespace Chamados;

use Chamados\Auth\Jwt;
use Chamados\Auth\Secrets;
use Chamados\Domain\BusinessCalendar;
use Chamados\Domain\Role;
use Chamados\Domain\Sla;
use Chamados\Http\HttpException;
use Chamados\Http\Request;
use Chamados\Http\Response;
use Chamados\Http\RateLimiter;
use Chamados\Http\Router;
use Chamados\Http\Validator;
use Chamados\Repository\TicketRepository;
use Chamados\Repository\TwoFactorRepository;
use Chamados\Repository\UserRepository;
use Chamados\Service\AuthService;
use Chamados\Service\MetricsService;
use Chamados\Service\TicketService;
use Chamados\Service\TwoFactorService;
use PDO;

/** Monta as dependências e as rotas. handle() não toca em globais: os testes chamam direto, sem servidor. */
final class App
{
    private readonly Router $router;
    private readonly AuthService $auth;
    private readonly TicketService $tickets;
    private readonly MetricsService $metrics;
    private readonly UserRepository $users;
    private readonly TwoFactorService $twoFactor;
    private readonly RateLimiter $limiter;
    private readonly Jwt $jwt;
    /** @var array{read: int, write: int, two_factor: int} */
    private readonly array $limits;

    /**
     * Limites por padrão: leitura 120/min e escrita 30/min por usuário (ou IP), 2FA 5 tentativas/15 min por usuário.
     * O login tem o próprio freio por conta e por IP no AuthService.
     */
    public const DEFAULT_LIMITS = ['read' => 120, 'write' => 30, 'two_factor' => 5];

    /**
     * @param array{jwt_secret: string, totp_key: string, jwt_ttl_minutes?: int, holidays?: list<string>, cors_origin?: string,
     *              rate_limits?: array{read?: int, write?: int, two_factor?: int}} $config
     */
    public function __construct(PDO $db, private readonly array $config, private readonly Clock $clock = new Clock())
    {
        $this->users = new UserRepository($db);
        $ticketRepo = new TicketRepository($db);
        $sla = new Sla(new BusinessCalendar($config['holidays'] ?? []));
        $this->jwt = new Jwt($config['jwt_secret'], 60 * ($config['jwt_ttl_minutes'] ?? 480));
        $secrets = new Secrets($config['totp_key']);
        $twoFactorRepo = new TwoFactorRepository($db);
        $this->limiter = new RateLimiter($db);
        $this->limits = ($config['rate_limits'] ?? []) + self::DEFAULT_LIMITS;

        $this->auth = new AuthService($this->users, $twoFactorRepo, $secrets, $this->jwt, $clock);
        $this->twoFactor = new TwoFactorService($twoFactorRepo, $this->users, $this->auth, $secrets, $clock);
        $this->tickets = new TicketService($db, $ticketRepo, $this->users, $sla, $clock);
        $this->metrics = new MetricsService($ticketRepo, $clock);
        $this->router = new Router();
        $this->routes();
    }

    public static function fromEnv(): self
    {
        $env = static fn (string $k, ?string $d = null) => ($v = getenv($k)) !== false && $v !== '' ? $v : $d;
        $secret = $env('JWT_SECRET') ?? throw new \RuntimeException('Defina JWT_SECRET (veja .env.example)');
        $totpKey = $env('TOTP_ENCRYPTION_KEY') ?? throw new \RuntimeException('Defina TOTP_ENCRYPTION_KEY (gere com: openssl rand -hex 32)');
        $db = Database::connect($env('DB_DSN', 'sqlite:' . __DIR__ . '/../database/chamados.sqlite'), $env('DB_USER'), $env('DB_PASSWORD'));
        return new self($db, [
            'jwt_secret' => $secret,
            'totp_key' => $totpKey,
            'jwt_ttl_minutes' => (int) $env('JWT_TTL_MINUTES', '480'),
            'holidays' => array_values(array_filter(array_map('trim', explode(',', $env('SLA_HOLIDAYS', ''))))),
            'cors_origin' => $env('CORS_ORIGIN', ''),
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
            $this->throttle($req);
            $res = $handler($req);
        } catch (HttpException $e) {
            $error = ['code' => $e->errorCode, 'message' => $e->getMessage()];
            if ($e->details) {
                $error['details'] = $e->details;
            }
            $res = Response::json(['error' => $error], $e->status);
            $res->headers += $e->headers;
        } catch (\Throwable $e) {
            error_log((string) $e);
            $res = Response::json(['error' => ['code' => 'internal_error', 'message' => 'Erro interno']], 500);
        }
        return $this->withHeaders($res);
    }

    /**
     * Rate limit de toda rota (o login tem freio próprio no AuthService).
     * Quem: o usuário do token; sem login, o IP. Atrás do Portal (BFF) o IP é o do servidor do Portal,
     * por isso o limite por IP do usuário final fica no próprio Portal.
     */
    private function throttle(Request $req): void
    {
        $now = $this->clock->now()->getTimestamp();
        $who = $req->user !== null ? 'user:' . $req->user['id'] : 'ip:' . $req->ip;

        if ($req->path === '/api/auth/login') {
            return;
        }
        if ($req->path === '/api/auth/2fa') {
            // por usuário do token pendente: ninguém testa os 10⁶ códigos trocando de IP
            try {
                $sub = $this->jwt->verify((string) ($req->json()['pending_token'] ?? ''), $now)['sub'] ?? null;
                $who = $sub !== null ? "user:$sub" : $who;
            } catch (\Throwable) {
                // token inválido: conta pelo IP e o AuthService responde 401
            }
            $this->limiter->hit("2fa:$who", $this->limits['two_factor'], 900, $now);
            return;
        }
        if (str_starts_with($req->path, '/api/me/2fa/')) {
            $this->limiter->hit("2fa-manage:$who", $this->limits['two_factor'] * 2, 900, $now);
        }
        $req->method === 'GET'
            ? $this->limiter->hit("read:$who", $this->limits['read'], 60, $now)
            : $this->limiter->hit("write:$who", $this->limits['write'], 60, $now);
    }

    private function withHeaders(Response $res): Response
    {
        $res->headers += [
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'DENY',
            'Referrer-Policy' => 'no-referrer',
            'Cache-Control' => 'no-store',
        ];
        // CORS só para a origem configurada: o Portal (BFF) chama do servidor e a documentação roda na mesma origem
        $origin = $this->config['cors_origin'] ?? '';
        if ($origin !== '') {
            $res->headers += [
                'Access-Control-Allow-Origin' => $origin,
                'Access-Control-Allow-Headers' => 'Authorization, Content-Type',
                'Access-Control-Allow-Methods' => 'GET, POST, PATCH, OPTIONS',
                'Vary' => 'Origin',
            ];
        }
        return $res;
    }

    private function routes(): void
    {
        $r = $this->router;
        $id = static fn (Request $q): int => (int) $q->params['id'];

        $r->add('GET', '/api/health', fn () => Response::json(['ok' => true]), auth: false);
        $r->add('POST', '/api/auth/login', fn (Request $q) => Response::json($this->auth->login($q->json(), $q->ip)), auth: false);
        $r->add('POST', '/api/auth/2fa', fn (Request $q) => Response::json($this->auth->loginSecondFactor($q->json())), auth: false);
        $r->add('GET', '/api/me', fn (Request $q) => Response::json($q->user));
        $r->add('GET', '/api/me/2fa', fn (Request $q) => Response::json($this->twoFactor->status($q->user)));
        $r->add('POST', '/api/me/2fa/setup', fn (Request $q) => Response::json($this->twoFactor->setup($q->user)));
        $r->add('POST', '/api/me/2fa/enable', fn (Request $q) => Response::json($this->twoFactor->enable($q->user, $q->json())));
        $r->add('POST', '/api/me/2fa/disable', function (Request $q) {
            $this->twoFactor->disable($q->user, $q->json());
            return Response::noContent();
        });

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
