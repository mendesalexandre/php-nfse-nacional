<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\DTO;

use DateTimeImmutable;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\TipoEmissaoDps;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class IdentificacaoTest extends TestCase
{
    public function test_defaults_aplicados(): void
    {
        $id = new Identificacao(numeroDps: 1);

        self::assertSame('1', $id->serie);
        self::assertSame(TipoEmissaoDps::Normal, $id->tipoEmissao);
        self::assertNull($id->dataCompetencia);
    }

    public function test_data_competencia_resolvida_usa_now_quando_nula(): void
    {
        $antes = new DateTimeImmutable();
        $resolvida = (new Identificacao(numeroDps: 1))->dataCompetenciaResolvida();
        $depois = new DateTimeImmutable();

        self::assertGreaterThanOrEqual($antes->getTimestamp(), $resolvida->getTimestamp());
        self::assertLessThanOrEqual($depois->getTimestamp(), $resolvida->getTimestamp());
    }

    public function test_data_competencia_resolvida_preserva_valor_explicito(): void
    {
        $data = new DateTimeImmutable('2026-01-15 10:00:00');
        $id = new Identificacao(numeroDps: 1, dataCompetencia: $data);

        self::assertSame($data, $id->dataCompetenciaResolvida());
    }

    public function test_numero_dps_zero_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Identificacao(numeroDps: 0);
    }

    public function test_numero_dps_acima_do_limite_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Identificacao(numeroDps: 100_000_000);
    }

    public function test_serie_vazia_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Identificacao(numeroDps: 1, serie: '');
    }

    public function test_serie_acima_de_5_chars_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Identificacao(numeroDps: 1, serie: '123456');
    }
}
