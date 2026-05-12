<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

use TCPDF;
use TCPDF2DBarcode;

/**
 * Gerador do DANFSE (PDF) conforme NT 008/2026.
 *
 * Usa TCPDF (já dependência transitiva, não adiciona peso ao projeto).
 * Layout em blocos: cabeçalho → prestador → tomador → serviço → valores
 * → tributos → tarja (se cancelada) → rodapé.
 *
 * Cada bloco é um método privado, fácil de modificar isoladamente conforme
 * o leiaute oficial evoluir.
 */
final class DanfseGenerator
{
    /** @var TCPDF */
    private TCPDF $pdf;

    private float $cursorY;

    public function gerar(DanfseDados $dados): string
    {
        $this->pdf = new TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $this->pdf->SetCreator('mendesalexandre/php-nfse-nacional');
        $this->pdf->SetAuthor($dados->prestador['razao_social'] ?? 'Prestador');
        $this->pdf->SetTitle('DANFSE ' . ($dados->numero() ?? '—'));
        $this->pdf->SetMargins(
            DanfseLayout::MARGIN_MM,
            DanfseLayout::MARGIN_MM,
            DanfseLayout::MARGIN_MM,
        );
        $this->pdf->SetAutoPageBreak(true, DanfseLayout::MARGIN_MM);
        $this->pdf->setPrintHeader(false);
        $this->pdf->setPrintFooter(false);
        $this->pdf->AddPage();
        $this->cursorY = DanfseLayout::MARGIN_MM;

        $this->blocoCabecalho($dados);
        $this->blocoPrestador($dados);
        $this->blocoTomador($dados);
        $this->blocoDiscriminacao($dados);
        $this->blocoValores($dados);
        $this->blocoTributos($dados);
        $this->blocoRodape($dados);

        if ($dados->cancelada) {
            $this->desenharTarjaCancelada();
        }

        /** @var string $output  TCPDF::Output('S') sempre retorna string */
        $output = $this->pdf->Output('', 'S');
        return $output;
    }

    // ====== BLOCOS ======

    private function blocoCabecalho(DanfseDados $dados): void
    {
        $this->desenharCaixa($this->cursorY, DanfseLayout::ALTURA_CABECALHO_MM, 'DANFSE — Documento Auxiliar da NFS-e');

        $y0 = $this->cursorY + 1;
        $x0 = DanfseLayout::MARGIN_MM + 2;

        $this->texto($x0, $y0 + 5, 'Município:', DanfseLayout::TAM_LABEL);
        $this->texto($x0, $y0 + 9, $dados->identificacao['local_emissao'] ?? '—', DanfseLayout::TAM_TEXTO, true);

        $this->texto($x0, $y0 + 14, 'Nº NFS-e:', DanfseLayout::TAM_LABEL);
        $this->texto($x0, $y0 + 18, $dados->numero() ?? '—', DanfseLayout::TAM_DESTAQUE, true);

        $this->texto($x0 + 40, $y0 + 14, 'Data Emissão:', DanfseLayout::TAM_LABEL);
        $this->texto(
            $x0 + 40,
            $y0 + 18,
            DanfseLayout::formatarDataHora($dados->identificacao['data_emissao']),
            DanfseLayout::TAM_TEXTO,
        );

        $this->texto($x0 + 90, $y0 + 14, 'Código Verificação:', DanfseLayout::TAM_LABEL);
        $this->texto(
            $x0 + 90,
            $y0 + 18,
            $dados->identificacao['codigo_verificacao'] ?? '—',
            DanfseLayout::TAM_TEXTO,
        );

        // QR Code no canto direito do cabeçalho
        if ($dados->qrCodeUrl !== null) {
            $qrSize = DanfseLayout::ALTURA_QR_CODE_MM;
            $qrX = DanfseLayout::PAGE_WIDTH_MM - DanfseLayout::MARGIN_MM - $qrSize - 2;
            $qrY = $this->cursorY + 2;
            $this->desenharQrCode($dados->qrCodeUrl, $qrX, $qrY, $qrSize);
        }

        $this->cursorY += DanfseLayout::ALTURA_CABECALHO_MM + 1;
    }

