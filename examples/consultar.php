<?php

declare(strict_types=1);

/**
 * Consulta status de uma NFS-e por chave de acesso.
 *
 * Uso:
 *   CHAVE=51079092200179028000138000000000005726057774456203 \
 *   php examples/consultar.php
 */

/** @var \PhpNfseNacional\NFSe $nfse */
$nfse = require __DIR__ . '/_bootstrap.php';

use PhpNfseNacional\Exceptions\SefinException;

$chave = envOrDie('CHAVE');

echo "Consultando NFS-e {$chave}\n\n";

try {
    $resp = $nfse->consulta()->consultarNfse($chave);

    echo "✓ Resposta:\n";
    echo "  → Chave: {$resp->chaveAcesso}\n";
    echo "  → cStat: {$resp->cStat}\n";
    echo "  → Número NFS-e: " . ($resp->numeroNfse ?? '-') . "\n";
    echo "  → Cód. Verificação: " . ($resp->codigoVerificacao ?? '-') . "\n";
    echo "  → dhProc: " . ($resp->dataProcessamento ?? '-') . "\n";

    if ($resp->xmlRetorno !== null) {
        $arquivo = '/tmp/consulta-' . date('YmdHis') . '.xml';
        file_put_contents($arquivo, $resp->xmlRetorno);
        echo "  → XML salvo em {$arquivo}\n";
    }

    echo "\nEventos vinculados:\n";
    $eventos = $nfse->consulta()->consultarEventos($chave);
    if ($eventos->xmlRetorno === null) {
        echo "  (nenhum evento)\n";
    } else {
        echo $eventos->xmlRetorno . "\n";
    }
} catch (SefinException $e) {
    echo "✗ SEFIN REJEITOU\n";
    echo "  → cStat: {$e->cStat}\n";
    echo "  → xMotivo: {$e->xMotivo}\n";
    exit(2);
}
