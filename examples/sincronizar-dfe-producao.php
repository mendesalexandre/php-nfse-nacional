<?php

declare(strict_types=1);

/**
 * Sincroniza DFes (caixa postal do CNPJ) em PRODUÇÃO e lista as NFS-e
 * EMITIDAS sob o CNPJ, ordenadas por número (nNFSe).
 *
 * Diferente do smoke de homologação, este decodifica o XML embutido de
 * cada item (arquivoXmlDecodificado) e extrai nNFSe + dhEmi, pra você
 * localizar uma nota específica pelo NÚMERO (ex.: achar uma nota órfã /
 * gap na sequência local).
 *
 * Uso:
 *   OPENSSL_CONF=/caminho/openssl-sha1.cnf \
 *   AMBIENTE=producao \
 *   PFX_PATH=/caminho/cert.pfx PFX_SENHA=... \
 *   PRESTADOR_CNPJ=00179028000138 PRESTADOR_IM=... PRESTADOR_RAZAO=... \
 *   PRESTADOR_LOGRADOURO=... PRESTADOR_NUMERO=... PRESTADOR_BAIRRO=... \
 *   PRESTADOR_CEP=... PRESTADOR_CMUN=5107909 PRESTADOR_UF=MT \
 *   NSU=0 ALVO_NNFSE=559 \
 *     php examples/sincronizar-dfe-producao.php
 *
 * - NSU: NSU inicial (default 0 = desde o começo). Persista o ultimoNsu
 *   entre execuções pra sincronização incremental.
 * - ALVO_NNFSE: número de NFS-e a destacar/salvar (opcional).
 */

/** @var \PhpNfseNacional\NFSe $nfse */
$nfse = require __DIR__ . '/_bootstrap.php';

$ultimoNsu = (int) (getenv('NSU') ?: 0);
$alvo = getenv('ALVO_NNFSE') !== false && getenv('ALVO_NNFSE') !== ''
    ? (int) getenv('ALVO_NNFSE')
    : null;

/** Extrai o nNFSe do XML cru da NFS-e. */
function extrairNumeroNfse(?string $xml): ?int
{
    if ($xml === null || $xml === '') {
        return null;
    }
    if (preg_match('/<nNFSe>(\d+)<\/nNFSe>/', $xml, $m)) {
        return (int) $m[1];
    }
    return null;
}

/** Extrai o dhEmi/dhProc do XML cru, pra contexto temporal. */
function extrairDataEmissao(?string $xml): ?string
{
    if ($xml === null || $xml === '') {
        return null;
    }
    if (preg_match('/<(?:dhProc|dhEmi)>([^<]+)<\/(?:dhProc|dhEmi)>/', $xml, $m)) {
        return $m[1];
    }
    return null;
}

echo "=== Sincronização DFe PRODUÇÃO (NSU inicial={$ultimoNsu}) ===\n";
if ($alvo !== null) {
    echo "Alvo: NFS-e nº {$alvo}\n";
}
echo "\n";

// Paginação manual com throttle: o ADN de produção rate-limita (HTTP 429)
// se as páginas vierem rápido demais. Buscamos 1 página por vez (maxPaginas=1
// = 50 itens), com pausa entre chamadas, acumulando até esvaziar / achar alvo.
$pausaSeg = (float) (getenv('PAUSA_SEG') ?: 2);
$maxPaginasTotal = (int) (getenv('MAX_PAGINAS') ?: 200);

