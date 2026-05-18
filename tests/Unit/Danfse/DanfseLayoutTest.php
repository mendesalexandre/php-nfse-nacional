<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Danfse\DanfseLayout;
use PHPUnit\Framework\TestCase;

final class DanfseLayoutTest extends TestCase
{
    /**
     * Mapeamento `tribISSQN` conforme leiaute SefinNacional V1.00.02
     * (Anexo IV, linha 256). Os valores estavam invertidos antes desse
     * fix — 2/3/4 mapeavam para Exportação/NãoIncid/Imunidade quando o
     * oficial é Imunidade/Exportação/NãoIncid.
     */
    public function test_tribISSQN_mapping_oficial(): void
    {
        $labels = DanfseLayout::tipoTributacaoIssqn();
        self::assertSame('Operação Tributável', $labels[1]);
        self::assertSame('Imunidade', $labels[2]);
        self::assertSame('Exportação de Serviço', $labels[3]);
        self::assertSame('Não Incidência', $labels[4]);
    }
}
