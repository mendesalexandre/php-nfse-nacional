<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\DTO;

use PhpNfseNacional\DTO\Valores;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class ValoresTest extends TestCase
{
    public function test_valores_validos_sao_aceitos(): void
    {
        $v = new Valores(
            valorServicos: 100.00,
            deducoesReducoes: 20.00,
            aliquotaIssqnPercentual: 4.00,
        );

        self::assertSame(100.00, $v->valorServicos);
        self::assertSame(20.00, $v->deducoesReducoes);
        self::assertSame(4.00, $v->aliquotaIssqnPercentual);
    }

    public function test_base_calculo_eh_vserv_menos_vdr(): void
    {
        $v = new Valores(100.00, 20.00, 4.00);
        self::assertSame(80.00, $v->baseCalculo());
    }

    public function test_issqn_apurado_eh_bc_vezes_aliquota(): void
    {
        $v = new Valores(100.00, 20.00, 4.00);
        // 80 × 4% = 3.20
        self::assertSame(3.20, $v->valorIssqn());
    }

    public function test_valor_servicos_zero_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('valorServicos deve ser maior que zero');
        new Valores(0.0, 0.0, 4.0);
    }

    public function test_deducoes_negativas_sao_rejeitadas(): void
    {
        $this->expectException(ValidationException::class);
        new Valores(100.0, -5.0, 4.0);
    }

    public function test_deducoes_maiores_que_servicos_sao_rejeitadas(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('maior que valorServicos');
        new Valores(100.0, 150.0, 4.0);
    }

    public function test_aliquota_negativa_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Valores(100.0, 0.0, -1.0);
    }

    public function test_aliquota_acima_de_10_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Valores(100.0, 0.0, 15.0);
    }

    public function test_caso_real_padrao_os_543624(): void
    {
        // Cenário com 3 atos: vServ=73,30, vDR=22,00 (deduções + ISSQN por dentro)
        $v = new Valores(
            valorServicos: 73.30,
            deducoesReducoes: 22.00,
            aliquotaIssqnPercentual: 4.00,
        );
        // SEFIN computa: vBC = vServ - vDR = 51.30, ISSQN = 51.30 × 4% = 2.052 → 2.05
        self::assertSame(51.30, $v->baseCalculo());
        self::assertEqualsWithDelta(2.05, $v->valorIssqn(), 0.005);
    }
}
