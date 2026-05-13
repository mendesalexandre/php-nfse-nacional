<?php

declare(strict_types=1);

/**
 * Manifesta uma NFS-e (Confirmação, Rejeição ou Anulação) em homologação.
 *
 * Como o cert que temos é do PRESTADOR (cartório), só dá pra testar como
 * autor=Prestador (códigos e202201/e202205/e205208). Pra testar como
 * Tomador/Intermediário precisaria do cert deles.
 *
 * Uso:
 *   OPERACAO=confirmar CHAVE=51079... AUTOR=1 php examples/manifestar.php
 *   OPERACAO=rejeitar CHAVE=51079... AUTOR=1 MOTIVO=1 \
 *     php examples/manifestar.php
 *   OPERACAO=anular CHAVE=51079... ID_REJEICAO=PRE51079...202205 \
 *     X_MOTIVO='Rejeição feita por engano' php examples/manifestar.php
 *
 * AUTOR: 1=Prestador, 2=Tomador, 3=Intermediário
 * MOTIVO: 1=Duplicidade, 2=JaEmitidaPeloTomador, 3=SemFatoGerador,
 *         4=ErroResponsabilidade, 5=ErroValorOuData, 9=Outros (exige X_MOTIVO)
 */

/** @var \PhpNfseNacional\NFSe $nfse */
$nfse = require __DIR__ . '/_bootstrap.php';

use PhpNfseNacional\DTO\MotivoRejeicao;
use PhpNfseNacional\Enums\AutorManifestacao;
use PhpNfseNacional\Exceptions\SefinException;

$operacao = envOrDie('OPERACAO');
$chave = envOrDie('CHAVE');

try {
    if ($operacao === 'confirmar') {
        $autor = AutorManifestacao::from((int) envOrDie('AUTOR'));
        echo "Confirmando NFS-e {$chave} como {$autor->label()}\n\n";
        $resp = $nfse->confirmar($chave, $autor);
    } elseif ($operacao === 'rejeitar') {
        $autor = AutorManifestacao::from((int) envOrDie('AUTOR'));
        $motivo = MotivoRejeicao::from((int) envOrDie('MOTIVO'));
        $xMotivo = (string) (getenv('X_MOTIVO') ?: '');
        echo "Rejeitando NFS-e {$chave} como {$autor->label()}\n";
        echo "  → Motivo: {$motivo->label()}\n";
        if ($xMotivo !== '') {
            echo "  → xMotivo: {$xMotivo}\n";
        }
        echo "\n";
        $resp = $nfse->rejeitar($chave, $autor, $motivo, $xMotivo);
    } elseif ($operacao === 'anular') {
        $cpfAgente = envOrDie('CPF_AGENTE');
        $idRejeicao = envOrDie('ID_REJEICAO');
        $xMotivo = envOrDie('X_MOTIVO');
        echo "Anulando Rejeição da NFS-e {$chave}\n";
        echo "  → CPFAgTrib: {$cpfAgente}\n";
        echo "  → Id da Rejeição: {$idRejeicao}\n";
        echo "  → xMotivo: {$xMotivo}\n\n";
        $resp = $nfse->anularRejeicao($chave, $cpfAgente, $idRejeicao, $xMotivo);
    } else {
        fwrite(STDERR, "ERRO: OPERACAO deve ser 'confirmar', 'rejeitar' ou 'anular'\n");
        exit(1);
    }

    echo "✓ MANIFESTAÇÃO REGISTRADA\n";
    echo "  → cStat: {$resp->cStat}\n";
    if ($resp->eventoIdempotente()) {
        echo "  → (idempotente: já existia antes)\n";
    }
    if ($resp->xMotivo !== null && $resp->xMotivo !== '') {
        echo "  → xMotivo: {$resp->xMotivo}\n";
    }
    if ($resp->protocolo !== null) {
        echo "  → Protocolo: {$resp->protocolo}\n";
    }

    $arquivo = '/tmp/manifestacao-' . date('YmdHis') . '.xml';
    file_put_contents($arquivo, $resp->xmlRetorno ?: $resp->rawResponse);
    echo "  → XML salvo em {$arquivo}\n";

    // Mostra o Id da manifestação (útil pra futura anulação)
    if ($resp->xmlRetorno !== null && preg_match('/<infPedReg Id="(PRE\d+)"/', $resp->xmlRetorno, $m)) {
        echo "  → Id desta manifestação: {$m[1]}\n";
    }
} catch (SefinException $e) {
    echo "✗ SEFIN/ADN REJEITOU\n";
    echo "  → cStat: {$e->cStat}\n";
    echo "  → xMotivo: {$e->xMotivo}\n";
    if ($e->rawResponse !== null) {
        $logFile = '/tmp/manifestacao-erro-' . date('YmdHis') . '.json';
        file_put_contents($logFile, $e->rawResponse);
        echo "  → Resposta crua em {$logFile}\n";
    }
    exit(2);
}
