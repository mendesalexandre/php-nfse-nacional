<?php

declare(strict_types=1);

/**
 * Baixa o XML autorizado e o DANFSe (PDF) de uma NFS-e diretamente do
 * Portal Nacional / ADN.
 *
 * Atenção: a partir de 01/07/2026 o ADN desativa o download oficial de
 * DANFSe — a partir daí é necessário gerar o PDF localmente pelo SDK
 * (ver examples/danfse-local.php).
 *
 * Uso:
 *   CHAVE=51079092200179028000138000000000005726057774456203 \
 *   php examples/download.php
 */

/** @var \PhpNfseNacional\NFSe $nfse */
$nfse = require __DIR__ . '/_bootstrap.php';

use PhpNfseNacional\Exceptions\SefinException;

$chave = envOrDie('CHAVE');
$timestamp = date('YmdHis');

echo "Baixando artefatos da NFS-e {$chave}\n\n";

// 1. XML autorizado
try {
    $xml = $nfse->baixarXml($chave);
    $arquivoXml = "/tmp/nfse-{$chave}-{$timestamp}.xml";
    file_put_contents($arquivoXml, $xml);
    echo "✓ XML salvo em {$arquivoXml} (" . strlen($xml) . " bytes)\n";
} catch (SefinException $e) {
    echo "✗ Falha no download do XML: cStat={$e->cStat}, {$e->xMotivo}\n";
    exit(2);
}

// 2. DANFSe (PDF) via ADN
try {
    $pdf = $nfse->baixarPdf($chave);
    $arquivoPdf = "/tmp/nfse-{$chave}-{$timestamp}.pdf";
    file_put_contents($arquivoPdf, $pdf);
    echo "✓ DANFSe salvo em {$arquivoPdf} (" . strlen($pdf) . " bytes)\n";
} catch (SefinException $e) {
    echo "✗ Falha no download do DANFSe: {$e->getMessage()}\n";
    echo "  Dica: tentar gerar local via examples/danfse-local.php\n";
    exit(3);
}
