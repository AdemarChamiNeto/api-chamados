<?php

declare(strict_types=1);

namespace Chamados\Http;

use Chamados\Database;
use PDO;

/**
 * Rate limit em janela fixa, com o contador no banco: cada requisição PHP é um processo novo,
 * então contador em memória não serviria (e o banco já é compartilhado entre instâncias).
 */
final class RateLimiter
{
    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Conta uma tentativa no bucket; se passou do limite, lança 429 com Retry-After.
     *
     * @throws HttpException
     */
    public function hit(string $bucket, int $limit, int $windowSeconds, int $now): void
    {
        $window = $now - $now % $windowSeconds;
        $upsert = Database::driver($this->db) === 'mysql'
            ? 'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE hits = hits + 1'
            : 'INSERT INTO rate_limits (bucket, window_start, hits) VALUES (?, ?, 1) ON CONFLICT (bucket, window_start) DO UPDATE SET hits = hits + 1';
        $this->db->prepare($upsert)->execute([$bucket, $window]);

        $st = $this->db->prepare('SELECT hits FROM rate_limits WHERE bucket = ? AND window_start = ?');
        $st->execute([$bucket, $window]);
        if ((int) $st->fetchColumn() > $limit) {
            throw new HttpException(429, 'too_many_requests', 'Muitas requisições. Tente de novo em instantes.', [], [
                'Retry-After' => (string) max(1, $window + $windowSeconds - $now),
            ]);
        }

        // limpeza ocasional das janelas antigas (1 a cada ~100 requisições)
        if (random_int(1, 100) === 1) {
            $this->db->prepare('DELETE FROM rate_limits WHERE window_start < ?')->execute([$now - 86400]);
        }
    }
}
