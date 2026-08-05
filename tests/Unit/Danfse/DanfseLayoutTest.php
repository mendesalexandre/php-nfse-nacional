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

    /**
     * Formatos confirmados contra 3 DANFSe reais baixadas do portal
     * nacional (gov.br) em 05/08/2026 — CEP e Código IBGE levam um ponto
     * a mais do que o formato "comum" (`DD.DDD-DDD`/`UF.MMMMM`), e
     * cTribNac/cNBS são agrupados conforme a hierarquia oficial dos
     * códigos (item.subitem.desdobro / seção.capítulo.subcapítulo.item).
     */
    public function test_formatarCep_formato_oficial_com_ponto_extra(): void
    {
        self::assertSame('01.310-100', DanfseLayout::formatarCep('01310100'));
        self::assertSame('-', DanfseLayout::formatarCep(null));
        self::assertSame('123', DanfseLayout::formatarCep('123'));
    }

    public function test_formatarCodigoIbge_uf_ponto_municipio(): void
    {
        self::assertSame('35.50308', DanfseLayout::formatarCodigoIbge('3550308'));
        self::assertSame('-', DanfseLayout::formatarCodigoIbge(null));
    }

    public function test_formatarCodigoTributacaoNacional_item_subitem_desdobro(): void
    {
        self::assertSame('21.01.01', DanfseLayout::formatarCodigoTributacaoNacional('210101'));
        self::assertSame('-', DanfseLayout::formatarCodigoTributacaoNacional(null));
    }

    public function test_formatarNbs_secao_capitulo_subcapitulo_item(): void
    {
        self::assertSame('1.1304.00.00', DanfseLayout::formatarNbs('113040000'));
        self::assertSame('-', DanfseLayout::formatarNbs(null));
    }
}
