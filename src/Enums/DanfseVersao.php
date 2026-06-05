<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Versão do leiaute visual da DANFSe local.
 *
 * - V1: layout que o ADN/SEFIN renderiza hoje em `/danfse/{chave}`. Pré-NT 008.
 *   Útil para preservar identidade visual após a desativação do ADN
 *   (prevista para 01/07/2026) ou como fallback quando o ADN está instável.
 * - V2: layout padronizado pela **NT 008/2026** (SE/CGNFS-e v1.0). Novo formato
 *   oficial. Default da lib.
 *
 * **Aviso**: o renderizador local (ambas as versões) ainda está em refino. Em
 * produção, prefira `$nfse->baixarPdf($chave)` (PDF gerado pelo SEFIN/ADN)
 * enquanto o endpoint estiver disponível.
 */
enum DanfseVersao: string
{
    case V1 = 'v1.0';
    case V2 = 'v2.0';
}
