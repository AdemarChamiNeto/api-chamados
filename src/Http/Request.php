<?php

declare(strict_types=1);

namespace Chamados\Http;

final class Request
{
    /** @var array<string,string> parâmetros de rota, preenchidos pelo Router */
    public array $params = [];
    /** @var array<string,mixed> usuário autenticado (preenchido pelo App) */
    public ?array $user = null;

    /**
     * @param array<string,mixed> $query
     * @param array<string,string> $headers nomes em minúsculas
     */
    public function __construct(
        public readonly string $method,
        public readonly string $path,
        public readonly array $query = [],
        public readonly array $headers = [],
        public readonly string $body = '',
        public readonly string $ip = '127.0.0.1',
    ) {
    }

    public static function fromGlobals(): self
    {
        $headers = [];
        foreach ($_SERVER as $k => $v) {
            if (str_starts_with($k, 'HTTP_')) {
                $headers[strtolower(str_replace('_', '-', substr($k, 5)))] = (string) $v;
            }
        }
        if (isset($_SERVER['CONTENT_TYPE'])) {
            $headers['content-type'] = (string) $_SERVER['CONTENT_TYPE'];
        }
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
        return new self(
            strtoupper($_SERVER['REQUEST_METHOD'] ?? 'GET'),
            rtrim($path, '/') ?: '/',
            $_GET,
            $headers,
            (string) file_get_contents('php://input'),
            (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'),
        );
    }

    /** @return array<string,mixed> */
    public function json(): array
    {
        if (trim($this->body) === '') {
            return [];
        }
        try {
            $data = json_decode($this->body, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new HttpException(400, 'invalid_json', 'JSON inválido');
        }
        if (!is_array($data) || array_is_list($data) && $data !== []) {
            throw new HttpException(400, 'invalid_json', 'O corpo deve ser um objeto JSON');
        }
        return $data;
    }

    public function header(string $name): ?string
    {
        return $this->headers[strtolower($name)] ?? null;
    }

    public function bearerToken(): ?string
    {
        $h = $this->header('authorization');
        return $h !== null && preg_match('/^Bearer\s+(\S+)$/i', $h, $m) ? $m[1] : null;
    }
}
