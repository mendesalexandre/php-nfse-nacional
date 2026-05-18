<?php

declare(strict_types=1);

/**
 * Smoke — sincroniza DFes (caixa postal do CNPJ) em HOMOLOGAÇÃO.
 *
 * Lista todas as NFS-es emitidas CONTRA o CNPJ do cert (como tomador),
 * cancelamentos recebidos, substituições, etc. Sincronização incremental
 * por NSU.
 *
 * Uso (mesmo env do emitir-homologacao.php — só PFX é estritamente
 * necessário aqui, prestador é só pra contexto/logging):
 *   OPENSSL_CONF=... PFX_PATH=... PFX_SENHA=... PRESTADOR_* \
 *     NSU=0 \
 *     php examples/sincronizar-dfe-homologacao.php
 *
 * Parâmetro NSU (env): NSU desde o qual sincronizar. Default 0 (do começo).
 * Em produção real, persistir o `$resp->ultimoNsu` da última chamada
 * bem-sucedida e usar na próxima.
 */

/** @var \PhpNfseNacional\NFSe $nfse */
$nfse = require __DIR__ . '/_bootstrap.php';

$ultimoNsu = (int) (getenv('NSU') ?: 0);

echo "Sincronizando DFe a partir de NSU={$ultimoNsu}...\n\n";

try {
    $resp = $nfse->sincronizarDfe($ultimoNsu);

    echo "Status: " . ($resp->statusProcessamento ?? '?') . "\n";
    echo "Itens recebidos: {$resp->quantidade()}\n";
    echo "Último NSU consumido: {$resp->ultimoNsu}\n";
    echo "Tem mais pendente? " . ($resp->temMais ? 'sim — chamar de novo' : 'não') . "\n";
    echo "\n";

    if ($resp->vazio()) {
        echo "Caixa postal vazia. Nenhum DFe novo desde NSU={$ultimoNsu}.\n";
        echo "(Se você não recebeu NFS-e como tomador no SEFIN homologação,\n";
        echo " a caixa vai ficar vazia mesmo. Isso é esperado.)\n";
    } else {
        echo "DFes recebidos:\n";
        foreach ($resp->itens as $i => $item) {
            $n = $i + 1;
            echo "  [{$n}] NSU={$item->nsu}";
            if ($item->tipoDocumento) {
                echo " | tipo={$item->tipoDocumento}";
            }
            if ($item->chaveAcesso) {
                echo " | chave={$item->chaveAcesso}";
            }
            if ($item->tipoEvento) {
                echo " | evento={$item->tipoEvento}";
            }
            if ($item->sequencialEvento) {
                echo " seq={$item->sequencialEvento}";
            }
            if ($item->dataHora) {
                echo " | dh={$item->dataHora}";
            }
            echo "\n";
        }
        echo "\nPersistir o NSU {$resp->ultimoNsu} pra próxima sincronização.\n";
    }

    // Salva resposta crua pra inspeção
    $timestamp = date('YmdHis');
    file_put_contents(
        "/tmp/dfe-sync-{$timestamp}.json",
        json_encode([
            'ultimoNsu' => $resp->ultimoNsu,
            'statusProcessamento' => $resp->statusProcessamento,
            'temMais' => $resp->temMais,
            'quantidade' => $resp->quantidade(),
            'itens' => array_map(
                fn ($item) => $item->bruto,
                $resp->itens,
            ),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
    );
    echo "\nResposta crua salva em /tmp/dfe-sync-{$timestamp}.json\n";

} catch (\Throwable $e) {
    echo "✗ ERRO: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(2);
}
