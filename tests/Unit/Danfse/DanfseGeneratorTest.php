<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Danfse\DanfseCustomizacao;
use PhpNfseNacional\Danfse\DanfseGenerator;
use PhpNfseNacional\Danfse\DanfseXmlParser;
use PHPUnit\Framework\TestCase;

final class DanfseGeneratorTest extends TestCase
{
    /** Fixture autorizada padrão: cStat=100 → cancelada=false. */
    private function dadosRegular(): \PhpNfseNacional\Danfse\DanfseDados
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        return (new DanfseXmlParser())->parse($xml);
    }

    /** Mesma fixture com cStat=101 → cancelada=true (caminho do parser). */
    private function dadosCanceladaPeloXml(): \PhpNfseNacional\Danfse\DanfseDados
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $xml = str_replace('<cStat>100</cStat>', '<cStat>101</cStat>', $xml);
        return (new DanfseXmlParser())->parse($xml);
    }

    public function test_sem_override_e_nota_regular_nao_tem_marca(): void
    {
        self::assertNull(DanfseGenerator::definirMarcaAgua($this->dadosRegular(), null));
    }

    public function test_override_cancelada_forca_marca_mesmo_com_cStat_100(): void
    {
        // Cenário real: cancelamento é evento, a NFS-e mantém cStat=100; o
        // estado vem de NFSe::verificarCancelamento() e entra via customização.
        $custom = new DanfseCustomizacao(cancelada: true);
        self::assertSame('CANCELADA', DanfseGenerator::definirMarcaAgua($this->dadosRegular(), $custom));
    }

    public function test_override_substituida_forca_marca(): void
    {
        $custom = new DanfseCustomizacao(substituida: true);
        self::assertSame('SUBSTITUÍDA', DanfseGenerator::definirMarcaAgua($this->dadosRegular(), $custom));
    }

    public function test_cancelada_tem_precedencia_sobre_substituida(): void
    {
        $custom = new DanfseCustomizacao(cancelada: true, substituida: true);
        self::assertSame('CANCELADA', DanfseGenerator::definirMarcaAgua($this->dadosRegular(), $custom));
    }

    public function test_override_false_suprime_marca_vinda_do_xml(): void
    {
        // dados.cancelada=true (cStat=101), mas o override força false → sem marca.
        $custom = new DanfseCustomizacao(cancelada: false);
        self::assertNull(DanfseGenerator::definirMarcaAgua($this->dadosCanceladaPeloXml(), $custom));
    }

    public function test_sem_override_usa_cStat_do_xml(): void
    {
        // null no override → cai no valor do XML (cStat=101 → CANCELADA).
        self::assertSame('CANCELADA', DanfseGenerator::definirMarcaAgua($this->dadosCanceladaPeloXml(), null));
    }

    public function test_gerarDoXml_com_cancelada_override_retorna_pdf_valido(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())
            ->gerarDoXml($xml, new DanfseCustomizacao(cancelada: true));
        self::assertStringStartsWith('%PDF-', $pdf);
    }

    private function textoDoPdf(string $pdf): string
    {
        $tmp = tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);
        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível no ambiente');
        }
        return $texto;
    }

    public function test_sem_customizacao_nao_renderiza_canhoto(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);
        self::assertStringNotContainsString('Data Cientificação', $texto);
    }

    public function test_canhoto_preenchido_automaticamente_repete_data_emissao(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml(
            $xml,
            new DanfseCustomizacao(canhoto: \PhpNfseNacional\Enums\TipoCanhoto::PreenchidoAutomaticamente),
        );
        $texto = $this->textoDoPdf($pdf);
        self::assertStringContainsString('Data Cientificação', $texto);
        self::assertStringContainsString('Identificação e Assinatura', $texto);
        self::assertStringContainsString('Nº NFS-e / Chave NFS-e', $texto);
        // A data de emissão (15/01/2026 10:00:00, da fixture) aparece 3x:
        // bloco DADOS DA NFS-e + "Data Cientificação" + "Identificação e
        // Assinatura" (ambos preenchidos automaticamente no canhoto).
        self::assertSame(3, substr_count($texto, '15/01/2026 10:00:00'));
    }

    public function test_canhoto_em_branco_nao_repete_data_emissao(): void
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml(
            $xml,
            new DanfseCustomizacao(canhoto: \PhpNfseNacional\Enums\TipoCanhoto::EmBranco),
        );
        $texto = $this->textoDoPdf($pdf);
        self::assertStringContainsString('Data Cientificação', $texto);
        // Sem preenchimento automático — a data de emissão aparece só 1x
        // (no bloco DADOS DA NFS-e), não duplicada no canhoto.
        self::assertSame(1, substr_count($texto, '15/01/2026 10:00:00'));
    }
}
