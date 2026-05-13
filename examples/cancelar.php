<?php

declare(strict_types=1);

/**
 * Cancela uma NFS-e por chave de acesso.
 *
 * Uso (env vars do prestador idênticas ao emitir-homologacao.php +):
 *   CHAVE=51079092200179028000138000000000005726057774456203 \
 *   MOTIVO=1 \
 *   JUSTIFICATIVA='Erro na emissão — valor divergente do recibo' \
 *   php examples/cancelar.php
 *
 * Códigos de motivo:
 *   1 = Erro na Emissão
 *   2 = Serviço não Prestado
 *   9 = Outros
 */

/** @var \PhpNfseNacional\NFSe $nfse */
$nfse = require __DIR__ . '/_bootstrap.php';

use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\Exceptions\SefinException;

$chave = envOrDie('CHAVE');
$motivo = MotivoCancelamento::from((int) envOrDie('MOTIVO'));
$justificativa = envOrDie('JUSTIFICATIVA');

echo "Cancelando NFS-e {$chave}\n";
echo "  → Motivo: {$motivo->label()}\n";
echo "  → Justificativa: {$justificativa}\n\n";

try {
    $resp = $nfse->cancelar($chave, $motivo, $justificativa);

    echo "✓ CANCELADA\n";
    echo "  → cStat: {$resp->cStat}\n";
    if ($resp->eventoIdempotente()) {
        echo "  → (idempotente: já estava cancelada previamente)\n";
    }
    echo "  → xMotivo: {$resp->xMotivo}\n";
    if ($resp->protocolo !== null) {
        echo "  → Protocolo evento: {$resp->protocolo}\n";
    }
} catch (SefinException $e) {
    echo "✗ SEFIN REJEITOU\n";
    echo "  → cStat: {$e->cStat}\n";
    echo "  → xMotivo: {$e->xMotivo}\n";
    exit(2);
}
