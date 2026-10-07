<?php

declare(strict_types=1);

namespace Chamados\Tests;

use Chamados\Domain\BusinessCalendar;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BusinessCalendarTest extends TestCase
{
    private BusinessCalendar $cal;

    protected function setUp(): void
    {
        $this->cal = new BusinessCalendar(['2026-10-12']); // feriado numa segunda
    }

    private static function sp(string $local): DateTimeImmutable
    {
        return new DateTimeImmutable($local, new DateTimeZone('America/Sao_Paulo'));
    }

    /** @return array<string,array{string,int,string}> */
    public static function additions(): array
    {
        return [
            'dentro da manhã' => ['2026-10-07 09:00', 60, '2026-10-07 10:00'],
            'atravessa o almoço' => ['2026-10-07 11:30', 60, '2026-10-07 13:30'],
            'começa no almoço' => ['2026-10-07 12:30', 30, '2026-10-07 13:30'],
            'antes do expediente' => ['2026-10-07 06:00', 15, '2026-10-07 08:15'],
            'vira o dia' => ['2026-10-07 17:00', 120, '2026-10-08 09:00'],
            'sexta para segunda' => ['2026-10-02 17:30', 60, '2026-10-05 08:30'],
            'pula feriado' => ['2026-10-09 17:00', 120, '2026-10-13 09:00'],
            'fim de semana conta da segunda' => ['2026-10-10 10:00', 30, '2026-10-13 08:30'],
            'termina no fim exato da janela' => ['2026-10-07 08:00', 240, '2026-10-07 12:00'],
            'dia inteiro = 9 h' => ['2026-10-07 08:00', 540, '2026-10-07 18:00'],
            'zero minutos fora do expediente' => ['2026-10-07 19:00', 0, '2026-10-08 08:00'],
        ];
    }

    #[DataProvider('additions')]
    public function testAddBusinessMinutes(string $start, int $minutes, string $expected): void
    {
        $got = $this->cal->addBusinessMinutes(self::sp($start), $minutes);
        $this->assertSame($expected, $got->setTimezone(new DateTimeZone('America/Sao_Paulo'))->format('Y-m-d H:i'));
    }

    public function testKeepsTheInputTimezone(): void
    {
        $utc = new DateTimeImmutable('2026-10-07 12:00', new DateTimeZone('UTC')); // 09:00 em SP
        $got = $this->cal->addBusinessMinutes($utc, 60);
        $this->assertSame('2026-10-07 13:00 UTC', $got->format('Y-m-d H:i T'));
    }

    public function testBusinessMinutesBetween(): void
    {
        $this->assertSame(60, $this->cal->businessMinutesBetween(self::sp('2026-10-07 11:30'), self::sp('2026-10-07 13:30')));
        $this->assertSame(540, $this->cal->businessMinutesBetween(self::sp('2026-10-07 00:00'), self::sp('2026-10-08 00:00')));
        $this->assertSame(0, $this->cal->businessMinutesBetween(self::sp('2026-10-10 08:00'), self::sp('2026-10-12 18:00')));
        $this->assertSame(0, $this->cal->businessMinutesBetween(self::sp('2026-10-08 10:00'), self::sp('2026-10-07 10:00')));
        // sexta 17:00 -> terça 09:00 com feriado na segunda: 1 h + 1 h
        $this->assertSame(120, $this->cal->businessMinutesBetween(self::sp('2026-10-09 17:00'), self::sp('2026-10-13 09:00')));
    }

    public function testAddAndBetweenAreInverse(): void
    {
        $start = self::sp('2026-10-07 10:17');
        foreach ([1, 59, 223, 540, 1000, 3333] as $m) {
            $end = $this->cal->addBusinessMinutes($start, $m);
            $this->assertSame($m, $this->cal->businessMinutesBetween($start, $end), "minutos: $m");
        }
    }

    public function testRejectsNegativeMinutes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->cal->addBusinessMinutes(self::sp('2026-10-07 10:00'), -1);
    }
}
