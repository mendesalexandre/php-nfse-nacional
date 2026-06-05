<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse\Layouts;

use PhpNfseNacional\Danfse\DanfseCustomizacao;
use PhpNfseNacional\Danfse\DanfseDados;
use PhpNfseNacional\Enums\DanfseVersao;
use TCPDF;

/**
 * Estratégia de renderização visual da DANFSe.
 *
 * Implementações são responsáveis por desenhar os blocos de conteúdo no
 * `TCPDF` já configurado (margens, página, fonte default). Não criam o PDF
 * nem fazem `Output()` — o `DanfseGenerator` orquestra esses passos comuns
 * (setup, marcas d'água de cancelada/substituída, output).
 */
interface DanfseLayoutStrategy
{
    /**
     * Renderiza todos os blocos de conteúdo da DANFSe no PDF recebido.
     *
     * @param DanfseDados          $dados   Dados parseados do XML autorizado.
     * @param TCPDF                $pdf     Instância TCPDF já com a página aberta.
     * @param DanfseCustomizacao|null $custom Customizações opcionais (logo, observações).
     */
    public function renderizar(DanfseDados $dados, TCPDF $pdf, ?DanfseCustomizacao $custom = null): void;

    /** Identifica a versão renderizada (usada em metadados e selects). */
    public function versao(): DanfseVersao;
}
