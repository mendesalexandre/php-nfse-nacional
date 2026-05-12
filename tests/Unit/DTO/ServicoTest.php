<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\DTO;

use PhpNfseNacional\DTO\Servico;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ServicoTest extends TestCase
{
    public function test_defaults_de_cartorio(): void
    {
        $s = new Servico(
            discriminacao: 'Certidão de matrícula',
            codigoMunicipioPrestacao: '5107909',
        );

        self::assertSame('210101', $s->cTribNac);   // serviços notariais
        self::assertSame('113040000', $s->cNBS);
        self::assertSame('100301', $s->cIndOp);
    }

    public function test_discriminacao_curta_demais_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Servico(discriminacao: 'curta', codigoMunicipioPrestacao: '5107909');
    }

    public function test_discriminacao_longa_demais_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Servico(
            discriminacao: str_repeat('a', 2001),
            codigoMunicipioPrestacao: '5107909',
        );
    }

    public function test_codigo_municipio_invalido_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Servico(discriminacao: 'Certidão de matrícula', codigoMunicipioPrestacao: 'abc');
    }

    public function test_cTribNac_com_5_digitos_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Servico(
            discriminacao: 'Certidão de matrícula',
            codigoMunicipioPrestacao: '5107909',
            cTribNac: '21010',
        );
    }
}
