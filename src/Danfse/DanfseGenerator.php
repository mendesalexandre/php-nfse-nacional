<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

use PhpNfseNacional\Danfse\Layouts\DanfseLayoutStrategy;
use TCPDF;

/**
 * Subclasse local do TCPDF que neutraliza o link "Powered by TCPDF"
 * que o pacote injeta como annotation no rodapé. Tecnicamente legal
 * sob LGPL (atribuição mantida nos comentários e metadata).
 *
 * @internal Não faz parte da API pública do SDK.
 */
final class TcpdfSemLink extends TCPDF
{
    public function setTcpdfLink(bool $enabled): void
    {
        $this->tcpdflink = $enabled;
    }
}

/**
 * Orquestrador da geração de PDF da DANFSe.
 *
 * Responsabilidades comuns a todas as versões de leiaute:
 *  - Criar e configurar a instância TCPDF (página A4, margens, footer, etc.)
 *  - Delegar o desenho dos blocos pra um `DanfseLayoutStrategy` (V1/V2/...)
 *  - Aplicar marcas d'água "CANCELADA" / "SUBSTITUÍDA" (item 2.5.1/2.5.2 NT 008)
 *  - Garantir autoload do `TCPDF2DBarcode` (necessário pro QR Code)
 *  - Devolver os bytes do PDF
 *
 * Quem decide ESTILO visual (cabeçalho, ordem dos blocos, fontes, grid) é a
 * strategy injetada no construtor — `DanfseLayoutV1` (layout legado do ADN) ou
 * `DanfseLayoutV2` (NT 008/2026).
 */
final class DanfseGenerator
{
    public function __construct(
        private readonly DanfseLayoutStrategy $layout,
    ) {
        $this->garantirBarcode2D();
    }

    public function gerar(DanfseDados $dados, ?DanfseCustomizacao $custom = null): string
    {
        $pdf = $this->criarPdf($dados);
        $this->layout->renderizar($dados, $pdf, $custom);

        // Marcas d'água diagonais — comuns a qualquer leiaute.
        if ($dados->cancelada) {
            $this->renderMarcaAgua($pdf, 'CANCELADA');
        } elseif ($dados->substituida) {
            $this->renderMarcaAgua($pdf, 'SUBSTITUÍDA');
        }

        /** @var string $output */
        $output = $pdf->Output('', 'S');
        return $output;
    }

    private function criarPdf(DanfseDados $dados): TCPDF
    {
        $pdf = new TcpdfSemLink('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->setTcpdfLink(false);
        $pdf->SetCreator('mendesalexandre/php-nfse-nacional');
        $pdf->SetAuthor($dados->prestador['nome'] ?? 'Prestador');
        $pdf->SetTitle('DANFSE ' . ($dados->numero() ?? '-'));
        $pdf->SetMargins(
            DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM),
            DanfseLayout::cmToMm(DanfseLayout::MARGIN_Y_CM),
            DanfseLayout::cmToMm(DanfseLayout::MARGIN_X_CM),
        );
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        // Remove footer default "Powered by TCPDF" (TCPDF imprime mesmo com
        // setPrintFooter(false) em algumas versões; só limpar o método).
        $pdf->setFooterData([0, 0, 0], [0, 0, 0]);
        $pdf->setFooterFont(['', '', 0]);
        $pdf->AddPage();
        return $pdf;
    }

    private function renderMarcaAgua(TCPDF $pdf, string $texto): void
    {
        $pdf->StartTransform();
        $pdf->Rotate(
            -45,
            DanfseLayout::PAGE_WIDTH_MM / 2,
            DanfseLayout::PAGE_HEIGHT_MM / 2,
        );
        $pdf->SetFont(DanfseLayout::FONTE_TITULO, '', DanfseLayout::TAM_MARCA_AGUA);
        $pdf->SetTextColor(...DanfseLayout::COR_MARCA_AGUA);
        $pdf->SetAlpha(0.5);
        $pdf->SetXY(0, DanfseLayout::PAGE_HEIGHT_MM / 2 - 15);
        $pdf->Cell(DanfseLayout::PAGE_WIDTH_MM, 30, $texto, 0, 0, 'C');
        $pdf->SetAlpha(1);
        $pdf->StopTransform();
        $pdf->SetTextColor(...DanfseLayout::COR_TEXTO);
    }

    /**
     * Garante o autoload do TCPDF2DBarcode (necessário pra QR Code).
     * TCPDF não usa PSR-4, então a classe não é carregada automaticamente.
     */
    private function garantirBarcode2D(): void
    {
        if (class_exists(\TCPDF2DBarcode::class)) {
            return;
        }
        $candidatos = [
            __DIR__ . '/../../vendor/tecnickcom/tcpdf/tcpdf_barcodes_2d.php',
            // Layouts de instalação alternativos (composer in-tree, vendor parent)
            __DIR__ . '/../../../tecnickcom/tcpdf/tcpdf_barcodes_2d.php',
            __DIR__ . '/../../../../tecnickcom/tcpdf/tcpdf_barcodes_2d.php',
        ];
        foreach ($candidatos as $path) {
            if (file_exists($path)) {
                require_once $path;
                return;
            }
        }
    }
}
