<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Danfse\DanfseXmlParser;
use PhpNfseNacional\Danfse\Layouts\DanfseLayoutV2;
use PHPUnit\Framework\TestCase;
use TCPDF;

/**
 * NT 008 v1.02 (14/07/2026) — ajuste no tratamento de vPis, vCofins e
 * tpRetPisCofins no bloco TRIBUTAÇÃO FEDERAL do DANFSe V2.
 */
final class DanfseLayoutV2PisCofinsTest extends TestCase
{
    /**
     * Renderiza o V2 com a fixture + `<tribFed>` injetado e devolve
     * [rótulo => valor impresso na célula seguinte].
     *
     * @return array<string, string>
     */
    private function renderizar(string $tribFed): array
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        // tribFed entra em DPS/infDPS/valores/trib, antes de totTrib
        $xml = str_replace('<totTrib>', $tribFed . '<totTrib>', $xml, $n);
        self::assertSame(1, $n, 'fixture deve ter exatamente um <totTrib>');

        $pdf = new class ('P', 'mm', 'A4', true, 'UTF-8', false) extends TCPDF {
            /** @var list<string> */
            public array $textos = [];

            public function Cell($w, $h = 0, $txt = '', $border = 0, $ln = 0, $align = '', $fill = false, $link = '', $stretch = 0, $ignore_min_height = false, $calign = 'T', $valign = 'M')
            {
                $this->textos[] = (string) $txt;
                return parent::Cell($w, $h, $txt, $border, $ln, $align, $fill, $link, $stretch, $ignore_min_height, $calign, $valign);
            }
        };
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->AddPage();

        (new DanfseLayoutV2())->renderizar((new DanfseXmlParser())->parse($xml), $pdf);

        $mapa = [];
        foreach ($pdf->textos as $i => $t) {
            $mapa[mb_strtoupper($t)] = $pdf->textos[$i + 1] ?? '';
        }
        return $mapa;
    }

    public function test_retido_tp1_soma_pis_cofins_no_csll_e_zera_debito(): void
    {
        $m = $this->renderizar(
            '<tribFed><piscofins><vPis>1.00</vPis><vCofins>4.00</vCofins><tpRetPisCofins>1</tpRetPisCofins></piscofins>'
            . '<vRetCSLL>2.50</vRetCSLL></tribFed>'
        );

        self::assertSame('R$ 7,50', $m['CONTRIBUIÇÕES SOCIAIS - RETIDAS']);
        self::assertSame('R$ 0,00', $m['PIS - DÉBITO APURAÇÃO PRÓPRIA']);
        self::assertSame('R$ 0,00', $m['COFINS - DÉBITO APURAÇÃO PRÓPRIA']);
        self::assertSame('PIS/COFINS Retido', $m['DESCRIÇÃO CONTRIB. SOCIAIS - RETIDAS']);
    }

    public function test_nao_retido_tp2_mantem_valores_informados(): void
    {
        $m = $this->renderizar(
            '<tribFed><piscofins><vPis>1.00</vPis><vCofins>4.00</vCofins><tpRetPisCofins>2</tpRetPisCofins></piscofins>'
            . '<vRetCSLL>2.50</vRetCSLL></tribFed>'
        );

        self::assertSame('R$ 2,50', $m['CONTRIBUIÇÕES SOCIAIS - RETIDAS']);
        self::assertSame('R$ 1,00', $m['PIS - DÉBITO APURAÇÃO PRÓPRIA']);
        self::assertSame('R$ 4,00', $m['COFINS - DÉBITO APURAÇÃO PRÓPRIA']);
        self::assertSame('PIS/COFINS Não Retido', $m['DESCRIÇÃO CONTRIB. SOCIAIS - RETIDAS']);
    }

    public function test_dominio_completo_do_tpRetPisCofins(): void
    {
        $descricoes = \PhpNfseNacional\Danfse\DanfseLayout::tipoRetencaoPisCofins();
        self::assertSame(range(0, 9), array_keys($descricoes));
        self::assertSame('PIS/COFINS/CSLL Retidos', $descricoes[3]);
        self::assertSame('COFINS Não Retido, PIS/CSLL Retidos', $descricoes[9]);
    }
}