try {
    $linhas = [];
    $alvoXml = null;
    $alvoChave = null;
    $nsu = $ultimoNsu;
    $statusFinal = null;
    $pagina = 0;

    while ($pagina < $maxPaginasTotal) {
        $pagina++;
        $tentativa = 0;
        retry:
        try {
            $resp = $nfse->sincronizarDfe($nsu, 1);
        } catch (\PhpNfseNacional\Exceptions\SefinException $e) {
            // 429 → backoff e tenta de novo (até 5x)
            if (str_contains($e->getMessage(), '429') && $tentativa < 5) {
                $tentativa++;
                $espera = $pausaSeg * (1 + $tentativa);
                echo "  [pág {$pagina}] HTTP 429 — aguardando {$espera}s (tentativa {$tentativa})\n";
                usleep((int) ($espera * 1_000_000));
                goto retry;
            }
            throw $e;
        }

        $statusFinal = $resp->statusProcessamento;
        if ($resp->vazio()) {
            echo "  [pág {$pagina}] vazia — fim da caixa postal (NSU={$nsu}).\n";
            break;
        }

        foreach ($resp->itens as $item) {
            $xml = $item->arquivoXmlDecodificado();
            $num = extrairNumeroNfse($xml);
            $linhas[] = [
                'nsu' => $item->nsu,
                'tipo' => $item->tipoDocumento,
                'evento' => $item->tipoEvento,
                'nnfse' => $num,
                'chave' => $item->chaveAcesso,
                'dh' => extrairDataEmissao($xml) ?? $item->dataHora,
            ];
            if ($alvo !== null && $num === $alvo) {
                $alvoXml = $xml;
                $alvoChave = $item->chaveAcesso;
            }
        }

        echo "  [pág {$pagina}] +{$resp->quantidade()} itens (total " . count($linhas) . "), NSU={$resp->ultimoNsu}"
            . ($alvoXml !== null ? "  *** ALVO {$alvo} ENCONTRADO ***" : '') . "\n";

        if ($alvoXml !== null) {
            break; // achou o alvo, não precisa varrer o resto
        }

        $nsu = $resp->ultimoNsu;
        usleep((int) ($pausaSeg * 1_000_000));
    }

    echo "\nStatus final: " . ($statusFinal ?? '?') . " | itens coletados: " . count($linhas) . " | último NSU: {$nsu}\n\n";

    if ($linhas === []) {
        echo "Caixa postal vazia desde NSU={$ultimoNsu}.\n";
        exit(0);
    }

    // Ordena por nNFSe (notas sem número — eventos — vão pro fim).
    usort($linhas, fn ($a, $b) => ($a['nnfse'] ?? PHP_INT_MAX) <=> ($b['nnfse'] ?? PHP_INT_MAX));

    echo str_pad('nNFSe', 8) . str_pad('tipo', 8) . str_pad('evento', 22) . str_pad('NSU', 8) . "dh / chave\n";
    echo str_repeat('-', 100) . "\n";
    foreach ($linhas as $l) {
        $marca = ($alvo !== null && $l['nnfse'] === $alvo) ? ' <<< ALVO' : '';
        echo str_pad((string) ($l['nnfse'] ?? '-'), 8)
            . str_pad((string) ($l['tipo'] ?? '-'), 8)
            . str_pad((string) ($l['evento'] ?? '-'), 22)
            . str_pad((string) $l['nsu'], 8)
            . ($l['dh'] ?? '-') . '  ' . ($l['chave'] ?? '-')
            . $marca . "\n";
    }

    // Salva tudo cru + XMLs decodificados pra auditoria.
    $ts = date('YmdHis');
    file_put_contents("/tmp/dfe-prod-{$ts}.json", json_encode([
        'ultimoNsu' => $nsu,
        'status' => $statusFinal,
        'linhas' => $linhas,
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    echo "\nResumo salvo em /tmp/dfe-prod-{$ts}.json\n";

    if ($alvo !== null) {
        if ($alvoXml !== null) {
            $f = "/tmp/nfse-{$alvo}-{$ts}.xml";
            file_put_contents($f, $alvoXml);
            echo "\n✓ NFS-e {$alvo} ENCONTRADA. chave={$alvoChave}\n";
            echo "  XML salvo em {$f}\n";
        } else {
            echo "\n✗ NFS-e {$alvo} NÃO encontrada até NSU={$nsu}. Se não esvaziou, rode de novo com NSU={$nsu}.\n";
        }
    }

} catch (\Throwable $e) {
    echo "✗ ERRO: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(2);
}
