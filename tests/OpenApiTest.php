<?php

declare(strict_types=1);

namespace Chamados\Tests;

use PHPUnit\Framework\TestCase;

/** Garante que a documentação não fica para trás: toda rota registrada no App aparece no openapi.yaml. */
final class OpenApiTest extends TestCase
{
    public function testEveryRouteIsDocumented(): void
    {
        preg_match_all("/\\\$r->add\\('(\\w+)', '([^']+)'/", (string) file_get_contents(__DIR__ . '/../src/App.php'), $routes, PREG_SET_ORDER);
        $spec = (string) file_get_contents(__DIR__ . '/../public/openapi.yaml');
        $this->assertGreaterThan(10, count($routes));

        foreach ($routes as [, $method, $path]) {
            $block = preg_match('#^  ' . preg_quote($path, '#') . ":\n((?:    .*\n|\n)*)#m", $spec, $m) ? $m[1] : null;
            $this->assertNotNull($block, "rota $path não documentada");
            $this->assertStringContainsString('    ' . strtolower($method) . ':', $block, "$method $path não documentado");
        }
    }
}
