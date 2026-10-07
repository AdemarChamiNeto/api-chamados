<?php

declare(strict_types=1);

namespace Chamados\Http;

/** Erro que vira resposta JSON: {"error": {"code", "message", "details"?}}. */
final class HttpException extends \RuntimeException
{
    /** @param array<string,string> $details */
    public function __construct(
        public readonly int $status,
        public readonly string $errorCode,
        string $message,
        public readonly array $details = [],
    ) {
        parent::__construct($message);
    }

    public static function notFound(string $what = 'Recurso'): self
    {
        return new self(404, 'not_found', "$what não encontrado");
    }

    public static function forbidden(string $message = 'Sem permissão para esta ação'): self
    {
        return new self(403, 'forbidden', $message);
    }

    /** @param array<string,string> $details */
    public static function validation(array $details): self
    {
        return new self(422, 'validation_error', 'Dados inválidos', $details);
    }
}