    private function blocoPrestador(DanfseDados $dados): void
    {
        $altura = 24.0;
        $this->desenharCaixa($this->cursorY, $altura, 'PRESTADOR DOS SERVIÇOS');

        $y0 = $this->cursorY + 5;
        $x0 = DanfseLayout::MARGIN_MM + 2;
        $p = $dados->prestador;

        $this->linhaCampo($x0, $y0, 'Razão Social', $p['razao_social']);
        $this->linhaCampo($x0, $y0 + 4.5, 'CNPJ', DanfseLayout::formatarDocumento($p['cnpj']), 50);
        $this->linhaCampo($x0 + 60, $y0 + 4.5, 'Inscrição Municipal', $p['inscricao_municipal'], 60);
        $endereco = sprintf(
            '%s, %s%s — %s — CEP %s',
            $p['logradouro'] ?? '—',
            $p['numero'] ?? 'S/N',
            $p['complemento'] !== null ? " ({$p['complemento']})" : '',
            $p['bairro'] ?? '—',
            DanfseLayout::formatarCep($p['cep']),
        );
        $this->linhaCampo($x0, $y0 + 9, 'Endereço', $endereco);
        $this->linhaCampo($x0, $y0 + 13.5, 'E-mail', $p['email'] ?? '—', 100);
        $this->linhaCampo($x0 + 110, $y0 + 13.5, 'Telefone', $p['telefone'] ?? '—', 50);

        $this->cursorY += $altura + 1;
    }

    private function blocoTomador(DanfseDados $dados): void
    {
        $altura = 24.0;
        $this->desenharCaixa($this->cursorY, $altura, 'TOMADOR DOS SERVIÇOS');

        $y0 = $this->cursorY + 5;
        $x0 = DanfseLayout::MARGIN_MM + 2;
        $t = $dados->tomador;

        $this->linhaCampo($x0, $y0, 'Nome', $t['nome']);
        $this->linhaCampo(
            $x0,
            $y0 + 4.5,
            $t['tipo_documento'] === 'CPF' ? 'CPF' : 'CNPJ',
            DanfseLayout::formatarDocumento($t['documento']),
            60,
        );
        $endereco = sprintf(
            '%s, %s%s — %s — CEP %s',
            $t['logradouro'] ?? '—',
            $t['numero'] ?? 'S/N',
            $t['complemento'] !== null ? " ({$t['complemento']})" : '',
            $t['bairro'] ?? '—',
            DanfseLayout::formatarCep($t['cep']),
        );
        $this->linhaCampo($x0, $y0 + 9, 'Endereço', $endereco);
        $this->linhaCampo($x0, $y0 + 13.5, 'E-mail', $t['email'] ?? '—', 100);
        $this->linhaCampo($x0 + 110, $y0 + 13.5, 'Telefone', $t['telefone'] ?? '—', 50);

        $this->cursorY += $altura + 1;
    }

    private function blocoDiscriminacao(DanfseDados $dados): void
    {
        $altura = 35.0;
        $this->desenharCaixa($this->cursorY, $altura, 'DISCRIMINAÇÃO DOS SERVIÇOS');

        $y0 = $this->cursorY + 5;
        $x0 = DanfseLayout::MARGIN_MM + 2;

        $this->linhaCampo(
            $x0,
            $y0,
            'Código Tributação Nacional',
            $dados->servico['codigo_tributacao_nacional'] ?? '—',
            70,
        );
        $this->linhaCampo(
            $x0 + 80,
            $y0,
            'Código NBS',
            $dados->servico['codigo_nbs'] ?? '—',
            60,
        );

        $descricao = $dados->servico['descricao'] ?? '—';
        $this->pdf->SetXY($x0, $y0 + 5);
        $this->pdf->SetFont(DanfseLayout::FONTE_NORMAL, '', DanfseLayout::TAM_TEXTO);
        $this->pdf->MultiCell(
            DanfseLayout::CONTENT_WIDTH_MM - 4,
            4,
            $descricao,
            0,
            'L',
        );

        $this->cursorY += $altura + 1;
    }

