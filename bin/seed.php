<?php

declare(strict_types=1);

require __DIR__ . '/../src/bootstrap.php';

use Chamados\Database;
use Chamados\Seeder;

$dsn = getenv('DB_DSN') ?: 'sqlite:' . __DIR__ . '/../database/chamados.sqlite';
$db = Database::connect($dsn, getenv('DB_USER') ?: null, getenv('DB_PASSWORD') ?: null);
Database::migrate($db);
if ((int) $db->query('SELECT COUNT(*) FROM tickets')->fetchColumn() > 0) {
    echo "Já existem chamados; seed ignorado.\n";
    exit(0);
}
Seeder::run($db, getenv('JWT_SECRET') ?: throw new RuntimeException('Defina JWT_SECRET'));
echo "Dados de demonstração criados. Senha de todos os usuários: " . Seeder::PASSWORD . "\n";
