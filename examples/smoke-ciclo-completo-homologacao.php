<?php

declare(strict_types=1);

/**
 * Smoke ponta-a-ponta — emite, baixa (XML + PDF) e cancela uma NFS-e
 * em HOMOLOGAÇÃO. Exercita a v0.11.x completa:
 *
 *   1. emitir() — gera NFS-e
 *   2. baixarXml() — XML autorizado
 *   3. baixarPdf() — PDF via ADN (com retry exponencial em 502/503/504)
 *   4. cancelar() — evento e101101
 *   5. consultar() — confirma status final cancelado
 *
 * Uso (mesmo env do emitir-homologacao.php):
 *   OPENSSL_CONF=... PFX_PATH=... PFX_SENHA=... PRESTADOR_* \
 *     php examples/smoke-ciclo-completo-homologacao.php
 */

/** @var \PhpNfseNacional\NFSe $nfse */
$nfse = require __DIR__ . '/_bootstrap.php';

use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\DTO\Servico;
use PhpNfseNacional\DTO\Tomador;
use PhpNfseNacional\DTO\Valores;

$timestamp = date('YmdHis');

// ════════════════════════════════════════════════════════════════════
// 1. EMITIR
// ════════════════════════════════════════════════════════════════════

echo "=== [1] EMITIR ===\n";

$tomador = new Tomador(
    documento: envOrDie('PRESTADOR_CMUN') === '5107909' ? '44208855134' : '12345678909',
    nome: 'TOMADOR SMOKE CICLO COMPLETO',
    endereco: new Endereco(
        logradouro: 'Rua Teste',
        numero: '100',
        bairro: 'Centro',
        cep: envOrDie('PRESTADOR_CEP'),
        codigoMunicipioIbge: envOrDie('PRESTADOR_CMUN'),
        uf: envOrDie('PRESTADOR_UF'),
    ),
);

$servico = new Servico(
    discriminacao: 'SMOKE CICLO COMPLETO — ' . date('Y-m-d H:i:s'),
    codigoMunicipioPrestacao: envOrDie('PRESTADOR_CMUN'),
);

$valores = new Valores(
    valorServicos: 100.00,
    deducoesReducoes: 20.00,
    aliquotaIssqnPercentual: 4.00,
);

$id = new Identificacao(numeroDps: (int) (getenv('NDPS') ?: time() % 1_000_000), serie: '1');

try {
    $resp = $nfse->emitir($id, $tomador, $servico, $valores);
} catch (\Throwable $e) {
    echo "✗ EMISSÃO FALHOU: " . $e->getMessage() . "\n";
    exit(2);
}

$chave = $resp->chaveAcesso;
if ($chave === null) {
    echo "✗ Emissão sem chave\n";
    exit(2);
}

echo "✓ NFS-e {$resp->numeroNfse} emitida (cStat={$resp->cStat})\n";
echo "  chave: {$chave}\n\n";

// ════════════════════════════════════════════════════════════════════
// 2. BAIXAR XML
// ════════════════════════════════════════════════════════════════════

echo "=== [2] BAIXAR XML ===\n";

try {
    $xml = $nfse->baixarXml($chave);
    $path = "/tmp/ciclo-{$timestamp}.xml";
    file_put_contents($path, $xml);
    echo "✓ XML salvo em {$path} (" . strlen($xml) . " bytes)\n\n";
} catch (\Throwable $e) {
    echo "✗ Download XML falhou: " . $e->getMessage() . "\n";
    exit(3);
}

// ════════════════════════════════════════════════════════════════════
// 3. BAIXAR PDF (com retry exponencial v0.11.0+)
// ════════════════════════════════════════════════════════════════════

echo "=== [3] BAIXAR PDF (ADN, com retry exponencial) ===\n";

try {
    $pdf = $nfse->baixarPdf($chave, tentativas: 3);
    $pdfPath = "/tmp/ciclo-{$timestamp}.pdf";
    file_put_contents($pdfPath, $pdf);
    echo "✓ PDF salvo em {$pdfPath} (" . strlen($pdf) . " bytes)\n\n";
} catch (\Throwable $e) {
    echo "✗ Download PDF (ADN) falhou: " . $e->getMessage() . "\n";
    echo "  Dica: o endpoint ADN é instável. Fallback é gerar local via danfseLocal()\n\n";

    // Tenta fallback local
    try {
        $pdfLocal = $nfse->danfseLocal($xml);
        $pdfPath = "/tmp/ciclo-{$timestamp}-local.pdf";
        file_put_contents($pdfPath, $pdfLocal);
        echo "✓ PDF gerado localmente em {$pdfPath} (" . strlen($pdfLocal) . " bytes)\n\n";
    } catch (\Throwable $e2) {
        echo "✗ Geração local também falhou: " . $e2->getMessage() . "\n";
        exit(4);
    }
}

// ════════════════════════════════════════════════════════════════════
// 4. CANCELAR
// ════════════════════════════════════════════════════════════════════

echo "=== [4] CANCELAR (e101101) ===\n";

try {
    $respCanc = $nfse->cancelar(
        chaveAcesso: $chave,
        motivo: MotivoCancelamento::ErroEmissao,
        justificativa: 'SMOKE de ciclo completo - cancelamento de teste em homologacao',
    );
    echo "✓ Cancelada (cStat={$respCanc->cStat})\n";
    if ($respCanc->cStat === 840) {
        echo "  (cStat 840 = idempotente, já estava cancelada)\n";
    }
    echo "\n";
} catch (\Throwable $e) {
    echo "✗ Cancelamento falhou: " . $e->getMessage() . "\n";
    exit(5);
}

// ════════════════════════════════════════════════════════════════════
// 5. CONFIRMAR STATUS FINAL
// ════════════════════════════════════════════════════════════════════

echo "=== [5] CONFIRMAR STATUS FINAL (via eventos) ===\n";

try {
    // IMPORTANTE: `consultar()->cancelada()` NÃO detecta cancelamento —
    // a consulta retorna cStat=100 (autorizada) mesmo após cancelar,
    // porque o cancelamento é evento separado, não muda o cStat.
    // Forma correta: `estaCancelada()` (via /contribuintes/NFSe/{chave}/Eventos)
    // ou via `sincronizarDfe()->foiCancelada($chave)` (lote do CNPJ).
    $cancelada = $nfse->estaCancelada($chave);
    echo "  estaCancelada(): " . ($cancelada ? 'true' : 'false') . "\n";

    if ($cancelada) {
        echo "\n✓ CICLO COMPLETO OK — NFS-e emitida, baixada e cancelada com sucesso\n";
    } else {
        echo "\n⚠ Cancelamento não confirmado (evento pode estar em processamento)\n";
    }
} catch (\Throwable $e) {
    echo "✗ Verificação final falhou: " . $e->getMessage() . "\n";
    exit(6);
}
