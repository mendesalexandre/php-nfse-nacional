<?php

declare(strict_types=1);

/**
 * Cancela uma NFS-e por substituição (evento e101102).
 *
 * Pré-requisito: a NFS-e substituidora já deve ter sido emitida normalmente
 * via `php examples/emitir-homologacao.php`. Esse script só registra o
 * vínculo + cancelamento da original.
 *
 * Uso (env vars do prestador idênticas ao emitir-homologacao.php +):
 *   CHAVE_ORIGINAL=51079092...456203 \
 *   CHAVE_SUBSTITUTA=51079092...456204 \
 *   MOTIVO=1 \
 *   JUSTIFICATIVA='Reemissão por divergência de valor' \
 *   php examples/substituir.php
 */

/** @var \PhpNfseNacional\NFSe $nfse */
$nfse = require __DIR__ . '/_bootstrap.php';

use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\Exceptions\SefinException;

$original = envOrDie('CHAVE_ORIGINAL');
$substituta = envOrDie('CHAVE_SUBSTITUTA');
$motivo = MotivoCancelamento::from((int) envOrDie('MOTIVO'));
$justificativa = envOrDie('JUSTIFICATIVA');

echo "Substituindo NFS-e\n";
echo "  → Original:    {$original}\n";
echo "  → Substituta:  {$substituta}\n";
echo "  → Motivo:      {$motivo->label()}\n";
echo "  → Justificativa: {$justificativa}\n\n";

try {
    $resp = $nfse->substituicao()->substituir($original, $substituta, $motivo, $justificativa);

    echo "✓ SUBSTITUIÇÃO REGISTRADA\n";
    echo "  → cStat: {$resp->cStat}\n";
    if ($resp->cStat === 840) {
        echo "  → (idempotente: já estava substituída previamente)\n";
    }
} catch (SefinException $e) {
    echo "✗ SEFIN REJEITOU\n";
    echo "  → cStat: {$e->cStat}\n";
    echo "  → xMotivo: {$e->xMotivo}\n";
    exit(2);
}