    private function blocoValores(DanfseDados $dados): void
    {
        $altura = 24.0;
        $this->desenharCaixa($this->cursorY, $altura, 'VALORES DO SERVIÇO E IMPOSTO');

        $y0 = $this->cursorY + 5;
        $x0 = DanfseLayout::MARGIN_MM + 2;
        $v = $dados->valores;

        $col = (DanfseLayout::CONTENT_WIDTH_MM - 4) / 4;

        $this->linhaCampo($x0 + $col * 0, $y0, 'Valor dos Serviços (R$)', DanfseLayout::formatarMoeda($v['valor_servicos'] ?? null), $col);
        $this->linhaCampo($x0 + $col * 1, $y0, 'Deduções/Reduções (R$)', DanfseLayout::formatarMoeda($v['valor_deducoes'] ?? null), $col);
        $this->linhaCampo($x0 + $col * 2, $y0, 'Base de Cálculo (R$)', DanfseLayout::formatarMoeda($v['base_calculo_issqn'] ?? null), $col);
        $this->linhaCampo($x0 + $col * 3, $y0, 'Alíquota', DanfseLayout::formatarPercentual($v['aliquota_aplicada'] ?? null), $col);

        $this->linhaCampo($x0 + $col * 0, $y0 + 10, 'Desconto Incond. (R$)', DanfseLayout::formatarMoeda($v['valor_desconto_incond'] ?? null), $col);
        $this->linhaCampo($x0 + $col * 1, $y0 + 10, 'ISSQN Apurado (R$)', DanfseLayout::formatarMoeda($v['valor_issqn'] ?? null), $col);
        $this->linhaCampo($x0 + $col * 2, $y0 + 10, 'Valor Líquido (R$)', DanfseLayout::formatarMoeda($v['valor_liquido'] ?? null), $col);
        $issqnRetido = ($dados->tributos['tipo_retencao_issqn'] ?? '1') === '2' ? 'Sim' : 'Não';
        $this->linhaCampo($x0 + $col * 3, $y0 + 10, 'ISSQN Retido?', $issqnRetido, $col);

        $this->cursorY += $altura + 1;
    }

    private function blocoTributos(DanfseDados $dados): void
    {
        $altura = 16.0;
        $this->desenharCaixa($this->cursorY, $altura, 'TRIBUTOS APROXIMADOS (Lei 12.741/2012)');

        $y0 = $this->cursorY + 5;
        $x0 = DanfseLayout::MARGIN_MM + 2;
        $t = $dados->tributos;
        $col = (DanfseLayout::CONTENT_WIDTH_MM - 4) / 3;

        $this->linhaCampo($x0 + $col * 0, $y0, 'Federal (%)', DanfseLayout::formatarPercentual((float) ($t['percentual_total_trib_federal'] ?? 0)), $col);
        $this->linhaCampo($x0 + $col * 1, $y0, 'Estadual (%)', DanfseLayout::formatarPercentual((float) ($t['percentual_total_trib_estadual'] ?? 0)), $col);
        $this->linhaCampo($x0 + $col * 2, $y0, 'Municipal (%)', DanfseLayout::formatarPercentual((float) ($t['percentual_total_trib_municipal'] ?? 0)), $col);

        $regime = (int) ($dados->prestador['regime_especial'] ?? 0);
        $this->linhaCampo($x0, $y0 + 7, 'Regime Especial de Tributação', DanfseLayout::regimesEspeciais()[$regime] ?? '—');

        $this->cursorY += $altura + 1;
    }

