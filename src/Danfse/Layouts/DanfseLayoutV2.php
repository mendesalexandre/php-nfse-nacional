<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse\Layouts;

use PhpNfseNacional\Danfse\DanfseCustomizacao;
use PhpNfseNacional\Danfse\DanfseDados;
use PhpNfseNacional\Danfse\DanfseLayout;
use PhpNfseNacional\Enums\DanfseVersao;
use PhpNfseNacional\Support\IbgeMunicipios;
use TCPDF;

/**
 * Strategy de renderização da DANFSe conforme **NT 008/2026 (SE/CGNFS-e v1.0)**.
 *
 * Layout em A4 retrato com a ordem do Anexo I da NT:
 *   Cabeçalho → DADOS DA NFS-e → PRESTADOR → TOMADOR → DESTINATÁRIO →
 *   INTERMEDIÁRIO → SERVIÇO PRESTADO → TRIBUTAÇÃO MUNICIPAL → TRIBUTAÇÃO
 *   FEDERAL → TRIBUTAÇÃO IBS/CBS → VALOR TOTAL → INFORMAÇÕES COMPLEMENTARES.
 *
 * Coordenadas todas em centímetros (conforme tabela 2.4.5), convertidas
 * pra mm na hora de chamar o TCPDF.
 *
 * O canhoto (item 2.1.13) é opcional e não é renderizado por default.
 *
 * **Em refino**: ainda há bugs visuais conhecidos no V2 (label "Indicador
 * Municipal" deveria ser "Inscrição Municipal", "VALOR LÍQUIDO" duplicado
 * quando IBS/CBS zerado, IBS/CBS block sempre visível, totais aproximados
 * embutidos em info complementares). Ver task v0.19.1+ cleanup.
 */
final class DanfseLayoutV2 implements DanfseLayoutStrategy
{
    private TCPDF $pdf;
    private float $cursorY = 0.0;
    private ?DanfseCustomizacao $custom = null;
    private bool $destacarRetencoes = false;

    /** Altura do título do bloco (item 2.4.1 — 7pt bold MAIÚSCULAS) */
    private const ALTURA_TITULO_BLOCO_CM = 0.40;

    /** Espessura mínima de linha em mm pra 0,5pt (item 2.2.3) */
    private const ESPESSURA_LINHA_MM = 0.176;

    /** Espessura da borda externa da página em mm pra 1pt */
    private const ESPESSURA_BORDA_MM = 0.353;

    /** Distância (cm) da moldura externa à borda física da folha. */
    private const MARGEM_FOLHA_CM = 0.17;

    public function versao(): DanfseVersao
    {
        return DanfseVersao::V2;
    }

    public function renderizar(DanfseDados $dados, TCPDF $pdf, ?DanfseCustomizacao $custom = null): void
    {
        $this->pdf = $pdf;
        $this->custom = $custom;
        $this->cursorY = 0.0;
        $this->destacarRetencoes = $custom !== null && $custom->destacarRetencoes;

        // Borda externa da página (1pt, item 2.2.3)
        $this->pdf->SetLineWidth(self::ESPESSURA_BORDA_MM);
        $this->pdf->SetDrawColor(...DanfseLayout::COR_BORDA);

        // Moldura externa da folha (estilo V1 — linha ao redor de toda a página)
        $this->renderBordaFolha();

        $this->renderCabecalho($dados);
        // Bloco DADOS DA NFS-e: posição fixa pra alinhar com QR Code do cabeçalho
        $this->cursorY = DanfseLayout::Y_DADOS_NFSE_CM;
        $this->renderDadosNfse($dados);
        $this->renderPrestador($dados);
        $this->renderTomador($dados);
        $this->renderDestinatario($dados);
        $this->renderIntermediario($dados);
        $this->renderServico($dados);
        $this->renderTributacaoMunicipal($dados);
        $this->renderTributacaoFederal($dados);
        $this->renderTributacaoIbsCbs($dados);
        $this->renderValorTotal($dados);
        $this->renderInformacoesComplementares($dados);
    }

    // ================================================================
    // BLOCO 1 — CABEÇALHO
    // ================================================================

    private function renderCabecalho(DanfseDados $dados): void
    {
        $y = DanfseLayout::cmToMm(DanfseLayout::Y_CABECALHO_CM);
        $larguraTotal = DanfseLayout::cmToMm(DanfseLayout::CONTENT_WIDTH_CM);
        $marginX = DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM);
        $altCabec = DanfseLayout::cmToMm(1.16);

        $this->pdf->SetFillColor(...DanfseLayout::COR_SOMBREAMENTO);
        $this->pdf->Rect($marginX, $y, $larguraTotal, $altCabec, 'DF');

        $logoPath = __DIR__ . '/../../../resources/assets/logo-nfse-horizontal.png';
        if (file_exists($logoPath)) {
            $this->pdf->Image(
                $logoPath,
                $marginX + 1,
                $y + 1,
                DanfseLayout::cmToMm(4.0),
                DanfseLayout::cmToMm(0.85),
                'PNG',
            );
        }

        $xCentro = $marginX + DanfseLayout::cmToMm(5.41);
        $larguraCentro = DanfseLayout::cmToMm(10.19);
        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', DanfseLayout::TAM_CABECALHO_TITULO);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        $this->pdf->SetXY($xCentro, $y + 1);
        $this->pdf->Cell($larguraCentro, 4, DanfseLayout::TEXTO_DANFSE_TITULO, 0, 0, 'C');
        $this->pdf->SetXY($xCentro, $y + 4);
        $this->pdf->Cell($larguraCentro, 3.5, DanfseLayout::TEXTO_DANFSE_SUBTITULO, 0, 0, 'C');

