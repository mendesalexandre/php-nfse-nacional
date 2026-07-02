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
        self::assertStringNotContainsString('DATA CIENTIFICAÇÃO', $texto);
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
        // Labels em caixa alta (a pedido, 02/07/2026).
        self::assertStringContainsString('DATA CIENTIFICAÇÃO', $texto);
        self::assertStringContainsString('IDENTIFICAÇÃO E ASSINATURA', $texto);
        self::assertStringContainsString('Nº NFS-E / CHAVE NFS-E', $texto);
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
        self::assertStringContainsString('DATA CIENTIFICAÇÃO', $texto);
        // Sem preenchimento automático — a data de emissão aparece só 1x
        // (no bloco DADOS DA NFS-e), não duplicada no canhoto.
        self::assertSame(1, substr_count($texto, '15/01/2026 10:00:00'));
    }

    public function test_situacao_e_finalidade_nao_usam_o_mesmo_texto(): void
    {
        // Bug real (02/07/2026): SITUAÇÃO DA NFS-E (fonte: cStat) copiava o
        // texto de FINALIDADE (fonte: finNFSe) — "NFS-e regular" nas duas.
        // São campos com fontes de dados diferentes no XML.
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);
        self::assertStringContainsString('NFS-e Gerada', $texto);
        self::assertStringContainsString('NFS-e regular', $texto);
    }

    public function test_canhoto_chave_nao_estoura_a_pagina(): void
    {
        // Bug real (02/07/2026): 3 colunas iguais (6.8cm) não cabiam
        // "número / chave de 50 dígitos" (~58 chars) na terceira coluna —
        // texto vazava pra fora da borda da folha. Valida que o texto do
        // PDF renderizado contém a chave completa (prova de que
        // renderCelulaAutoFit encolheu a fonte o suficiente pra caber).
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml(
            $xml,
            new DanfseCustomizacao(canhoto: \PhpNfseNacional\Enums\TipoCanhoto::PreenchidoAutomaticamente),
        );
        $texto = $this->textoDoPdf($pdf);
        // Chave de 50 dígitos da fixture nfse-autorizada.xml
        self::assertStringContainsString(
            '35012345200001234567890123456789012345678123456789',
            str_replace(' ', '', $texto),
        );
    }

    public function test_descricao_servico_longa_nao_sobrepoe_bloco_seguinte(): void
    {
        // Bug real (02/07/2026): altura fixa de 1.10cm pra "Descrição do
        // Serviço" sobrepunha "TRIBUTAÇÃO MUNICIPAL (ISSQN)" quando o
        // texto precisava de mais de ~2 linhas. Agora a altura é calculada
        // dinamicamente via TCPDF::getStringHeight().
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $descricaoLonga = trim(str_repeat(
            'Servico de exemplo com texto bem longo pra forcar quebra de linha multipla. ',
            8,
        ));
        $xml = str_replace('Servico de exemplo - valores ficticios para teste', $descricaoLonga, $xml);

        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);

        $posDescricaoFim = strrpos($texto, 'forcar quebra de linha multipla.');
        $posTributacao = strpos($texto, 'TRIBUTAÇÃO MUNICIPAL');
        self::assertNotFalse($posDescricaoFim, 'descrição completa não encontrada no PDF');
        self::assertNotFalse($posTributacao, 'bloco TRIBUTAÇÃO MUNICIPAL não encontrado no PDF');
        self::assertGreaterThan(
            $posDescricaoFim,
            $posTributacao,
            'TRIBUTAÇÃO MUNICIPAL deveria vir depois da descrição completa no texto extraído',
        );
    }

    public function test_ambiente_gerador_e_sempre_sistema_proprio(): void
    {
        // O SDK só gera DANFSe LOCALMENTE (nunca via ADN nesse fluxo) —
        // "Ambiente Gerador" tem que refletir isso, não "Sistema Nacional"
        // (que era o valor hardcoded errado antes, 02/07/2026).
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);
        self::assertStringContainsString('Ambiente Gerador: Sistema Próprio', $texto);
        self::assertStringNotContainsString('Sistema Nacional', $texto);
    }

    public function test_nome_tomador_longo_nao_sobrepoe_municipio(): void
    {
        // Bug real (02/07/2026): "Nome / Nome Empresarial" usava Cell()
        // sem wrap/auto-fit — nomes institucionais longos (comuns em
        // cartórios, ex: "OFICIAL DE REGISTRO CIVIL DAS PESSOAS NATURAIS
        // E TABELIÃO DE NOTAS DO DISTRITO DE...") vazavam pra cima da
        // coluna "Município / Sigla UF" ao lado.
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $nomeLongo = 'OFICIAL DE REGISTRO CIVIL DAS PESSOAS NATURAIS E TABELIAO DE '
            . 'NOTAS DO DISTRITO DE OURO BRANCO COMARCA';
        $xml = str_replace('JOAO DA SILVA', $nomeLongo, $xml);

        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);

        // Nome desse tamanho não cabe nem na fonte mínima (6pt) — trunca
        // com reticências em vez de sobrepor a coluna vizinha. Confere que
        // pelo menos o INÍCIO do nome aparece (não ficou em branco) e que
        // "Município / Sigla UF" segue legível ao lado, sem overlap.
        self::assertStringContainsString('OFICIAL DE REGISTRO CIVIL', $texto);
        self::assertStringContainsString('São Paulo / SP', $texto);
    }

    public function test_nome_tomador_moderadamente_longo_nao_trunca(): void
    {
        // Nomes que cabem na fonte mínima (6pt) não devem ser truncados —
        // só encolhem até o piso, sem perder informação.
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $nomeModerado = 'CARTORIO DE REGISTRO DE IMOVEIS DE CIDADE EXEMPLO LTDA';
        $xml = str_replace('JOAO DA SILVA', $nomeModerado, $xml);

        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);

        self::assertStringContainsString($nomeModerado, $texto);
        self::assertStringNotContainsString($nomeModerado . '...', $texto);
    }
}
