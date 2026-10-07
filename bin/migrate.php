<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Chamados\Database;

$dsn = getenv('DB_DSN') ?: 'sqlite:' . __DIR__ . '/../database/chamados.sqlite';
$db = Database::connect($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null);
Database::migrate($db);
echo "Schema aplicado (" . Database::driver($db) . ")\n";
