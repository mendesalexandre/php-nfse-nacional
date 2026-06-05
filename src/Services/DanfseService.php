<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use PhpNfseNacional\Danfse\DanfseCustomizacao;
use PhpNfseNacional\Danfse\DanfseDados;
use PhpNfseNacional\Danfse\DanfseGenerator;
use PhpNfseNacional\Danfse\DanfseXmlParser;
use PhpNfseNacional\Danfse\Layouts\DanfseLayoutStrategy;
use PhpNfseNacional\Danfse\Layouts\DanfseLayoutV1;
use PhpNfseNacional\Danfse\Layouts\DanfseLayoutV2;
use PhpNfseNacional\Enums\DanfseVersao;

/**
 * Geração local do DANFSE (PDF) a partir do XML autorizado pelo SEFIN.
 *
 * Alternativa ao download via ADN ({@see DownloadService::pdfDanfse}) — útil
 * quando:
 *   - O ambiente do ADN está indisponível
 *   - Há necessidade de customizar o layout (logo do emitente, observações)
 *   - Quer-se evitar dependência operacional do portal pra gerar o PDF
 *
 * **Versões de leiaute disponíveis**:
 *   - `DanfseVersao::V2` (default) — NT 008/2026 (SE/CGNFS-e v1.0). Novo
 *     leiaute padronizado. Em refino.
 *   - `DanfseVersao::V1` — leiaute legado do ADN. Útil para preservar
 *     identidade visual após desativação do ADN em 01/07/2026 ou como
 *     fallback quando o ADN está instável.
 *
 * Uso típico:
 *   $xml = $nfse->baixarXml($chave);
 *   $pdf = $nfse->danfseLocal($xml);                              // V2 default
 *   $pdf = $nfse->danfseLocal($xml, versao: DanfseVersao::V1);    // legado ADN
 */
final class DanfseService
{
    public function __construct(
        private readonly DanfseXmlParser $parser = new DanfseXmlParser(),
    ) {}

    /**
     * Gera o PDF do DANFSE a partir do XML autorizado pelo SEFIN.
     *
     * @return string Bytes do PDF
     */
    public function gerarDoXml(
        string $xmlNfse,
        ?DanfseCustomizacao $custom = null,
        DanfseVersao $versao = DanfseVersao::V2,
    ): string {
        $dados = $this->parser->parse($xmlNfse);
        return $this->gerarDeDados($dados, $custom, $versao);
    }

    /**
     * Variante low-level — gera o PDF a partir de DTOs já parseados.
     * Permite ao usuário do SDK customizar dados antes da renderização.
     */
    public function gerarDeDados(
        DanfseDados $dados,
        ?DanfseCustomizacao $custom = null,
        DanfseVersao $versao = DanfseVersao::V2,
    ): string {
        return (new DanfseGenerator($this->layoutPara($versao)))->gerar($dados, $custom);
    }

    /**
     * Acesso ao parser pra quem quer só extrair dados sem renderizar.
     */
    public function parser(): DanfseXmlParser
    {
        return $this->parser;
    }

    private function layoutPara(DanfseVersao $versao): DanfseLayoutStrategy
    {
        return match ($versao) {
            DanfseVersao::V1 => new DanfseLayoutV1(),
            DanfseVersao::V2 => new DanfseLayoutV2(),
        };
    }
}