        if ($dados->homologacao) {
            $this->pdf->SetTextColor(...DanfseLayout::COR_VERMELHO_HOMOL);
            $this->pdf->SetXY($xCentro, $y + 7.5);
            $this->pdf->Cell($larguraCentro, 3.5, DanfseLayout::TEXTO_HOMOLOGACAO, 0, 0, 'C');
            $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        }

        $xDir = $marginX + DanfseLayout::cmToMm(15.62);
        $larguraDir = DanfseLayout::cmToMm(
            DanfseLayout::QR_X_CM - 15.62 - 0.1,
        );

        $municipio = ($dados->prestador['municipio'] ?? '-')
            . ' - ' . ($dados->prestador['uf'] ?? '-');
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', DanfseLayout::TAM_CABECALHO_MUNICIPIO);
        $this->pdf->SetXY($xDir, $y + 0.5);
        $this->pdf->Cell($larguraDir, 3, 'Município: ' . $municipio, 0, 0, 'L');

        $ambiente = 'Sistema Nacional';
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', DanfseLayout::TAM_CABECALHO_AMBIENTE);
        $this->pdf->SetXY($xDir, $y + 4);
        $this->pdf->Cell($larguraDir, 2.5, 'Ambiente: ' . $ambiente, 0, 0, 'L');

        $tipoAmb = $dados->homologacao ? 'Homologação' : 'Produção';
        $this->pdf->SetXY($xDir, $y + 6.5);
        $this->pdf->Cell($larguraDir, 2.5, 'Tipo: ' . $tipoAmb, 0, 0, 'L');

