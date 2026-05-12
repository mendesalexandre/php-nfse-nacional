<?php

declare(strict_types=1);

/**
 * Gera o DANFSe (PDF) localmente — NT 008/2026 — a partir de um XML
 * autorizado da NFS-e.
 *
 * Não exige certificado nem env vars do prestador — só o XML de entrada.
 * Útil pra:
 *   - Reprocessar PDFs sem chamar o ADN
 *   - Backups offline
 *   - Substituir o download oficial após 01/07/2026
 *
 * Uso:
 *   php examples/danfse-local.php /caminho/nfse-autorizada.xml
 *   # ou
 *   XML_PATH=/caminho/nfse.xml php examples/danfse-local.php
 *
 * Saída: /tmp/danfse-{timestamp}.pdf
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpNfseNacional\Services\DanfseService;

$xmlPath = $argv[1] ?? getenv('XML_PATH');
if (!is_string($xmlPath) || $xmlPath === '') {
    fwrite(STDERR, "Uso: php examples/danfse-local.php <caminho-xml>\n");
    exit(1);
}
if (!is_readable($xmlPath)) {
    fwrite(STDERR, "ERRO: arquivo XML não encontrado ou ilegível: {$xmlPath}\n");
    exit(1);
}

$xml = file_get_contents($xmlPath);
if ($xml === false) {
    fwrite(STDERR, "ERRO: falha ao ler {$xmlPath}\n");
    exit(1);
}

$service = new DanfseService();
$pdf = $service->gerarDoXml($xml);

$saida = '/tmp/danfse-' . date('YmdHis') . '.pdf';
file_put_contents($saida, $pdf);

echo "✓ DANFSe gerado em {$saida} (" . strlen($pdf) . " bytes)\n";
