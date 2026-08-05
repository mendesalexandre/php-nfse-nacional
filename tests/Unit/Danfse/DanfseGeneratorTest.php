<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Danfse\DanfseCustomizacao;
use PhpNfseNacional\Danfse\DanfseGenerator;
use PhpNfseNacional\Danfse\DanfseXmlParser;
use PHPUnit\Framework\Attributes\DataProvider;
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

    private function xmlAutorizado(): string
    {
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        return $xml;
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
        self::assertStringContainsString('Nº NFS-e / CHAVE NFS-e', $texto);
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
        // Bug real (02/07/2026): SITUAÇÃO DA NFS-e (fonte: cStat) copiava o
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

    /**
     * @return array<string, array{string, string}>
     */
    public static function casosSituacaoNfse(): array
    {
        return [
            'cStat 100 = gerada'                     => ['100', 'NFS-e Gerada'],
            'cStat 101 = substituição gerada'         => ['101', 'NFS-e de Substituição Gerada'],
            'cStat 102 = decisão judicial'            => ['102', 'NFS-e de Decisão Judicial'],
            'cStat 103 = avulsa'                      => ['103', 'NFS-e Avulsa'],
        ];
    }

    #[DataProvider('casosSituacaoNfse')]
    public function test_situacao_da_nfse_usa_dominio_do_infNFSe_cStat(string $cStat, string $esperado): void
    {
        // `infNFSe/cStat` (Anexo IV, campo 17) é um domínio PRÓPRIO e
        // restrito a {100,101,102,103} — DIFERENTE do cStat de resposta de
        // emissão/evento (enum `CStat`, onde 101/102 = cancelada/cancelada
        // por substituição). Cancelamento é evento, não muda esse campo.
        // Bug real: versões anteriores mapeavam 101→"NFS-e Cancelada" e
        // 102→"NFS-e Cancelada por Substituição", herdado por engano do
        // domínio errado — confirmado contra o CSV oficial (Anexo IV).
        $xml = str_replace('<cStat>100</cStat>', "<cStat>{$cStat}</cStat>", $this->xmlAutorizado());
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);
        self::assertStringContainsString($esperado, $texto);
        self::assertStringNotContainsString('NFS-e Cancelada', $texto);
    }

    public function test_ambiente_gerador_e_tipo_de_ambiente_reproduzem_valor_cru_do_xml(): void
    {
        // "Ambiente Gerador" (ambGer) e "Tipo de Ambiente" (tpAmb) são campos
        // do XML da NFS-e (tabela 2.4.5, NT 008, "Tam. do Campo: 1") — o
        // oficial imprime o dígito cru, não um rótulo traduzido. Hardcoded
        // "Sistema Próprio"/"Sistema Nacional" eram os dois valores errados
        // de versões anteriores (02/07/2026 e depois). Fixture: ambGer=2,
        // tpAmb=2 — igual ao que o portal nacional mostra pra NFS-e emitidas
        // via API SEFIN Nacional.
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);
        self::assertStringContainsString('Ambiente Gerador: 2', $texto);
        self::assertStringContainsString('Tipo de Ambiente: 2', $texto);
        self::assertStringNotContainsString('Sistema Próprio', $texto);
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

    public function test_exclusoes_ibscbs_mostra_issqn_mesmo_sem_gibscbs_no_dps(): void
    {
        // Reverte o fix de 03/07/2026 (v0.26.11) — aquele diagnóstico
        // estava errado. "Exclusões e Reduções da Base de Cálculo" =
        // ISSQN apurado + PIS/COFINS débito + desconto incondicionado é o
        // valor CORRETO mesmo quando a operação não declara <gIBSCBS> no
        // DPS: o ISSQN já recolhido é uma exclusão legítima da base do
        // IBS/CBS durante a transição, e o SEFIN/portal nacional mostra
        // esse valor de qualquer forma. Confirmado comparando com 2 DANFSe
        // reais do portal nacional 05/08/2026 (nota sem gIBSCBS, "Exclusões"
        // = ISSQN Apurado, não "-"). Fixture: vISSQN=0,92, sem PIS/COFINS/
        // desconto — soma = 0,92.
        $xml = file_get_contents(__DIR__ . '/../../fixtures/nfse-autorizada.xml');
        self::assertNotFalse($xml);
        $xmlSemIbscbs = preg_replace('#<IBSCBS>.*?</IBSCBS>#s', '', $xml);
        self::assertNotNull($xmlSemIbscbs);
        self::assertStringNotContainsString('IBSCBS', $xmlSemIbscbs);

        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xmlSemIbscbs);
        $texto = $this->textoDoPdf($pdf);

        self::assertStringContainsString('TRIBUTAÇÃO IBS/CBS', $texto);
        self::assertStringContainsString('CST / cClassTrib', $texto);
        self::assertStringContainsString('Exclusões e Reduções da Base de Cálculo', $texto);
        self::assertSame('R$ 0,92', $this->valorNaColuna($texto, 'Exclusões e Reduções da Base de Cálculo'));
    }

    /** Linha imediatamente após a primeira ocorrência do label no texto do PDF. */
    private function linhaApos(string $texto, string $label): string
    {
        $linhas = explode("\n", $texto);
        foreach ($linhas as $idx => $linha) {
            if (str_contains($linha, $label)) {
                return $linhas[$idx + 1] ?? '';
            }
        }
        self::fail("label \"{$label}\" não encontrado no PDF");
    }

    /**
     * Valor da MESMA coluna do label (não só "início da linha seguinte" —
     * `linhaApos()` dá falso positivo quando o valor de uma coluna à
     * esquerda também é "-", mascarando o valor real da coluna que a
     * gente queria checar).
     */
    private function valorNaColuna(string $texto, string $label): string
    {
        $linhas = explode("\n", $texto);
        foreach ($linhas as $idx => $linha) {
            $colunas = preg_split('/\s{2,}/', trim($linha)) ?: [];
            $pos = array_search($label, $colunas, true);
            if ($pos !== false) {
                $valores = preg_split('/\s{2,}/', trim($linhas[$idx + 1] ?? '')) ?: [];
                return $valores[$pos] ?? '';
            }
        }
        self::fail("label \"{$label}\" não encontrado no PDF (busca por coluna)");
    }

    // ================================================================
    // Regra transitória: destaque de IBS/CBS só a partir de 2027
    // ================================================================

    public function test_ibscbs_suprimido_antes_de_2027(): void
    {
        // Fixture tem dCompet=2026-01-15 e <IBSCBS> completo (vIBSTot=1.04,
        // vCBS=1.86) — o SDK já pode enviar isso no DPS, mas o DETALHE
        // (bloco 10: CST/cClassTrib/alíquotas) não é destacado antes da
        // rampa 2027 da Reforma Tributária. Os TOTAIS (bloco 11) sempre
        // mostram o valor real (0,00 quando não há IBS/CBS; aqui há, então
        // mostra 2,90/35,87) — confirmado contra DANFSe real do portal
        // nacional, que nunca esconde esses dois campos com "-".
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($this->xmlAutorizado());
        $texto = $this->textoDoPdf($pdf);

        self::assertStringContainsString('TRIBUTAÇÃO IBS/CBS', $texto);
        self::assertMatchesRegularExpression('/^-\s*\/\s*-/', $this->linhaApos($texto, 'CST / cClassTrib'));
        self::assertSame('R$ 2,90', $this->valorNaColuna($texto, 'Total do IBS/CBS'));
        self::assertSame('R$ 35,87', $this->valorNaColuna($texto, 'VALOR LÍQUIDO DA NFS-e + IBS/CBS'));
    }

    public function test_ibscbs_exibido_a_partir_de_2027(): void
    {
        $xml = str_replace('<dCompet>2026-01-15</dCompet>', '<dCompet>2027-01-15</dCompet>', $this->xmlAutorizado());
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml);
        $texto = $this->textoDoPdf($pdf);

        self::assertStringContainsString('TRIBUTAÇÃO IBS/CBS', $texto);
        self::assertMatchesRegularExpression('/^000\s*\/\s*000001/', $this->linhaApos($texto, 'CST / cClassTrib'));
        // Total do IBS/CBS = vIBSTot (1.04) + vCBS (1.86) = 2.90
        self::assertStringContainsString('2,90', $this->linhaApos($texto, 'Total do IBS/CBS'));
        // VALOR LÍQUIDO + IBS/CBS = vLiq (32.97) + 2.90 = 35.87
        self::assertStringContainsString('35,87', $this->linhaApos($texto, 'VALOR LÍQUIDO DA NFS-e + IBS/CBS'));
    }

    public function test_override_exibirValoresIbsCbs_forca_exibicao_antes_de_2027(): void
    {
        $custom = new DanfseCustomizacao(exibirValoresIbsCbs: true);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($this->xmlAutorizado(), $custom);
        $texto = $this->textoDoPdf($pdf);

        self::assertStringContainsString('TRIBUTAÇÃO IBS/CBS', $texto);
        self::assertMatchesRegularExpression('/^000\s*\/\s*000001/', $this->linhaApos($texto, 'CST / cClassTrib'));
        self::assertStringContainsString('2,90', $this->linhaApos($texto, 'Total do IBS/CBS'));
    }

    public function test_override_exibirValoresIbsCbs_false_suprime_detalhe_mesmo_apos_2027(): void
    {
        // O override só controla o DETALHE (bloco 10) — os totais (bloco
        // 11) continuam mostrando o valor real independente da flag.
        $xml = str_replace('<dCompet>2026-01-15</dCompet>', '<dCompet>2027-01-15</dCompet>', $this->xmlAutorizado());
        $custom = new DanfseCustomizacao(exibirValoresIbsCbs: false);
        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xml, $custom);
        $texto = $this->textoDoPdf($pdf);

        self::assertMatchesRegularExpression('/^-\s*\/\s*-/', $this->linhaApos($texto, 'CST / cClassTrib'));
        self::assertStringContainsString('2,90', $this->linhaApos($texto, 'Total do IBS/CBS'));
    }

    public function test_ibscbs_total_mostra_zero_quando_nao_ha_gibscbs_real(): void
    {
        // Confirmado contra DANFSe real do portal nacional 05/08/2026:
        // nota sem <IBSCBS> no DPS mostra "R$ 0,00" nos dois campos, não
        // "-". IMPORTANTE: "VALOR LÍQUIDO + IBS/CBS" fica "R$ 0,00" —
        // NÃO cai pra "VALOR LÍQUIDO DA NFS-e" sozinho (parecia intuitivo
        // vLiq+0=vLiq, mas não é isso que o SEFIN faz).
        $xmlSemIbscbs = preg_replace('#<IBSCBS>.*?</IBSCBS>#s', '', $this->xmlAutorizado());
        self::assertNotNull($xmlSemIbscbs);

        $pdf = (new \PhpNfseNacional\Services\DanfseService())->gerarDoXml($xmlSemIbscbs);
        $texto = $this->textoDoPdf($pdf);

        self::assertSame('R$ 0,00', $this->valorNaColuna($texto, 'Total do IBS/CBS'));
        self::assertSame('R$ 0,00', $this->valorNaColuna($texto, 'VALOR LÍQUIDO DA NFS-e + IBS/CBS'));
        // vLiq "normal" continua correto, só o "+IBS/CBS" que fica 0,00
        self::assertSame('R$ 32,97', $this->valorNaColuna($texto, 'VALOR LÍQUIDO DA NFS-e'));
    }
}