    private function blocoRodape(DanfseDados $dados): void
    {
        $y0 = DanfseLayout::PAGE_HEIGHT_MM - DanfseLayout::MARGIN_MM - 15;
        $x0 = DanfseLayout::MARGIN_MM;

        $this->pdf->SetFont(DanfseLayout::FONTE_NORMAL, '', 6);
        $this->pdf->SetXY($x0, $y0);
        $this->pdf->Cell(
            DanfseLayout::CONTENT_WIDTH_MM,
            3,
            'Chave de Acesso: ' . ($dados->chave() ?? '—'),
            0,
            1,
            'L',
        );
        $this->pdf->Cell(
            DanfseLayout::CONTENT_WIDTH_MM,
            3,
            'Consulte em: ' . ($dados->qrCodeUrl ?? 'https://www.nfse.gov.br/'),
            0,
            1,
            'L',
        );
        $this->pdf->Cell(
            DanfseLayout::CONTENT_WIDTH_MM,
            3,
            'Processado em ' . DanfseLayout::formatarDataHora($dados->identificacao['data_processamento'])
                . ' · Protocolo: ' . ($dados->identificacao['protocolo'] ?? '—'),
            0,
            1,
            'L',
        );
    }

    private function desenharTarjaCancelada(): void
    {
        $this->pdf->StartTransform();
        $this->pdf->Rotate(
            45,
            DanfseLayout::PAGE_WIDTH_MM / 2,
            DanfseLayout::PAGE_HEIGHT_MM / 2,
        );
        $this->pdf->SetFont(DanfseLayout::FONTE_NORMAL, 'B', 64);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TARJA_CANCELADA);
        $this->pdf->SetAlpha(0.4);
        $this->pdf->SetXY(0, DanfseLayout::PAGE_HEIGHT_MM / 2 - 15);
        $this->pdf->Cell(
            DanfseLayout::PAGE_WIDTH_MM,
            30,
            'CANCELADA',
            0,
            0,
            'C',
        );
        $this->pdf->SetAlpha(1);
        $this->pdf->StopTransform();
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
    }

    // ====== HELPERS DE DESENHO ======

    private function desenharCaixa(float $y, float $altura, string $titulo): void
    {
        $this->pdf->SetDrawColor(...DanfseLayout::COR_BORDA);
        $this->pdf->Rect(DanfseLayout::MARGIN_MM, $y, DanfseLayout::CONTENT_WIDTH_MM, $altura);

        $this->pdf->SetFont(DanfseLayout::FONTE_NORMAL, 'B', DanfseLayout::TAM_LABEL);
        $this->pdf->SetXY(DanfseLayout::MARGIN_MM + 2, $y + 0.5);
        $this->pdf->Cell(0, 3, $titulo, 0, 0, 'L');
    }

    private function texto(float $x, float $y, string $conteudo, float $tamanho, bool $negrito = false): void
    {
        $this->pdf->SetXY($x, $y);
        $this->pdf->SetFont(DanfseLayout::FONTE_NORMAL, $negrito ? 'B' : '', $tamanho);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        $this->pdf->Cell(0, 3, $conteudo, 0, 0, 'L');
    }

    private function linhaCampo(float $x, float $y, string $label, ?string $valor, ?float $larguraMaxMm = null): void
    {
        $this->pdf->SetXY($x, $y);
        $this->pdf->SetFont(DanfseLayout::FONTE_NORMAL, '', DanfseLayout::TAM_LABEL);
        $this->pdf->SetTextColor(...DanfseLayout::COR_LABEL);
        $this->pdf->Cell($larguraMaxMm ?? 0, 2.5, $label, 0, 0, 'L');

        $this->pdf->SetXY($x, $y + 2.5);
        $this->pdf->SetFont(DanfseLayout::FONTE_NORMAL, 'B', DanfseLayout::TAM_TEXTO);
        $this->pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
        $this->pdf->Cell($larguraMaxMm ?? 0, 3.5, $valor ?? '—', 0, 0, 'L');
    }

    private function desenharQrCode(string $url, float $x, float $y, float $size): void
    {
        if (class_exists(TCPDF2DBarcode::class)) {
            $this->pdf->write2DBarcode($url, 'QRCODE,M', $x, $y, $size, $size, [
                'border' => false,
                'padding' => 0,
            ]);
        }
    }
}
