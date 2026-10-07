<?php

declare(strict_types=1);

// Autoload do Composer quando existe; senão um PSR-4 mínimo (a API não tem dependências de runtime).
$vendor = __DIR__ . '/../vendor/autoload.php';
if (is_file($vendor)) {
    require $vendor;
} else {
    spl_autoload_register(static function (string $class): void {
        if (str_starts_with($class, 'Chamados\\')) {
            $file = __DIR__ . '/' . str_replace('\\', '/', substr($class, 9)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

// Carrega o .env (só variáveis que ainda não existem no ambiente).
$envFile = __DIR__ . '/../.env';
if (is_file($envFile)) {
    foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (preg_match('/^\s*([A-Z_][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m) && getenv($m[1]) === false) {
            putenv($m[1] . '=' . trim($m[2], " \t\"'"));
        }
    }
}

date_default_timezone_set('UTC');