        if ($dados->qrCodeUrl !== null) {
            $this->pdf->write2DBarcode(
                $dados->qrCodeUrl,
                'QRCODE,M',
                DanfseLayout::cmToMm(DanfseLayout::QR_X_CM),
                DanfseLayout::cmToMm(DanfseLayout::QR_Y_CM),
                DanfseLayout::cmToMm(DanfseLayout::QR_TAMANHO_CM),
                DanfseLayout::cmToMm(DanfseLayout::QR_TAMANHO_CM),
                ['border' => false, 'padding' => 0],
            );

            $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', DanfseLayout::TAM_DESCRICAO_QR);
            $this->pdf->SetXY(
                DanfseLayout::cmToMm(15.62),
                DanfseLayout::cmToMm(DanfseLayout::QR_Y_CM + DanfseLayout::QR_TAMANHO_CM + 0.05),
            );
            $this->pdf->MultiCell(
                DanfseLayout::cmToMm(4.78),
                2.0,
                DanfseLayout::TEXTO_AUTENTICIDADE_QR,
                0,
                'C',
                false,
            );
        }
    }

    // ================================================================
    // BLOCO 2 — DADOS DA NFS-e
    // ================================================================

    private function renderDadosNfse(DanfseDados $dados): void
    {
        $alturaLinha = 0.63;

        $this->renderCelula(0.30, $this->cursorY, 15.30, $alturaLinha,
            'CHAVE DE ACESSO DA NFS-E',
            DanfseLayout::formatarChave($dados->chave()),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true);
        $this->cursorY += $alturaLinha;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $alturaLinha, 'NÚMERO DA NFS-E',
            $dados->numero() ?? '-',
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true);
        $this->renderCelula(5.41, $this->cursorY, 5.09, $alturaLinha, 'COMPETÊNCIA DA NFS-E',
            DanfseLayout::formatarData($dados->identificacao['data_competencia'] ?? null),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true);
        $this->renderCelula(10.51, $this->cursorY, 5.09, $alturaLinha, 'DATA E HORA DA EMISSÃO DA NFS-E',
            DanfseLayout::formatarDataHora($dados->identificacao['data_emissao_nfse'] ?? null),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true);
        $this->cursorY += $alturaLinha;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $alturaLinha, 'NÚMERO DA DPS',
            $dados->identificacao['numero_dps'] ?? '-',
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true);
        $this->renderCelula(5.41, $this->cursorY, 5.09, $alturaLinha, 'SÉRIE DA DPS',
            $dados->identificacao['serie'] ?? '-',
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true);
        $this->renderCelula(10.51, $this->cursorY, 5.09, $alturaLinha, 'DATA E HORA DA EMISSÃO DA DPS',
            DanfseLayout::formatarDataHora($dados->identificacao['data_emissao_dps'] ?? null),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true);
        $this->cursorY += $alturaLinha;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $alturaLinha, 'EMITENTE DA NFS-e',
            $this->labelTipoEmitente($dados->identificacao['tipo_emitente'] ?? null),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true,
            sombreado: true);
        $this->renderCelula(5.41, $this->cursorY, 5.09, $alturaLinha, 'SITUAÇÃO DA NFS-e',
            $this->labelSituacao((int) ($dados->identificacao['cStat'] ?? 0)),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true,
            sombreado: true);
        $this->renderCelula(10.51, $this->cursorY, 5.09, $alturaLinha, 'FINALIDADE',
            $this->labelFinalidade($dados->identificacao['finalidade'] ?? null),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true,
            sombreado: true);
        $this->cursorY += $alturaLinha;
    }

    // ================================================================
    // BLOCO 3 — PRESTADOR / FORNECEDOR
    // ================================================================

    private function renderPrestador(DanfseDados $dados): void
    {
        $p = $dados->prestador;
        $this->iniciarBloco('PRESTADOR / FORNECEDOR');
        $h = 0.63;

        if ($this->custom !== null && $this->custom->temLogoPrestador()) {
            $logoLarguraCm = 4.0;
            $logoAlturaCm = 1.26;
            $xLogo = DanfseLayout::cmToMm(20.70 - $logoLarguraCm - 0.30);
            $yLogo = DanfseLayout::cmToMm($this->cursorY + 0.05);
            try {
                $this->pdf->Image(
                    (string) $this->custom->logoPrestadorPath,
                    $xLogo,
                    $yLogo,
                    DanfseLayout::cmToMm($logoLarguraCm),
                    DanfseLayout::cmToMm($logoAlturaCm),
                    '',
                    '',
                    'T',
                    true,
                    300,
                    '',
                    false,
                    false,
                    0,
                    'CM',
                );
            } catch (\Throwable) {
                // Logo opcional — ignora falhas (formato exótico, corrompido)
            }
        }

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'CNPJ / CPF / NIF',
            DanfseLayout::formatarDocumento($p['documento']));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Indicador Municipal',
            $p['inscricao_municipal'] ?? '-');
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Telefone',
            DanfseLayout::formatarTelefone($p['telefone']));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 10.19, $h, 'Nome / Nome Empresarial',
            $p['nome'] ?? '-');
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Município / Sigla UF',
            $this->municipioUf($p));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Código IBGE / CEP',
            ($p['codigo_municipio'] ?? '-') . ' / ' . DanfseLayout::formatarCep($p['cep']));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 10.19, $h, 'Endereço',
            $this->montarEndereco($p));
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'E-mail',
            $p['email'] ?? '-');
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Simples Nacional na Data de Competência',
            $this->labelSimplesNacional($p['opta_simples'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'Regime de Apuração Tributária pelo SN',
            $p['regime_apuracao_sn'] ?? '-');
        $this->cursorY += $h;
    }

    // ================================================================
    // BLOCO 4 — TOMADOR / ADQUIRENTE
    // ================================================================

    private function renderTomador(DanfseDados $dados): void
    {
        $t = $dados->tomador;

        if (($t['documento'] ?? null) === null) {
            $this->renderLinhaSupressao('TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
            return;
        }

        $this->iniciarBloco('TOMADOR / ADQUIRENTE');

        $h = 0.63;
        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'CNPJ / CPF / NIF',
            DanfseLayout::formatarDocumento($t['documento']));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Indicador Municipal',
            $t['inscricao_municipal'] ?? '-');
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Telefone',
            DanfseLayout::formatarTelefone($t['telefone']));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 10.19, $h, 'Nome / Nome Empresarial',
            $t['nome'] ?? '-');
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Município / Sigla UF',
            $this->municipioUf($t));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Código IBGE / CEP',
            ($t['codigo_municipio'] ?? '-') . ' / ' . DanfseLayout::formatarCep($t['cep']));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 10.19, $h, 'Endereço', $this->montarEndereco($t));
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'E-mail', $t['email'] ?? '-');
        $this->cursorY += $h;
    }

    // ================================================================
    // BLOCO 5 — DESTINATÁRIO DA OPERAÇÃO
    // ================================================================

    private function renderDestinatario(DanfseDados $dados): void
    {
        if ($dados->destinatarioIgualTomador()) {
            $this->renderLinhaSupressao('O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO');
            return;
        }

        $d = $dados->destinatario;
        if (($d['documento'] ?? null) === null) {
            $this->renderLinhaSupressao('DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
            return;
        }

        $this->iniciarBloco('DESTINATÁRIO DA OPERAÇÃO');

        $h = 0.63;
        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'CNPJ / CPF / NIF',
            DanfseLayout::formatarDocumento($d['documento']));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Telefone',
            DanfseLayout::formatarTelefone($d['telefone']));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 10.19, $h, 'Nome / Nome Empresarial', $d['nome'] ?? '-');
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Município / Sigla UF',
            $this->municipioUf($d));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Código IBGE / CEP',
            ($d['codigo_municipio'] ?? '-') . ' / ' . DanfseLayout::formatarCep($d['cep'] ?? null));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 10.19, $h, 'Endereço', $this->montarEndereco($d));
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'E-mail', $d['email'] ?? '-');
        $this->cursorY += $h;
    }

    // ================================================================
    // BLOCO 6 — INTERMEDIÁRIO DA OPERAÇÃO
    // ================================================================

    private function renderIntermediario(DanfseDados $dados): void
    {
        if ($dados->semIntermediario()) {
            $this->renderLinhaSupressao('INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e');
            return;
        }

        $this->iniciarBloco('INTERMEDIÁRIO DA OPERAÇÃO');

        $i = $dados->intermediario;
        $h = 0.63;
        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'CNPJ / CPF / NIF',
            DanfseLayout::formatarDocumento($i['documento'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Indicador Municipal',
            $i['inscricao_municipal'] ?? '-');
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Telefone',
            DanfseLayout::formatarTelefone($i['telefone'] ?? null));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 10.19, $h, 'Nome / Nome Empresarial', $i['nome'] ?? '-');
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Município / Sigla UF',
            $this->municipioUf($i));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Código IBGE / CEP',
            ($i['codigo_municipio'] ?? '-') . ' / ' . DanfseLayout::formatarCep($i['cep'] ?? null));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 10.19, $h, 'Endereço', $this->montarEndereco($i));
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'E-mail', $i['email'] ?? '-');
        $this->cursorY += $h;
    }

    // ================================================================
    // BLOCO 7 — SERVIÇO PRESTADO
    // ================================================================

    private function renderServico(DanfseDados $dados): void
    {
        $s = $dados->servico;
        $this->iniciarBloco('SERVIÇO PRESTADO');
        $h = 0.63;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Código de Tributação Nacional / Municipal',
            ($s['codigo_tributacao_nacional'] ?? '-') . ' / ' . ($s['codigo_tributacao_municipal'] ?? '-'));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Código da NBS', $s['codigo_nbs'] ?? '-');
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'Local da Prestação / Sigla UF / País',
            ($s['municipio_prestacao'] ?? '-') . ' / BR');
        $this->cursorY += $h;

        $this->renderCelulaTextoLongo(0.30, $this->cursorY, 20.40, 0.45,
            $s['descricao_tributacao_nacional'] ?? '-');
        $this->cursorY += 0.45;

        $this->renderCelulaTextoLongo(0.30, $this->cursorY, 20.40, 1.10,
            $s['descricao_servico'] ?? '-',
            label: 'Descrição do Serviço');
        $this->cursorY += 1.10;
    }

    // ================================================================
    // BLOCO 8 — TRIBUTAÇÃO MUNICIPAL (ISSQN)
    // ================================================================

    private function renderTributacaoMunicipal(DanfseDados $dados): void
    {
        $t = $dados->tributacaoMunicipal;
        $this->iniciarBloco('TRIBUTAÇÃO MUNICIPAL (ISSQN)');

        if ($dados->operacaoNaoSujeitaIssqn()) {
            $this->renderCaixaTextoUnico($this->cursorY, 0.63,
                'TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN');
            $this->cursorY += 0.63;
            return;
        }

        $h = 0.63;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Tipo de Tributação do ISSQN',
            $this->labelTipoTributacaoIssqn($this->ns($t['tipo_tributacao_codigo'])));
        $municipioIncidencia = $dados->identificacao['local_incidencia']
            ?? IbgeMunicipios::buscar($dados->identificacao['cod_municipio_incidencia'] ?? null)['nome']
            ?? null;
        $ufIncidencia = IbgeMunicipios::buscar($dados->identificacao['cod_municipio_incidencia'] ?? null)['uf']
            ?? ($dados->prestador['uf'] ?? null);
        $this->renderCelula(5.41, $this->cursorY, 15.29, $h, 'Município / Sigla UF / País de Incidência do ISSQN',
            ($municipioIncidencia ?? '-') . ' / ' . ($ufIncidencia ?? '-') . ' / BR');
        $this->cursorY += $h;

        $regimeCodigo = (int) ($dados->prestador['regime_especial'] ?? 0);
        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Regime Especial de Tributação do ISSQN',
            DanfseLayout::regimesEspeciais()[$regimeCodigo] ?? '-');
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Tipo de Imunidade do ISSQN',
            $this->s($t['tipo_imunidade'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Suspensão da Exigibilidade do ISSQN',
            $this->s($t['tipo_suspensao'] ?? null));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Número Processo Suspensão',
            $this->s($t['numero_processo_suspensao'] ?? null));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Benefício Municipal',
            $this->s($t['beneficio_municipal'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Cálculo do BM',
            DanfseLayout::formatarMoeda($t['calculo_bm'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Total Deduções/Reduções',
            DanfseLayout::formatarMoeda($t['total_deducoes_reducoes'] ?? null));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Desconto Incondicionado',
            DanfseLayout::formatarMoeda($t['desconto_incondicionado'] ?? null));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'BC ISSQN',
            DanfseLayout::formatarMoeda($t['base_calculo_issqn'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Alíquota Aplicada',
            DanfseLayout::formatarPercentual($t['aliquota_aplicada'] ?? null));

        // Regra de negócio do ISS: só é elegível para destaque se for efetivamente
        // retido na fonte — Retido pelo Tomador ('2') ou pelo Intermediário ('3').
        // Em apuração própria pelo prestador, o destaque é ignorado. Se retido,
        // delega ao helper, que valida valor > 0 e se a flag global está ligada.
        $tipoRetencaoIss = $this->ns($t['tipo_retencao_issqn'] ?? null);
        $issRetido = in_array($tipoRetencaoIss, ['2', '3'], true);
        $corFundoIss = $issRetido
            ? $this->corDestaqueSePossuirValor($t['issqn_apurado'] ?? null)
            : null;

        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Retenção do ISSQN',
            $this->labelRetencaoIssqn($tipoRetencaoIss),
            corFundo: $corFundoIss);
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'ISSQN Apurado',
            DanfseLayout::formatarMoeda($t['issqn_apurado'] ?? null),
            corFundo: $corFundoIss);
        $this->cursorY += $h;
    }

    // ================================================================
    // BLOCO 9 — TRIBUTAÇÃO FEDERAL (EXCETO CBS)
    // ================================================================

    private function renderTributacaoFederal(DanfseDados $dados): void
    {
        $f = $dados->tributacaoFederal;
        $this->iniciarBloco('TRIBUTAÇÃO FEDERAL (EXCETO CBS)');
        $h = 0.63;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'IRRF',
            DanfseLayout::formatarMoeda($f['irrf'] ?? null),
            corFundo: $this->corDestaqueSePossuirValor($f['irrf'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Contribuição Previdenciária - Retida',
            DanfseLayout::formatarMoeda($f['contribuicao_previdenciaria'] ?? null),
            corFundo: $this->corDestaqueSePossuirValor($f['contribuicao_previdenciaria'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'Contribuições Sociais - Retidas',
            DanfseLayout::formatarMoeda($f['contribuicoes_sociais_retidas'] ?? null),
            corFundo: $this->corDestaqueSePossuirValor($f['contribuicoes_sociais_retidas'] ?? null));
        $this->cursorY += $h;

        // "Descrição Contrib. Sociais - Retidas" expõe o tpRetPisCofins
        // (1=Retido, 2=NãoRetido). Só destaca quando há retenção efetiva
        // (código '1') e a flag de conferência está ligada. PIS/COFINS de
        // apuração própria (débito) não são retenções — não recebem destaque.
        $tpPC = (string) ($f['descricao_contrib_sociais'] ?? '');
        $pisCofRetido = $tpPC !== '' && $tpPC !== '0' && $tpPC !== '2';
        $corFundoPisCofins = ($this->destacarRetencoes && $pisCofRetido)
            ? DanfseLayout::COR_AMARELO_DESTAQUE
            : null;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'PIS - Débito Apuração Própria',
            DanfseLayout::formatarMoeda($f['pis_debito'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'COFINS - Débito Apuração Própria',
            DanfseLayout::formatarMoeda($f['cofins_debito'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'Descrição Contrib. Sociais - Retidas',
            $this->s($f['descricao_contrib_sociais'] ?? null),
            corFundo: $corFundoPisCofins);
        $this->cursorY += $h;
    }

    // ================================================================
    // BLOCO 10 — TRIBUTAÇÃO IBS / CBS (Reforma Tributária)
    // ================================================================

    private function renderTributacaoIbsCbs(DanfseDados $dados): void
    {
        $i = $dados->tributacaoIbsCbs;
        $this->iniciarBloco('TRIBUTAÇÃO IBS / CBS');
        $h = 0.63;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'CST / cClassTrib',
            ($i['cst'] ?? '-') . ' / ' . ($i['cclass_trib'] ?? '-'));
        $this->renderCelula(5.41, $this->cursorY, 15.29, $h,
            'Indicador de Operação / Código IBGE Incidência / Município Incidência / Sigla UF',
            ($i['cod_indicador_operacao'] ?? '-') . ' / '
            . ($i['cod_localidade_incidencia'] ?? '-') . ' / '
            . ($i['localidade_incidencia'] ?? '-') . ' / '
            . ($dados->prestador['uf'] ?? '-'));
        $this->cursorY += $h;

        $exclusoes = $this->somarExclusoes($dados);
        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Exclusões e Reduções da Base de Cálculo',
            DanfseLayout::formatarMoeda($exclusoes));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Base de Cálculo Após Exclusões e Reduções',
            DanfseLayout::formatarMoeda($i['vbc_apos_exclusoes'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Red. Alíquota IBS / Red. Alíquota CBS',
            DanfseLayout::formatarPercentual($this->reducaoAliquotaIbs($i)) . ' / '
            . DanfseLayout::formatarPercentual($i['p_red_aliq_cbs'] ?? null));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Alíquota - IBS UF / IBS Mun',
            DanfseLayout::formatarPercentual($i['p_ibs_uf'] ?? null) . ' / '
            . DanfseLayout::formatarPercentual($i['p_ibs_mun'] ?? null));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Alíq. Efetiva Municipal - IBS',
            DanfseLayout::formatarPercentual($i['p_aliq_efet_mun'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Valor Apurado Municipal - IBS',
            DanfseLayout::formatarMoeda($i['v_ibs_mun'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Alíq. Efetiva Estadual - IBS',
            DanfseLayout::formatarPercentual($i['p_aliq_efet_uf'] ?? null));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Valor Apurado Estadual - IBS',
            DanfseLayout::formatarMoeda($i['v_ibs_uf'] ?? null));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Valor Total Apurado - IBS',
            DanfseLayout::formatarMoeda($i['v_ibs_total'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Alíquota - CBS',
            DanfseLayout::formatarPercentual($i['p_cbs'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Alíq. Efetiva - CBS',
            DanfseLayout::formatarPercentual($i['p_aliq_efet_cbs'] ?? null));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'Valor Total Apurado - CBS',
            DanfseLayout::formatarMoeda($i['v_cbs'] ?? null));
        $this->cursorY += $h;
    }

    /**
     * @param array<string, scalar|null> $i
     */
    private function reducaoAliquotaIbs(array $i): ?float
    {
        $mun = $i['p_red_aliq_mun'] ?? null;
        if ($mun !== null && $mun !== 0.0) {
            return (float) $mun;
        }
        $uf = $i['p_red_aliq_uf'] ?? null;
        return $uf !== null ? (float) $uf : (is_numeric($mun) ? (float) $mun : null);
    }

    // ================================================================
    // BLOCO 11 — VALOR TOTAL DA NFS-E
    // ================================================================

    private function renderValorTotal(DanfseDados $dados): void
    {
        $v = $dados->valorTotal;
        $this->iniciarBloco('VALOR TOTAL DA NFS-E');
        $h = 0.67;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Valor da Operação / Serviço',
            DanfseLayout::formatarMoeda($v['valor_servicos'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'Desconto Incondicionado',
            DanfseLayout::formatarMoeda($v['desconto_incondicionado'] ?? null));
        $this->renderCelula(10.51, $this->cursorY, 10.19, $h, 'Desconto Condicionado',
            DanfseLayout::formatarMoeda($v['desconto_condicionado'] ?? null));
        $this->cursorY += $h;

        $this->renderCelula(0.30, $this->cursorY, 5.09, $h, 'Total das Retenções (ISSQN / Federais)',
            DanfseLayout::formatarMoeda($v['total_retencoes'] ?? null), 
            corFundo: $this->corDestaqueSePossuirValor($v['total_retencoes'] ?? null));
        $this->renderCelula(5.41, $this->cursorY, 5.09, $h, 'VALOR LÍQUIDO DA NFS-e',
            DanfseLayout::formatarMoeda($v['valor_liquido'] ?? null),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true,
            sombreado: true);
        $this->renderCelula(10.51, $this->cursorY, 5.09, $h, 'Total do IBS/CBS',
            DanfseLayout::formatarMoeda($v['total_ibscbs'] ?? null));
        $this->renderCelula(15.62, $this->cursorY, 5.09, $h, 'VALOR LÍQUIDO DA NFS-e + IBS/CBS',
            DanfseLayout::formatarMoeda($v['valor_liquido_mais_ibscbs'] ?? null),
            tamanhoLabel: DanfseLayout::TAM_LABEL_IDENTIFICACAO, labelCaixaAlta: true,
            sombreado: true);
        $this->cursorY += $h;
    }

    // ================================================================
    // BLOCO 12 — INFORMAÇÕES COMPLEMENTARES
    // ================================================================

    private function renderInformacoesComplementares(DanfseDados $dados): void
    {
        $i = $dados->informacoesComplementares;
        $this->iniciarBloco('INFORMAÇÕES COMPLEMENTARES');

        $linhas = [];
        if (!empty($i['chave_substituida'])) {
            $linhas[] = 'NFS-e Subst.: ' . $i['chave_substituida'];
        }
        if (!empty($i['codigo_obra']) || !empty($i['inscricao_imobiliaria'])) {
            $linhas[] = 'Cod. Obra: ' . ($i['codigo_obra'] ?? '-')
                . ' | Insc. Imob.: ' . ($i['inscricao_imobiliaria'] ?? '-');
        }
        if (!empty($i['id_atividade_evento'])) {
            $linhas[] = 'Cod. Evt.: ' . $i['id_atividade_evento'];
        }
        if (!empty($i['numero_pedido']) || !empty($i['item_pedido'])) {
            $linhas[] = 'Doc. Tec.: ' . ($i['numero_pedido'] ?? '-')
                . ' | Item Ped.: ' . ($i['item_pedido'] ?? '-');
        }
        if (!empty($i['informacoes_complementares'])) {
            $linhas[] = $i['informacoes_complementares'];
        }

        if ($this->custom !== null && $this->custom->temObservacoesAdicionais()) {
            $linhas[] = (string) $this->custom->observacoesAdicionais;
        }

        $tFed = $dados->tributacaoFederal;
        $tMun = $dados->tributacaoMunicipal;
        $totFed = (float) ($tFed['irrf'] ?? 0)
            + (float) ($tFed['contribuicao_previdenciaria'] ?? 0)
            + (float) ($tFed['contribuicoes_sociais_retidas'] ?? 0)
            + (float) ($tFed['pis_debito'] ?? 0)
            + (float) ($tFed['cofins_debito'] ?? 0);
        $totMun = (float) ($tMun['issqn_apurado'] ?? 0);
        $linhas[] = sprintf(
            'Totais Aproximados dos Tributos cfe. Lei nº 12.741/2012: '
            . 'Federais R$ %s ; Estaduais R$ 0,00 ; Municipais R$ %s',
            DanfseLayout::formatarMoedaSemPrefixo($totFed),
            DanfseLayout::formatarMoedaSemPrefixo($totMun),
        );

        $texto = implode("\n", $linhas);
        $alturaRestante = max(2.0, 28.7 - $this->cursorY);
        $this->renderCelulaTextoLongo(0.30, $this->cursorY, 20.40, $alturaRestante, $texto);
        $this->cursorY += $alturaRestante;
    }

    // ================================================================
    // HELPERS DE RENDERIZAÇÃO
    // ================================================================

    /**
     * @param list<int>|null $corFundo RGB do fundo da célula; null usa o padrão
     */
    private function renderCelula(
        float $xCm,
        float $yCm,
        float $larguraCm,
        float $alturaCm,
        string $label,
        string $valor,
        int $tamanhoLabel = DanfseLayout::TAM_LABEL_CAMPO,
        bool $labelCaixaAlta = false,
        bool $sombreado = false,
        ?array $corFundo = null,
    ): void {
        $marginX = DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM);
        $x = $marginX + DanfseLayout::cmToMm($xCm - DanfseLayout::MARGIN_X_CM);
        $y = DanfseLayout::cmToMm($yCm);
        $largura = DanfseLayout::cmToMm($larguraCm);
        $altura = DanfseLayout::cmToMm($alturaCm);

        // Estilo V1: sem caixa por campo. Só preenche o fundo quando há
        // sombreamento (célula de destaque/total) ou destaque de retenção —
        // sem traço de borda (fill puro 'F').
        if ($sombreado || $corFundo !== null) {
            $cor = $corFundo ?? DanfseLayout::COR_SOMBREAMENTO;
            $this->pdf->SetFillColor(...$cor);
            $this->pdf->Rect($x, $y, $largura, $altura, 'F');
        }

        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', $tamanhoLabel);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        $this->pdf->SetXY($x + 0.5, $y + 0.3);
        $this->pdf->Cell($largura - 1, 2.5, $labelCaixaAlta ? mb_strtoupper($label) : $label, 0, 0, 'L');

        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', DanfseLayout::TAM_CONTEUDO);
        $this->pdf->SetXY($x + 0.5, $y + 2.8);
        $this->pdf->Cell($largura - 1, 3, $valor, 0, 0, 'L');
    }

    private function renderCelulaTextoLongo(
        float $xCm,
        float $yCm,
        float $larguraCm,
        float $alturaCm,
        string $texto,
        ?string $label = null,
    ): void {
        $marginX = DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM);
        $x = $marginX + DanfseLayout::cmToMm($xCm - DanfseLayout::MARGIN_X_CM);
        $y = DanfseLayout::cmToMm($yCm);
        $largura = DanfseLayout::cmToMm($larguraCm);
        $altura = DanfseLayout::cmToMm($alturaCm);

        $offsetTexto = 1.0;
        if ($label !== null) {
            $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', DanfseLayout::TAM_LABEL_CAMPO);
            $this->pdf->SetXY($x + 0.5, $y + 0.3);
            $this->pdf->Cell($largura - 1, 2.5, $label, 0, 0, 'L');
            $offsetTexto = 3.0;
        }

        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', DanfseLayout::TAM_CONTEUDO);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        $this->pdf->SetXY($x + 0.5, $y + $offsetTexto);
        $this->pdf->MultiCell($largura - 1, 3, $texto, 0, 'L');
    }

    private function renderLinhaSupressao(string $texto): void
    {
        // Estilo V1: texto centralizado entre linhas divisórias finas,
        // sem caixa full-width.
        $alturaCm = self::ALTURA_TITULO_BLOCO_CM;
        $marginX = DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM);
        $y = DanfseLayout::cmToMm($this->cursorY);
        $largura = DanfseLayout::cmToMm(DanfseLayout::CONTENT_WIDTH_CM);
        $altura = DanfseLayout::cmToMm($alturaCm);

        $this->linhaSeparadora($this->cursorY);

        $this->setFonte(DanfseLayout::FONTE_TITULO, '', DanfseLayout::TAM_LABEL_BLOCO);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        $this->pdf->SetXY($marginX, $y + 0.6);
        $this->pdf->Cell($largura, $altura - 1.0, $texto, 0, 0, 'C');

        $this->cursorY += $alturaCm;
    }

    private function renderCaixaTextoUnico(float $yCm, float $alturaCm, string $texto): void
    {
        $marginX = DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM);
        $y = DanfseLayout::cmToMm($yCm);
        $largura = DanfseLayout::cmToMm(DanfseLayout::CONTENT_WIDTH_CM);
        $altura = DanfseLayout::cmToMm($alturaCm);

        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', DanfseLayout::TAM_CONTEUDO);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        $this->pdf->SetXY($marginX, $y + $altura / 2 - 2);
        $this->pdf->Cell($largura, 3, $texto, 0, 0, 'C');
    }

    private function iniciarBloco(string $titulo): void
    {
        $this->renderTituloBloco($this->cursorY, $titulo);
        $this->cursorY += self::ALTURA_TITULO_BLOCO_CM;
    }

    private function renderTituloBloco(float $yCm, string $titulo): void
    {
        // Estilo V1: linha divisória fina acima do título + negrito MAIÚSCULAS,
        // sem faixa cinza de fundo.
        $marginX = DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM);
        $y = DanfseLayout::cmToMm($yCm);
        $largura = DanfseLayout::cmToMm(DanfseLayout::CONTENT_WIDTH_CM);
        $altura = DanfseLayout::cmToMm(0.32);

        $this->linhaSeparadora($yCm);

        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', DanfseLayout::TAM_LABEL_BLOCO);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        $this->pdf->SetXY($marginX + 1, $y + 0.7);
        $this->pdf->Cell($largura - 2, $altura - 0.5, mb_strtoupper($titulo), 0, 0, 'L');
    }

    /**
     * Linha horizontal full-width — retângulo PREENCHIDO (não stroke), pra
     * evitar o anti-aliasing fuzzy que viewers aplicam em traços finos.
     * Mesma técnica do V1.
     */
    private function linhaSeparadora(float $yCm): void
    {
        $marginX = DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM);
        $largura = DanfseLayout::cmToMm(DanfseLayout::CONTENT_WIDTH_CM);
        $this->pdf->SetFillColor(...DanfseLayout::COR_BORDA);
        $this->pdf->Rect($marginX, DanfseLayout::cmToMm($yCm), $largura, self::ESPESSURA_LINHA_MM, 'F');
    }

    /**
     * Moldura externa da folha — 4 retângulos preenchidos (top/bottom/left/
     * right) formando o quadro. Mesma técnica dos separadores (preto sólido
     * sem AA). Estilo V1/ADN.
     */
    private function renderBordaFolha(): void
    {
        $margem = DanfseLayout::cmToMm(self::MARGEM_FOLHA_CM);
        $espessura = self::ESPESSURA_BORDA_MM;
        $larguraBorda = DanfseLayout::PAGE_WIDTH_MM - 2 * $margem;
        $alturaBorda = DanfseLayout::PAGE_HEIGHT_MM - 2 * $margem;

        $this->pdf->SetFillColor(...DanfseLayout::COR_BORDA);
        $this->pdf->Rect($margem, $margem, $larguraBorda, $espessura, 'F');
        $this->pdf->Rect($margem, $margem + $alturaBorda - $espessura, $larguraBorda, $espessura, 'F');
        $this->pdf->Rect($margem, $margem, $espessura, $alturaBorda, 'F');
        $this->pdf->Rect($margem + $larguraBorda - $espessura, $margem, $espessura, $alturaBorda, 'F');
    }

    private function setFonte(string $familia, string $estilo, float $tamanho): void
    {
        $this->pdf->SetFont($familia, $estilo, $tamanho);
    }

    private function s(mixed $v): string
    {
        if ($v === null || $v === '') {
            return '-';
        }
        return is_scalar($v) ? (string) $v : '-';
    }

    private function ns(mixed $v): ?string
    {
        if ($v === null || $v === '') {
            return null;
        }
        return is_scalar($v) ? (string) $v : null;
    }

    /**
     * @param array<string, string|null> $endereco
     */
    private function municipioUf(array $endereco): string
    {
        $municipio = $endereco['municipio'] ?? null;
        $uf = $endereco['uf'] ?? null;
        if ($municipio === null || $uf === null) {
            $info = IbgeMunicipios::buscar($endereco['codigo_municipio'] ?? null);
            if ($info !== null) {
                $municipio ??= $info['nome'];
                $uf ??= $info['uf'];
            }
        }
        if ($municipio === null && $uf === null) {
            return '-';
        }
        return ($municipio ?? '-') . ' / ' . ($uf ?? '-');
    }

    /**
     * @param array<string, string|null> $endereco
     */
    private function montarEndereco(array $endereco): string
    {
        $partes = [];
        if (!empty($endereco['logradouro'])) {
            $partes[] = $endereco['logradouro'];
        }
        if (!empty($endereco['numero'])) {
            $partes[] = $endereco['numero'];
        }
        if (!empty($endereco['complemento'])) {
            $partes[] = $endereco['complemento'];
        }
        if (!empty($endereco['bairro'])) {
            $partes[] = $endereco['bairro'];
        }
        return $partes !== [] ? implode(', ', $partes) : '-';
    }

    private function labelTipoEmitente(?string $codigo): string
    {
        return match ($codigo) {
            '1' => 'Prestador',
            '2' => 'Tomador',
            '3' => 'Intermediário',
            default => 'Prestador',
        };
    }

    private function labelSituacao(int $cStat): string
    {
        return match ($cStat) {
            100 => 'NFS-e regular',
            101 => 'NFS-e cancelada',
            102 => 'NFS-e cancelada por substituição',
            default => $cStat > 0 ? "cStat={$cStat}" : 'NFS-e regular',
        };
    }

    private function labelFinalidade(?string $codigo): string
    {
        return match ($codigo) {
            '0' => 'NFS-e regular',
            '1' => 'NFS-e Complementar',
            '2' => 'NFS-e Substituição',
            default => 'NFS-e regular',
        };
    }

    private function labelSimplesNacional(?string $codigo): string
    {
        if ($codigo === null) {
            return '-';
        }
        return DanfseLayout::simplesNacionalLabels()[(int) $codigo] ?? '-';
    }

    private function labelTipoTributacaoIssqn(?string $codigo): string
    {
        if ($codigo === null) {
            return '-';
        }
        return DanfseLayout::tipoTributacaoIssqn()[(int) $codigo] ?? $codigo;
    }

    private function labelRetencaoIssqn(?string $codigo): string
    {
        if ($codigo === null) {
            return '-';
        }
        return DanfseLayout::tipoRetencaoIssqn()[(int) $codigo] ?? $codigo;
    }

    private function somarExclusoes(DanfseDados $dados): float
    {
        return (float) ($dados->valorTotal['desconto_incondicionado'] ?? 0)
            + (float) ($dados->tributacaoMunicipal['issqn_apurado'] ?? 0)
            + (float) ($dados->tributacaoFederal['pis_debito'] ?? 0)
            + (float) ($dados->tributacaoFederal['cofins_debito'] ?? 0);
    }

    /**
     * Retorna a cor de destaque (amarelo) se a opção estiver ativa na
     * customização e o valor retido for maior que zero.
     *
     * @return list<int>|null
     */
    private function corDestaqueSePossuirValor(mixed $valor): ?array
    {
        // Se a opção não foi ativada na customização, retorna null direto
        if (!$this->destacarRetencoes) {
            return null;
        }

        if ($valor === null) {
            return null;
        }

        $numero = (float) $valor;

        return $numero > 0
            ? DanfseLayout::COR_AMARELO_DESTAQUE
            : null;
    }
}
