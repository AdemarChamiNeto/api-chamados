<?php

declare(strict_types=1);

namespace Chamados\Http;

/** Roteador mínimo: método + caminho com parâmetros {nome} (só dígitos para {id}). */
final class Router
{
    /** @var list<array{string,string,callable,bool}> */
    private array $routes = [];

    /** @param callable(Request):Response $handler */
    public function add(string $method, string $pattern, callable $handler, bool $auth = true): void
    {
        $regex = preg_replace_callback(
            '/\{(\w+)\}/',
            fn ($m) => $m[1] === 'id' ? '(?P<id>\d+)' : '(?P<' . $m[1] . '>[^/]+)',
            $pattern,
        );
        $this->routes[] = [$method, '#^' . $regex . '$#', $handler, $auth];
    }

    /** @return array{callable, bool, array<string,string>} handler, exige auth, parâmetros */
    public function match(Request $req): array
    {
        $allowed = [];
        foreach ($this->routes as [$method, $regex, $handler, $auth]) {
            if (!preg_match($regex, $req->path, $m)) {
                continue;
            }
            if ($method !== $req->method) {
                $allowed[] = $method;
                continue;
            }
            return [$handler, $auth, array_filter($m, 'is_string', ARRAY_FILTER_USE_KEY)];
        }
        if ($allowed) {
            throw new HttpException(405, 'method_not_allowed', 'Método não permitido; use ' . implode(', ', $allowed));
        }
        throw new HttpException(404, 'not_found', 'Rota não encontrada');
    }
}
