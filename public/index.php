<?php

declare(strict_types=1);

// Com o servidor embutido do PHP (php -S), arquivos estáticos de public/ são servidos direto.
if (PHP_SAPI === 'cli-server' && is_file(__DIR__ . parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH))) {
    return false;
}

require __DIR__ . '/../src/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if ($path === '/' || $path === '/docs') {
    header('Location: /docs.html');
    exit;
}

Chamados\App::fromEnv()->handle(Chamados\Http\Request::fromGlobals())->send();
