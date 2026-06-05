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
 * Strategy de renderização da DANFSe no **leiaute V1.0** — o mesmo que o
 * ADN/SEFIN renderiza hoje em `/danfse/{chave}`. Layout pré-NT 008/2026.
 *
 * Paradigma visual do V1:
 *   - **Sem caixa por campo** — labels (pequenas, bold) acima dos valores
 *     (normal), posicionados por coordenada absoluta.
 *   - **Sem fundo/sombreamento** em cabeçalho ou títulos de bloco.
 *   - **Separadores horizontais** finos entre blocos, nada mais.
 *   - Title de bloco fica como label-anchor na coluna 1 (e.g. "EMITENTE DA
 *     NFS-e" / "Prestador do Serviço" empilhados verticalmente), não como
 *     barra horizontal.
 *
 * V1 NÃO tem bloco TRIBUTAÇÃO IBS/CBS (Reforma Tributária está em rampa e
 * o ADN ainda não inclui esse bloco). "TOTAIS APROXIMADOS DOS TRIBUTOS"
 * vem como bloco tabular dedicado de 3 colunas (Fed/Est/Mun em percentual),
 * fora de INFORMAÇÕES COMPLEMENTARES.
 */
final class DanfseLayoutV1 implements DanfseLayoutStrategy
{
    private TCPDF $pdf;
    private float $cursorY = 0.0;
    private ?DanfseCustomizacao $custom = null;
    /** Override temporário de fonte (usado em blocos com fonte menor). */
    private ?float $tamLabelOverride = null;
    private ?float $tamValorOverride = null;

    /** Espessura dos separadores internos.
     *  0.25mm ≈ 0.71pt — espesso o suficiente pra renderizar como pixel(s)
     *  sólido(s) em viewers comuns sem precisar de anti-aliasing.
     *  Abaixo disso (0.20mm), AA do viewer cria meia-pixels cinzas e dá
     *  impressão de "embaçado". */
    private const ESPESSURA_LINHA_MM = 0.25;

    /** Espessura da borda externa da folha — mais grossa que separadores. */
    private const ESPESSURA_BORDA_MM = 0.36;

    /** Distância (cm) da borda externa à borda da folha. */
    private const MARGEM_FOLHA_CM = 0.17;

    /** Tamanho das fontes — calibrado pra legibilidade dentro do padding interno. */
    private const TAM_LABEL = 7.5;
    private const TAM_VALOR = 7.5;
    private const TAM_TITULO_BLOCO = 7.5;
    private const TAM_TITULO_CABEC = 10;
    private const TAM_SUBTITULO_CABEC = 8;
    private const TAM_PREFEITURA = 7.5;
    private const TAM_INFO_CABEC = 6;
    private const TAM_QR_AUTH = 5.8;

    /** QR Code menor que o default (1.52cm). */
    private const QR_TAMANHO_CM = 1.30;

    /** Letter spacing (mm) aplicado a todos os textos — dá um respiro tipo SEFIN. */
    private const LETTER_SPACING_MM = 0.05;

    /** Espaçamentos verticais (cm). */
    private const ESP_ENTRE_LABEL_VALOR = 0.32;
    private const ESP_APOS_VALOR = 0.35;

    /** Padding interno (~10px) entre a borda externa e o conteúdo. */
    private const PAD_INTERNO = 0.27;

    /** Posições X (cm) das 4 colunas — já com PAD_INTERNO aplicado à esquerda. */
    private const COL_X = [0.57, 5.54, 10.51, 15.48];
    private const COL_LARG = 4.95;
    /** Spans pré-calculados pra cells que ocupam várias colunas. */
    private const COL_LARG_2 = 9.92;
    private const COL_LARG_3 = 14.89;
    private const COL_LARG_4 = 19.86;

    /** Layout das linhas de Endereço — alinhado com a grade de 4 colunas:
     *   Endereço ocupa cols 1-2 (mesma faixa de Nome)
     *   Município ocupa col 3 (mesma faixa de E-mail — vertical alignment)
     *   CEP ocupa col 4 (mesma faixa de Telefone)
     */
    private const ENDERECO_LARG_CM = 9.92; // = COL_LARG_2 (cols 1-2)
    private const MUN_X_ENDERECO_CM = 10.51; // = COL_X[2] (col 3)
    private const MUN_LARG_ENDERECO_CM = 4.95; // = COL_LARG
    private const CEP_X_ENDERECO_CM = 15.48; // = COL_X[3] (col 4)
    private const CEP_LARG_ENDERECO_CM = 4.95; // = COL_LARG

    public function versao(): DanfseVersao
    {
        return DanfseVersao::V1;
    }

    public function renderizar(DanfseDados $dados, TCPDF $pdf, ?DanfseCustomizacao $custom = null): void
    {
        $this->pdf = $pdf;
        $this->custom = $custom;
        // Gap menor no topo (0.10cm) — só pro cabeçalho. PAD_INTERNO segue
        // sendo usado nas posições X horizontais.
        $this->cursorY = DanfseLayout::MARGIN_Y_CM + 0.10;

        $this->pdf->SetLineWidth(self::ESPESSURA_LINHA_MM);
        $this->pdf->SetDrawColor(...DanfseLayout::COR_BORDA);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        // Letter spacing global — dá aquele respiro tipo SEFIN entre caracteres
        $this->pdf->setFontSpacing(self::LETTER_SPACING_MM);

        // Borda externa da folha (1pt, NT 008/2026 manda; ADN V1 também tem)
        $this->renderBordaFolha();

        $this->renderCabecalho($dados);
        $this->renderIdentificacao($dados);
        $this->renderEmitente($dados);
        $this->renderTomador($dados);
        $this->renderIntermediario($dados);
        $this->renderServico($dados);
        $this->renderTributacaoMunicipal($dados);
        $this->renderTributacaoFederal($dados);
        $this->renderValorTotal($dados);
        $this->renderTotaisAproximados($dados);
        $this->renderInformacoesComplementares($dados);
    }

    // ================================================================
    // CABEÇALHO
    // ================================================================

    private function renderCabecalho(DanfseDados $dados): void
    {
        $yIni = $this->cursorY;
        $altCabec = 1.20;

        // Logo NFSe à esquerda
        $logoPath = __DIR__ . '/../../../resources/assets/logo-nfse-horizontal.png';
        if (file_exists($logoPath)) {
            $this->pdf->Image(
                $logoPath,
                DanfseLayout::cmToMm(self::COL_X[0]),
                DanfseLayout::cmToMm($yIni),
                DanfseLayout::cmToMm(3.50),
                DanfseLayout::cmToMm(0.85),
                'PNG',
            );
        }

        // Centro — DANFSe v1.0 / subtítulo / (homol) tarja vermelha
        $xCentro = DanfseLayout::cmToMm(self::COL_X[1]);
        $larguraCentro = DanfseLayout::cmToMm(self::COL_LARG_2);

        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', self::TAM_TITULO_CABEC);
        $this->pdf->SetXY($xCentro, DanfseLayout::cmToMm($yIni));
        $this->pdf->Cell($larguraCentro, 4, 'DANFSe v1.0', 0, 0, 'C');

        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', self::TAM_SUBTITULO_CABEC);
        $this->pdf->SetXY($xCentro, DanfseLayout::cmToMm($yIni + 0.40));
        $this->pdf->Cell($larguraCentro, 4, 'Documento Auxiliar da NFS-e', 0, 0, 'C');

        if ($dados->homologacao) {
            $this->pdf->SetTextColor(...DanfseLayout::COR_VERMELHO_HOMOL);
            $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', self::TAM_SUBTITULO_CABEC);
            $this->pdf->SetXY($xCentro, DanfseLayout::cmToMm($yIni + 0.80));
            $this->pdf->Cell($larguraCentro, 4, DanfseLayout::TEXTO_HOMOLOGACAO, 0, 0, 'C');
            $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        }

        // Direita (acima do QR) — PREFEITURA / MUNICIPIO / email (sem negrito)
        $xDir = DanfseLayout::cmToMm(self::COL_X[3]);
        $larguraDir = DanfseLayout::cmToMm(DanfseLayout::QR_X_CM + self::PAD_INTERNO - self::COL_X[3] - 0.10);

        $municipio = $this->upper($dados->prestador['municipio'] ?? '-');
        // Linha 1 — "PREFEITURA MUNICIPAL DE ..." em fonte ligeiramente maior
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_PREFEITURA);
        $this->pdf->SetXY($xDir, DanfseLayout::cmToMm($yIni));
        $this->pdf->Cell($larguraDir, 2.5, 'PREFEITURA MUNICIPAL DE ' . $municipio, 0, 0, 'L');

        // Linha 2 — "MUNICIPIO DE ..." em fonte menor
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_INFO_CABEC);
        $this->pdf->SetXY($xDir, DanfseLayout::cmToMm($yIni + 0.30));
        $this->pdf->Cell($larguraDir, 2.5, 'MUNICIPIO DE ' . $municipio, 0, 0, 'L');

        $emailMun = mb_strtoupper(sprintf(
            'CENTRAL.ISSQN@%s.%s.GOV.BR',
            $this->slugMunicipio($dados->prestador['municipio'] ?? ''),
            (string) ($dados->prestador['uf'] ?? ''),
        ));
        $this->pdf->SetXY($xDir, DanfseLayout::cmToMm($yIni + 0.60));
        $this->pdf->Cell($larguraDir, 2.5, $emailMun, 0, 0, 'L');

        // QR Code (com auth text logo abaixo) — fica na coluna direita do
        // grupo de identificação, BAIXO da banda do cabeçalho.
        if ($dados->qrCodeUrl !== null) {
            // Centraliza o QR (reduzido) horizontalmente dentro da col 4.
            $larguraCol4 = DanfseLayout::CONTENT_WIDTH_CM - self::COL_X[3] - self::PAD_INTERNO;
            $qrX = self::COL_X[3] + ($larguraCol4 - self::QR_TAMANHO_CM) / 2;
            // +0.15cm de margem top entre o separador do cabeçalho e o QR.
            $qrY = DanfseLayout::QR_Y_CM + self::PAD_INTERNO + 0.15;
            $this->pdf->write2DBarcode(
                $dados->qrCodeUrl,
                'QRCODE,M',
                DanfseLayout::cmToMm($qrX),
                DanfseLayout::cmToMm($qrY),
                DanfseLayout::cmToMm(self::QR_TAMANHO_CM),
                DanfseLayout::cmToMm(self::QR_TAMANHO_CM),
                ['border' => false, 'padding' => 0],
            );

            // Texto de autenticidade — abaixo do QR, na col 4 inteira.
            // Letter spacing zerado especificamente aqui pra apertar o texto
            // sem precisar reduzir fonte. Restaurado depois.
            $this->pdf->setFontSpacing(0);
            $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_QR_AUTH);
            $this->pdf->SetXY(
                DanfseLayout::cmToMm(self::COL_X[3]),
                DanfseLayout::cmToMm($qrY + self::QR_TAMANHO_CM + 0.05),
            );
            $this->pdf->MultiCell(
                DanfseLayout::cmToMm($larguraCol4),
                1.9,
                DanfseLayout::TEXTO_AUTENTICIDADE_QR,
                0,
                'J',
                false,
            );
            $this->pdf->setFontSpacing(self::LETTER_SPACING_MM);
        }

        // Separador logo abaixo do bloco do cabeçalho (gap mínimo de 0.03cm
        // — quase colado na tarja "NFS-e SEM VALIDADE JURÍDICA"). Linha
        // fina (0.10mm = ~1px) só nesse separador específico.
        $this->cursorY = $yIni + $altCabec + 0.03;
        $this->linhaSeparadora($this->cursorY, 0.10);
        $this->cursorY += 0.10;
    }

    // ================================================================
    // IDENTIFICAÇÃO (chave + números)
    // ================================================================

    private function renderIdentificacao(DanfseDados $dados): void
    {
        // Chave de Acesso (full width, label em cima e valor abaixo).
        // Margin bottom maior pra separar visualmente do grupo de números abaixo.
        $this->rotulo(self::COL_X[0], $this->cursorY, self::COL_LARG_3, 'Chave de Acesso da NFS-e');
        $this->valor(self::COL_X[0], $this->cursorY + self::ESP_ENTRE_LABEL_VALOR, self::COL_LARG_3,
            $this->chaveSemFormat($dados->chave()));
        $this->cursorY += self::ESP_ENTRE_LABEL_VALOR + 0.60;

        // Linha NFS-e: Número | Competência | Data emissão NFS-e
        $this->rotuloValorCol(0, 'Número da NFS-e', $dados->numero() ?? '-');
        $this->rotuloValorCol(1, 'Competência da NFS-e',
            DanfseLayout::formatarData($dados->identificacao['data_competencia'] ?? null));
        $this->rotuloValorCol(2, 'Data e Hora da emissão da NFS-e',
            DanfseLayout::formatarDataHora($dados->identificacao['data_emissao_nfse'] ?? null));
        $this->avancarLinha();

        // Linha DPS: Número | Série | Data emissão DPS (col 4 já está sendo
        // usada pelo QR e seu auth text, que são renderizados no cabeçalho)
        $this->rotuloValorCol(0, 'Número da DPS', $dados->identificacao['numero_dps'] ?? '-');
        $this->rotuloValorCol(1, 'Série da DPS', $dados->identificacao['serie'] ?? '-');
        $this->rotuloValorCol(2, 'Data e Hora da emissão da DPS',
            DanfseLayout::formatarDataHora($dados->identificacao['data_emissao_dps'] ?? null));
        // Margin extra (~0.25cm) — afasta o separador inferior do auth text
        // que termina na col 4 deste mesmo band.
        $this->avancarLinha(0.25);
        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;
    }

    // ================================================================
    // EMITENTE
    // ================================================================

    private function renderEmitente(DanfseDados $dados): void
    {
        $p = $dados->prestador;

        // Linha 1 — col 1 traz EMITENTE (label) + Prestador do Serviço (subtítulo)
        // empilhados; cols 2-4 trazem CNPJ/IM/Telefone com label-em-cima
        $this->tituloLateral(self::COL_X[0], $this->cursorY, 'EMITENTE DA NFS-e');
        $this->subtituloLateral(self::COL_X[0], $this->cursorY, 'Prestador do Serviço');
        $this->rotuloValorCol(1, 'CNPJ / CPF / NIF',
            DanfseLayout::formatarDocumento($p['documento']));
        $this->rotuloValorCol(2, 'Inscrição Municipal', $p['inscricao_municipal'] ?? '-');
        $this->rotuloValorCol(3, 'Telefone', DanfseLayout::formatarTelefone($p['telefone']));
        $this->avancarLinha();

        // Linha 2 — Nome (cols 1-2) + E-mail (cols 3-4)
        $this->rotuloValor(self::COL_X[0], $this->cursorY, self::COL_LARG_2,'Nome / Nome Empresarial',
            $p['nome'] ?? '-');
        $this->rotuloValor(self::COL_X[2], $this->cursorY, self::COL_LARG_2,'E-mail',
            $p['email'] ?? '-');
        $this->avancarLinha();

        // Linha 3 — Endereço (largo) + Município + CEP (estreitos)
        $this->rotulo(self::COL_X[0], $this->cursorY, self::ENDERECO_LARG_CM, 'Endereço');
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_VALOR);
        $this->pdf->SetXY(
            DanfseLayout::cmToMm(self::COL_X[0]),
            DanfseLayout::cmToMm($this->cursorY + self::ESP_ENTRE_LABEL_VALOR),
        );
        $this->pdf->MultiCell(
            DanfseLayout::cmToMm(self::ENDERECO_LARG_CM),
            3.0,
            $this->montarEndereco($p),
            0,
            'L',
            false,
        );
        $yAposEndereco = $this->pdf->GetY() / 10.0;
        $this->rotuloValor(self::MUN_X_ENDERECO_CM, $this->cursorY, self::MUN_LARG_ENDERECO_CM,
            'Município', $this->municipioUfTraco($p));
        $this->rotuloValor(self::CEP_X_ENDERECO_CM, $this->cursorY, self::CEP_LARG_ENDERECO_CM,
            'CEP', DanfseLayout::formatarCep($p['cep']));
        $cursorYLinhaNormal = $this->cursorY + self::ESP_ENTRE_LABEL_VALOR + self::ESP_APOS_VALOR;
        $this->cursorY = max($cursorYLinhaNormal, $yAposEndereco + 0.15);

        // Linha 4 — Simples Nacional | Regime Apuração SN
        $this->rotuloValor(self::COL_X[0], $this->cursorY, self::COL_LARG_2,
            'Simples Nacional na Data de Competência',
            $this->labelSimplesNacional($p['opta_simples'] ?? null));
        $this->rotuloValor(self::COL_X[2], $this->cursorY, self::COL_LARG_2,
            'Regime de Apuração Tributária pelo SN',
            $p['regime_apuracao_sn'] ?? '-');
        // Margin bottom maior — separa visualmente do bloco TOMADOR abaixo
        $this->avancarLinha(0.20);

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;
    }

    // ================================================================
    // TOMADOR
    // ================================================================

    private function renderTomador(DanfseDados $dados): void
    {
        $t = $dados->tomador;

        if (($t['documento'] ?? null) === null) {
            $this->blocoSupresso('TOMADOR DO SERVIÇO NÃO IDENTIFICADO NA NFS-e');
            return;
        }

        // Linha 1 — TOMADOR DO SERVIÇO label-anchor em col 1 + dados
        $this->tituloLateral(self::COL_X[0], $this->cursorY, 'TOMADOR DO SERVIÇO');
        $this->rotuloValorCol(1, 'CNPJ / CPF / NIF',
            DanfseLayout::formatarDocumento($t['documento']));
        $this->rotuloValorCol(2, 'Inscrição Municipal', $t['inscricao_municipal'] ?? '-');
        $this->rotuloValorCol(3, 'Telefone', DanfseLayout::formatarTelefone($t['telefone']));
        $this->avancarLinha();

        // Linha 2 — Nome (cols 1-2) + E-mail (cols 3-4)
        $this->rotuloValor(self::COL_X[0], $this->cursorY, self::COL_LARG_2,'Nome / Nome Empresarial',
            $t['nome'] ?? '-');
        $this->rotuloValor(self::COL_X[2], $this->cursorY, self::COL_LARG_2,'E-mail',
            $t['email'] ?? '-');
        $this->avancarLinha();

        // Linha 3 — Endereço (largo) + Município + CEP (estreitos)
        $endereco = $this->montarEndereco($t);
        $this->rotulo(self::COL_X[0], $this->cursorY, self::ENDERECO_LARG_CM, 'Endereço');
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_VALOR);
        $this->pdf->SetXY(
            DanfseLayout::cmToMm(self::COL_X[0]),
            DanfseLayout::cmToMm($this->cursorY + self::ESP_ENTRE_LABEL_VALOR),
        );
        $this->pdf->MultiCell(
            DanfseLayout::cmToMm(self::ENDERECO_LARG_CM),
            3.0,
            $endereco,
            0,
            'L',
            false,
        );
        $yAposEndereco = $this->pdf->GetY() / 10.0; // mm → cm
        $this->rotuloValor(self::MUN_X_ENDERECO_CM, $this->cursorY, self::MUN_LARG_ENDERECO_CM,
            'Município', $this->municipioUfTraco($t));
        $this->rotuloValor(self::CEP_X_ENDERECO_CM, $this->cursorY, self::CEP_LARG_ENDERECO_CM,
            'CEP', DanfseLayout::formatarCep($t['cep']));
        // Cursor avança pra MAX(linha normal, fim do MultiCell)
        $cursorYLinhaNormal = $this->cursorY + self::ESP_ENTRE_LABEL_VALOR + self::ESP_APOS_VALOR;
        $this->cursorY = max($cursorYLinhaNormal, $yAposEndereco + 0.15);

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;
    }

    // ================================================================
    // INTERMEDIÁRIO (V1: linha de supressão se ausente)
    // ================================================================

    private function renderIntermediario(DanfseDados $dados): void
    {
        if ($dados->semIntermediario()) {
            $this->blocoSupresso('INTERMEDIÁRIO DO SERVIÇO NÃO IDENTIFICADO NA NFS-e');
            return;
        }

        $i = $dados->intermediario;

        $this->tituloLateral(self::COL_X[0], $this->cursorY, 'INTERMEDIÁRIO DO SERVIÇO');
        $this->rotuloValorCol(1, 'CNPJ / CPF / NIF',
            DanfseLayout::formatarDocumento($i['documento'] ?? null));
        $this->rotuloValorCol(2, 'Inscrição Municipal', $i['inscricao_municipal'] ?? '-');
        $this->rotuloValorCol(3, 'Telefone', DanfseLayout::formatarTelefone($i['telefone'] ?? null));
        $this->avancarLinha();

        $this->rotuloValor(self::COL_X[0], $this->cursorY, self::COL_LARG_2,'Nome / Nome Empresarial',
            $i['nome'] ?? '-');
        $this->rotuloValorCol(3, 'E-mail', $i['email'] ?? '-');
        $this->avancarLinha();

        $this->rotuloValor(self::COL_X[0], $this->cursorY, self::COL_LARG_2,'Endereço',
            $this->montarEndereco($i));
        $this->rotuloValorCol(2, 'Município', $this->municipioUfTraco($i));
        $this->rotuloValorCol(3, 'CEP', DanfseLayout::formatarCep($i['cep'] ?? null));
        $this->avancarLinha();

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;
    }

    // ================================================================
    // SERVIÇO PRESTADO
    // ================================================================

    private function renderServico(DanfseDados $dados): void
    {
        $s = $dados->servico;

        $this->tituloBloco('SERVIÇO PRESTADO');

        // Linha 1: 4 colunas — código formatado como XX.XX.XX (padrão ADN)
        $codTribNac = $this->formatarCodTribNac($s['codigo_tributacao_nacional'] ?? null);
        $descTrib = $s['descricao_tributacao_nacional'] ?? '';
        $codTribNacComp = $codTribNac . ($descTrib !== '' ? ' - ' . $descTrib : '');

        $this->rotulo(self::COL_X[0], $this->cursorY, self::COL_LARG, 'Código de Tributação Nacional');
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_VALOR);
        $this->pdf->SetXY(
            DanfseLayout::cmToMm(self::COL_X[0]),
            DanfseLayout::cmToMm($this->cursorY + self::ESP_ENTRE_LABEL_VALOR),
        );
        $this->pdf->MultiCell(
            DanfseLayout::cmToMm(self::COL_LARG),
            3.0,
            $codTribNacComp,
            0,
            'L',
            false,
        );
        $yAposCodTrib = $this->pdf->GetY() / 10.0;

        $this->rotuloValorCol(1, 'Código de Tributação Municipal',
            $s['codigo_tributacao_municipal'] ?? '-');
        $this->rotuloValorCol(2, 'Local da Prestação',
            $this->municipioUfTraco([
                'municipio' => $s['municipio_prestacao'] ?? null,
                'uf' => $dados->prestador['uf'] ?? null,
                'codigo_municipio' => $s['codigo_municipio_prestacao'] ?? null,
            ]));
        $this->rotuloValorCol(3, 'País da Prestação', $s['pais_prestacao'] ?? '-');
        $cursorYLinhaNormal = $this->cursorY + self::ESP_ENTRE_LABEL_VALOR + self::ESP_APOS_VALOR;
        $this->cursorY = max($cursorYLinhaNormal, $yAposCodTrib + 0.15);

        // Descrição do Serviço — full width, multi-line
        $this->rotulo(self::COL_X[0], $this->cursorY, self::COL_LARG_4, 'Descrição do Serviço');
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_VALOR);
        $this->pdf->SetXY(
            DanfseLayout::cmToMm(self::COL_X[0]),
            DanfseLayout::cmToMm($this->cursorY + self::ESP_ENTRE_LABEL_VALOR),
        );
        $this->pdf->MultiCell(
            DanfseLayout::cmToMm(self::COL_LARG_4),
            3.0,
            $s['descricao_servico'] ?? '-',
            0,
            'L',
            false,
        );
        $this->avancarLinha(0.30);

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;
    }

    // ================================================================
    // TRIBUTAÇÃO MUNICIPAL (4 linhas, sem caixa)
    // ================================================================

    private function renderTributacaoMunicipal(DanfseDados $dados): void
    {
        $t = $dados->tributacaoMunicipal;
        $v = $dados->valorTotal;

        $this->tituloBloco('TRIBUTAÇÃO MUNICIPAL');

        // Fonte 1pt menor neste bloco (4 rows densas — labels longos como
        // "Suspensão da Exigibilidade do ISSQN" pedem mais respiro).
        $this->tamLabelOverride = 6.5;
        $this->tamValorOverride = 6.5;

        $municipioIncidencia = $dados->identificacao['local_incidencia']
            ?? IbgeMunicipios::buscar($dados->identificacao['cod_municipio_incidencia'] ?? null)['nome']
            ?? null;
        $ufIncidencia = IbgeMunicipios::buscar($dados->identificacao['cod_municipio_incidencia'] ?? null)['uf']
            ?? ($dados->prestador['uf'] ?? null);

        $this->rotuloValorCol(0, 'Tributação do ISSQN',
            $this->labelTipoTributacaoIssqn($this->ns($t['tipo_tributacao_codigo'])));
        $this->rotuloValorCol(1, 'País Resultado da Prestação do Serviço',
            $this->s($t['pais_resultado'] ?? null));
        $this->rotuloValorCol(2, 'Município de Incidência do ISSQN',
            ($municipioIncidencia ?? '-') . ' - ' . ($ufIncidencia ?? '-'));
        $regimeCodigo = (int) ($dados->prestador['regime_especial'] ?? 0);
        $this->rotuloValorCol(3, 'Regime Especial de Tributação',
            DanfseLayout::regimesEspeciais()[$regimeCodigo] ?? '-');
        $this->avancarLinha(0.20);

        $this->rotuloValorCol(0, 'Tipo de Imunidade', $this->s($t['tipo_imunidade'] ?? null));
        $this->rotuloValorCol(1, 'Suspensão da Exigibilidade do ISSQN',
            $this->boolLabel($t['tipo_suspensao'] ?? null));
        $this->rotuloValorCol(2, 'Número Processo Suspensão',
            $this->s($t['numero_processo_suspensao'] ?? null));
        $this->rotuloValorCol(3, 'Benefício Municipal', $this->s($t['beneficio_municipal'] ?? null));
        $this->avancarLinha(0.20);

        $this->rotuloValorCol(0, 'Valor do Serviço',
            DanfseLayout::formatarMoeda($v['valor_servicos'] ?? null));
        $this->rotuloValorCol(1, 'Desconto Incondicionado',
            DanfseLayout::formatarMoeda($t['desconto_incondicionado'] ?? null));
        $this->rotuloValorCol(2, 'Total Deduções/Reduções',
            DanfseLayout::formatarMoeda($t['total_deducoes_reducoes'] ?? null));
        $this->rotuloValorCol(3, 'Cálculo do BM',
            DanfseLayout::formatarMoeda($t['calculo_bm'] ?? null));
        $this->avancarLinha(0.20);

        $this->rotuloValorCol(0, 'BC ISSQN',
            DanfseLayout::formatarMoeda($t['base_calculo_issqn'] ?? null));
        $this->rotuloValorCol(1, 'Alíquota Aplicada',
            DanfseLayout::formatarPercentual($t['aliquota_aplicada'] ?? null));
        $this->rotuloValorCol(2, 'Retenção do ISSQN',
            $this->labelRetencaoIssqn($this->ns($t['tipo_retencao_issqn'] ?? null)));
        $this->rotuloValorCol(3, 'ISSQN Apurado',
            DanfseLayout::formatarMoeda($t['issqn_apurado'] ?? null));
        // Margin extra (~10px) abaixo da última linha antes do separador
        $this->avancarLinha(0.27);

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;

        // Reset overrides — próximos blocos usam fonte padrão
        $this->tamLabelOverride = null;
        $this->tamValorOverride = null;
    }

    // ================================================================
    // TRIBUTAÇÃO FEDERAL
    // ================================================================

    private function renderTributacaoFederal(DanfseDados $dados): void
    {
        $f = $dados->tributacaoFederal;

        $this->tituloBloco('TRIBUTAÇÃO FEDERAL');

        // Fonte 1pt menor (consistente com TRIBUTAÇÃO MUNICIPAL / VALOR TOTAL)
        $this->tamLabelOverride = 6.5;
        $this->tamValorOverride = 6.5;

        $this->rotuloValorCol(0, 'IRRF', DanfseLayout::formatarMoeda($f['irrf'] ?? null));
        $this->rotuloValorCol(1, 'Contribuição Previdenciária - Retida',
            DanfseLayout::formatarMoeda($f['contribuicao_previdenciaria'] ?? null));
        $this->rotuloValorCol(2, 'Contribuições Sociais - Retidas',
            DanfseLayout::formatarMoeda($f['contribuicoes_sociais_retidas'] ?? null));
        $this->rotuloValorCol(3, 'Descrição Contrib. Sociais - Retidas',
            $this->s($f['descricao_contrib_sociais'] ?? null));
        $this->avancarLinha(0.20);

        $this->rotuloValorCol(0, 'PIS - Débito Apuração Própria',
            DanfseLayout::formatarMoeda($f['pis_debito'] ?? null));
        $this->rotuloValorCol(1, 'COFINS - Débito Apuração Própria',
            DanfseLayout::formatarMoeda($f['cofins_debito'] ?? null));
        $this->avancarLinha(0.20);

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;

        // Reset overrides
        $this->tamLabelOverride = null;
        $this->tamValorOverride = null;
    }

    // ================================================================
    // VALOR TOTAL
    // ================================================================

    private function renderValorTotal(DanfseDados $dados): void
    {
        $v = $dados->valorTotal;

        $this->tituloBloco('VALOR TOTAL DA NFS-E');

        // Fonte 1pt menor neste bloco (mesma estratégia da TRIBUTAÇÃO MUNICIPAL)
        $this->tamLabelOverride = 6.5;
        $this->tamValorOverride = 6.5;

        $this->rotuloValorCol(0, 'Valor do Serviço',
            DanfseLayout::formatarMoeda($v['valor_servicos'] ?? null));
        $this->rotuloValorCol(1, 'Desconto Condicionado',
            DanfseLayout::formatarMoeda($v['desconto_condicionado'] ?? null));
        $this->rotuloValorCol(2, 'Desconto Incondicionado',
            DanfseLayout::formatarMoeda($v['desconto_incondicionado'] ?? null));
        $this->rotuloValorCol(3, 'ISSQN Retido',
            DanfseLayout::formatarMoeda($v['issqn_retido'] ?? null));
        $this->avancarLinha(0.20);

        $this->rotuloValorCol(0, 'Total das Retenções Federais',
            DanfseLayout::formatarMoeda($v['total_retencoes_federais'] ?? null));
        $this->rotuloValor(self::COL_X[1], $this->cursorY, 10.19,
            'PIS/COFINS - Débito Apur. Própria',
            DanfseLayout::formatarMoeda(
                (((float) ($dados->tributacaoFederal['pis_debito'] ?? 0))
                + ((float) ($dados->tributacaoFederal['cofins_debito'] ?? 0))) ?: null
            ));
        // Valor Líquido em negrito, label maior — o destaque do bloco
        $this->rotulo(self::COL_X[3], $this->cursorY, self::COL_LARG, 'Valor Líquido da NFS-e');
        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', self::TAM_VALOR + 1);
        $this->pdf->SetXY(
            DanfseLayout::cmToMm(self::COL_X[3]),
            DanfseLayout::cmToMm($this->cursorY + self::ESP_ENTRE_LABEL_VALOR),
        );
        $this->pdf->Cell(
            DanfseLayout::cmToMm(self::COL_LARG),
            3.0,
            DanfseLayout::formatarMoeda($v['valor_liquido'] ?? null),
            0,
            0,
            'L',
        );
        $this->avancarLinha(0.20);

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;

        // Reset overrides
        $this->tamLabelOverride = null;
        $this->tamValorOverride = null;
    }

    // ================================================================
    // TOTAIS APROXIMADOS DOS TRIBUTOS (tabular, 3 colunas centralizadas)
    // ================================================================

    private function renderTotaisAproximados(DanfseDados $dados): void
    {
        $this->tituloBloco('TOTAIS APROXIMADOS DOS TRIBUTOS');

        // Fonte 1pt menor neste bloco (mesma estratégia da TRIBUTAÇÃO MUNICIPAL / VALOR TOTAL)
        $this->tamLabelOverride = 6.5;
        $this->tamValorOverride = 6.5;

        // 3 colunas equilibradas dentro do padding interno
        $larguraCol = self::COL_LARG_4 / 3;
        $xIni = self::COL_X[0];
        $totFed = $dados->valorTotal['percentual_total_tributos_federais'] ?? 0;
        $totEst = $dados->valorTotal['percentual_total_tributos_estaduais'] ?? 0;
        $totMun = $dados->valorTotal['percentual_total_tributos_municipais'] ?? 0;

        $this->rotuloCentralizado($xIni, $this->cursorY, $larguraCol, 'Federais');
        $this->rotuloCentralizado($xIni + $larguraCol, $this->cursorY, $larguraCol, 'Estaduais');
        $this->rotuloCentralizado($xIni + $larguraCol * 2, $this->cursorY, $larguraCol, 'Municipais');
        $this->valorCentralizado($xIni, $this->cursorY + self::ESP_ENTRE_LABEL_VALOR, $larguraCol,
            DanfseLayout::formatarPercentual($totFed));
        $this->valorCentralizado($xIni + $larguraCol, $this->cursorY + self::ESP_ENTRE_LABEL_VALOR,
            $larguraCol, DanfseLayout::formatarPercentual($totEst));
        $this->valorCentralizado($xIni + $larguraCol * 2, $this->cursorY + self::ESP_ENTRE_LABEL_VALOR,
            $larguraCol, DanfseLayout::formatarPercentual($totMun));
        $this->avancarLinha(0.20);

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;

        // Reset overrides
        $this->tamLabelOverride = null;
        $this->tamValorOverride = null;
    }

    // ================================================================
    // INFORMAÇÕES COMPLEMENTARES
    // ================================================================

    private function renderInformacoesComplementares(DanfseDados $dados): void
    {
        $i = $dados->informacoesComplementares;

        $this->tituloBloco('INFORMAÇÕES COMPLEMENTARES');

        // Linhas no formato (prefixo bold, valor normal) — `null` em prefixo
        // significa linha de texto puro sem label (informações complementares
        // livres do XML, observações customizadas).
        $linhas = [];
        if (!empty($i['chave_substituida'])) {
            $linhas[] = ['NFS-e Subst.:', $i['chave_substituida']];
        }
        if (!empty($dados->servico['codigo_nbs'])) {
            $linhas[] = ['NBS:', $dados->servico['codigo_nbs']];
        }
        if (!empty($i['codigo_obra']) || !empty($i['inscricao_imobiliaria'])) {
            $linhas[] = ['Cod. Obra:',
                ($i['codigo_obra'] ?? '-') . ' | Insc. Imob.: ' . ($i['inscricao_imobiliaria'] ?? '-')];
        }
        if (!empty($i['id_atividade_evento'])) {
            $linhas[] = ['Cod. Evt.:', (string) $i['id_atividade_evento']];
        }
        if (!empty($i['numero_pedido']) || !empty($i['item_pedido'])) {
            $linhas[] = ['Doc. Tec.:',
                ($i['numero_pedido'] ?? '-') . ' | Item Ped.: ' . ($i['item_pedido'] ?? '-')];
        }
        if (!empty($i['informacoes_complementares'])) {
            $linhas[] = [null, (string) $i['informacoes_complementares']];
        }
        if ($this->custom !== null && $this->custom->temObservacoesAdicionais()) {
            $linhas[] = [null, (string) $this->custom->observacoesAdicionais];
        }

        $y = $this->cursorY;
        $xIni = self::COL_X[0];
        $larguraTotalMm = DanfseLayout::cmToMm(self::COL_LARG_4);
        foreach ($linhas as [$prefixo, $valor]) {
            $this->pdf->SetXY(DanfseLayout::cmToMm($xIni), DanfseLayout::cmToMm($y));
            $larguraPrefixoMm = 0.0;
            if ($prefixo !== null) {
                $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', self::TAM_VALOR);
                // Mede a largura exata do prefixo com a fonte bold
                $larguraPrefixoMm = $this->pdf->GetStringWidth($prefixo . ' ');
                $this->pdf->Cell($larguraPrefixoMm, 3.0, $prefixo . ' ', 0, 0, 'L');
            }
            $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_VALOR);
            $this->pdf->Cell($larguraTotalMm - $larguraPrefixoMm, 3.0, $valor, 0, 1, 'L');
            $y += 0.32;
        }
    }

    // ================================================================
    // HELPERS — paradigma "form sem caixa"
    // ================================================================

    /** Avança o cursor pra próxima linha (label + valor + espaço). */
    private function avancarLinha(float $extra = 0.0): void
    {
        $this->cursorY += self::ESP_ENTRE_LABEL_VALOR + self::ESP_APOS_VALOR + $extra;
    }

    /** Label (bold, pequeno) — só posiciona texto, sem caixa.
     *  stretch=1: comprime horizontalmente APENAS se o texto exceder a célula
     *  (labels longos como "País Resultado da Prestação do Serviço" ficam
     *  ligeiramente apertados em vez de invadir a próxima coluna). */
    private function rotulo(float $xCm, float $yCm, float $larguraCm, string $texto): void
    {
        $tam = $this->tamLabelOverride ?? self::TAM_LABEL;
        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', $tam);
        $this->pdf->SetXY(DanfseLayout::cmToMm($xCm), DanfseLayout::cmToMm($yCm));
        $this->pdf->Cell(DanfseLayout::cmToMm($larguraCm), 2.0, $texto, 0, 0, 'L',
            false, '', 1);
    }

    /** Valor (normal, maior) — só posiciona texto, sem caixa. */
    private function valor(float $xCm, float $yCm, float $larguraCm, string $texto): void
    {
        $tam = $this->tamValorOverride ?? self::TAM_VALOR;
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', $tam);
        $this->pdf->SetXY(DanfseLayout::cmToMm($xCm), DanfseLayout::cmToMm($yCm));
        $this->pdf->Cell(DanfseLayout::cmToMm($larguraCm), 3.0, $texto, 0, 0, 'L');
    }

    /** Conveniência: label em $yCm + valor em $yCm+ESP_ENTRE_LABEL_VALOR. */
    private function rotuloValor(
        float $xCm,
        float $yCm,
        float $larguraCm,
        string $rotulo,
        string $valor,
    ): void {
        $this->rotulo($xCm, $yCm, $larguraCm, $rotulo);
        $this->valor($xCm, $yCm + self::ESP_ENTRE_LABEL_VALOR, $larguraCm, $valor);
    }

    /** Conveniência pra grade de 4 colunas — usa col index (0..3). */
    private function rotuloValorCol(int $col, string $rotulo, string $valor): void
    {
        $this->rotuloValor(self::COL_X[$col], $this->cursorY, self::COL_LARG, $rotulo, $valor);
    }

    private function rotuloCentralizado(float $xCm, float $yCm, float $larguraCm, string $texto): void
    {
        $tam = $this->tamLabelOverride ?? self::TAM_LABEL;
        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', $tam);
        $this->pdf->SetXY(DanfseLayout::cmToMm($xCm), DanfseLayout::cmToMm($yCm));
        $this->pdf->Cell(DanfseLayout::cmToMm($larguraCm), 2.0, $texto, 0, 0, 'C');
    }

    private function valorCentralizado(float $xCm, float $yCm, float $larguraCm, string $texto): void
    {
        $tam = $this->tamValorOverride ?? self::TAM_VALOR;
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', $tam);
        $this->pdf->SetXY(DanfseLayout::cmToMm($xCm), DanfseLayout::cmToMm($yCm));
        $this->pdf->Cell(DanfseLayout::cmToMm($larguraCm), 3.0, $texto, 0, 0, 'C');
    }

    /**
     * Label-anchor lateral pra título de bloco "EMITENTE DA NFS-e" /
     * "TOMADOR DO SERVIÇO" etc. — ocupa col 1, fica em bold maior.
     */
    private function tituloLateral(float $xCm, float $yCm, string $titulo): void
    {
        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', self::TAM_TITULO_BLOCO);
        $this->pdf->SetXY(DanfseLayout::cmToMm($xCm), DanfseLayout::cmToMm($yCm));
        $this->pdf->Cell(DanfseLayout::cmToMm(self::COL_LARG), 2.5, $titulo, 0, 0, 'L');
    }

    /** Subtítulo abaixo do título lateral (e.g. "Prestador do Serviço"). */
    private function subtituloLateral(float $xCm, float $yCm, string $subtitulo): void
    {
        $this->setFonte(DanfseLayout::FONTE_CONTEUDO, '', self::TAM_VALOR);
        $this->pdf->SetXY(DanfseLayout::cmToMm($xCm), DanfseLayout::cmToMm($yCm + self::ESP_ENTRE_LABEL_VALOR));
        $this->pdf->Cell(DanfseLayout::cmToMm(self::COL_LARG), 3.0, $subtitulo, 0, 0, 'L');
    }

    /** Título de bloco full-width (e.g. "SERVIÇO PRESTADO") — sem fundo. */
    private function tituloBloco(string $titulo): void
    {
        $this->setFonte(DanfseLayout::FONTE_TITULO, 'B', self::TAM_TITULO_BLOCO);
        $this->pdf->SetXY(
            DanfseLayout::cmToMm(self::COL_X[0]),
            DanfseLayout::cmToMm($this->cursorY),
        );
        $this->pdf->Cell(
            DanfseLayout::cmToMm(DanfseLayout::CONTENT_WIDTH_CM),
            2.5,
            $titulo,
            0,
            0,
            'L',
        );
        $this->cursorY += 0.40;
    }

    /**
     * Linha horizontal — renderizada como retângulo PREENCHIDO (não stroke).
     * PDF viewers tendem a anti-aliasiar strokes finos, dando aquela
     * impressão de "embaçado". Rect com 'F' fica preto sólido sem AA nas
     * bordas top/bottom.
     *
     * $espessuraMm: opcional. Default usa ESPESSURA_LINHA_MM (0.25mm).
     */
    private function linhaSeparadora(float $yCm, ?float $espessuraMm = null): void
    {
        $xIni = DanfseLayout::cmToMm(self::COL_X[0]);
        $y = DanfseLayout::cmToMm($yCm);
        $largura = DanfseLayout::cmToMm(self::COL_LARG_4);
        $espessura = $espessuraMm ?? self::ESPESSURA_LINHA_MM;
        $this->pdf->SetFillColor(...DanfseLayout::COR_BORDA);
        $this->pdf->Rect($xIni, $y, $largura, $espessura, 'F');
    }

    /**
     * Retângulo externo da folha — renderizado como 4 retângulos preenchidos
     * (top, bottom, left, right) formando o quadro. Mesma técnica dos
     * separadores: preto sólido sem AA fuzzy.
     */
    private function renderBordaFolha(): void
    {
        $margem = DanfseLayout::cmToMm(self::MARGEM_FOLHA_CM);
        $espessura = self::ESPESSURA_BORDA_MM;
        $larguraBorda = DanfseLayout::PAGE_WIDTH_MM - 2 * $margem;
        $alturaBorda = DanfseLayout::PAGE_HEIGHT_MM - 2 * $margem;

        $this->pdf->SetFillColor(...DanfseLayout::COR_BORDA);
        // Top
        $this->pdf->Rect($margem, $margem, $larguraBorda, $espessura, 'F');
        // Bottom
        $this->pdf->Rect($margem, $margem + $alturaBorda - $espessura, $larguraBorda, $espessura, 'F');
        // Left
        $this->pdf->Rect($margem, $margem, $espessura, $alturaBorda, 'F');
        // Right
        $this->pdf->Rect($margem + $larguraBorda - $espessura, $margem, $espessura, $alturaBorda, 'F');
    }

    /**
     * Bloco suprimido (intermediário ausente, etc.) — texto centralizado
     * com linhas divisórias acima e abaixo.
     */
    private function blocoSupresso(string $texto): void
    {
        // NÃO renderiza linha acima — o bloco anterior já encerra com uma.
        // Estrutura visual: linha [TOMADOR sep] → texto → linha [trailing sep]
        // com gaps mínimos (~0.05cm) acima e abaixo do texto.

        // Compensa parcialmente o += 0.10 do bloco anterior pra deixar gap
        // top em ~0.05cm.
        $this->cursorY -= 0.05;

        // Sem negrito — paradigma V1 do ADN
        $this->setFonte(DanfseLayout::FONTE_TITULO, '', self::TAM_TITULO_BLOCO);
        $this->pdf->SetXY(
            DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM),
            DanfseLayout::cmToMm($this->cursorY),
        );
        $this->pdf->Cell(
            DanfseLayout::cmToMm(DanfseLayout::CONTENT_WIDTH_CM),
            2.5,
            $texto,
            0,
            0,
            'C',
        );
        // Cell de 2.5mm + 0.05cm de respiro = 0.30cm total de avanço
        $this->cursorY += 0.30;

        $this->linhaSeparadora($this->cursorY);
        $this->cursorY += 0.10;
    }

    // ================================================================
    // FORMATAÇÃO / LOOKUP
    // ================================================================

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

    private function boolLabel(mixed $v): string
    {
        if ($v === null || $v === '' || $v === '0' || $v === 0 || $v === false) {
            return 'Não';
        }
        if ($v === '1' || $v === 1 || $v === true) {
            return 'Sim';
        }
        return is_scalar($v) ? (string) $v : '-';
    }

    private function upper(string $s): string
    {
        return mb_strtoupper($s);
    }

    private function slugMunicipio(string $municipio): string
    {
        $s = strtolower($municipio);
        $s = preg_replace('/\s+/', '', $s) ?? $s;
        $s = preg_replace('/[áàâãä]/u', 'a', $s) ?? $s;
        $s = preg_replace('/[éèêë]/u', 'e', $s) ?? $s;
        $s = preg_replace('/[íìîï]/u', 'i', $s) ?? $s;
        $s = preg_replace('/[óòôõö]/u', 'o', $s) ?? $s;
        $s = preg_replace('/[úùûü]/u', 'u', $s) ?? $s;
        $s = preg_replace('/[ç]/u', 'c', $s) ?? $s;
        return $s;
    }

    private function chaveSemFormat(?string $chave): string
    {
        if ($chave === null) {
            return '-';
        }
        $d = preg_replace('/\D/', '', $chave) ?? '';
        return $d !== '' ? $d : '-';
    }

    /**
     * Formata o cTribNac de 6 dígitos (210101) como XX.XX.XX (21.01.01),
     * que é o padrão visual do ADN.
     */
    private function formatarCodTribNac(?string $codigo): string
    {
        if ($codigo === null) {
            return '-';
        }
        $d = preg_replace('/\D/', '', $codigo) ?? '';
        if (strlen($d) !== 6) {
            return $d !== '' ? $d : '-';
        }
        return substr($d, 0, 2) . '.' . substr($d, 2, 2) . '.' . substr($d, 4, 2);
    }

    /**
     * @param array<string, string|null> $endereco
     */
    private function municipioUfTraco(array $endereco): string
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
        return ($municipio ?? '-') . ' - ' . ($uf ?? '-');
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
}
