<?php

declare(strict_types=1);

namespace Chamados\Http;

/** Validação simples e explícita; junta todos os erros antes de responder 422. */
final class Validator
{
    /** @var array<string,string> */
    private array $errors = [];

    /** @param array<string,mixed> $data */
    public function __construct(private readonly array $data)
    {
    }

    public function string(string $field, int $min = 1, int $max = 255, bool $required = true): ?string
    {
        $v = $this->data[$field] ?? null;
        if ($v === null || (is_string($v) && trim($v) === '')) {
            if ($required) {
                $this->errors[$field] = 'Obrigatório';
            }
            return null;
        }
        if (!is_string($v)) {
            $this->errors[$field] = 'Deve ser texto';
            return null;
        }
        $v = trim($v);
        $len = mb_strlen($v);
        if ($len < $min || $len > $max) {
            $this->errors[$field] = "Deve ter entre $min e $max caracteres";
            return null;
        }
        return $v;
    }

    public function email(string $field): ?string
    {
        $v = $this->string($field, 3, 190);
        if ($v !== null && !filter_var($v, FILTER_VALIDATE_EMAIL)) {
            $this->errors[$field] = 'Email inválido';
            return null;
        }
        return $v === null ? null : mb_strtolower($v);
    }

    /**
     * @template T of \BackedEnum
     * @param class-string<T> $enum
     * @return T|null
     */
    public function enum(string $field, string $enum, bool $required = true): ?\BackedEnum
    {
        $v = $this->data[$field] ?? null;
        if ($v === null) {
            if ($required) {
                $this->errors[$field] = 'Obrigatório';
            }
            return null;
        }
        $case = is_string($v) ? $enum::tryFrom($v) : null;
        if ($case === null) {
            $this->errors[$field] = 'Valor inválido; use: ' . implode(', ', array_map(fn ($c) => $c->value, $enum::cases()));
        }
        return $case;
    }

    public function bool(string $field, bool $default = false): bool
    {
        $v = $this->data[$field] ?? $default;
        if (!is_bool($v)) {
            $this->errors[$field] = 'Deve ser true ou false';
            return $default;
        }
        return $v;
    }

    public function nullableInt(string $field): ?int
    {
        if (!array_key_exists($field, $this->data)) {
            $this->errors[$field] = 'Obrigatório (use null para remover)';
            return null;
        }
        $v = $this->data[$field];
        if ($v !== null && !is_int($v)) {
            $this->errors[$field] = 'Deve ser um inteiro ou null';
            return null;
        }
        return $v;
    }

    public function addError(string $field, string $message): void
    {
        $this->errors[$field] = $message;
    }

    public function validate(): void
    {
        if ($this->errors) {
            throw HttpException::validation($this->errors);
        }
    }
}
