<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use PhpNfseNacional\Danfse\DanfseCustomizacao;
use PhpNfseNacional\Danfse\DanfseDados;
use PhpNfseNacional\Danfse\DanfseGenerator;
use PhpNfseNacional\Danfse\DanfseXmlParser;

/**
 * Geração local do DANFSE (PDF) a partir do XML autorizado pelo SEFIN.
 *
 * Alternativa ao download via ADN ({@see DownloadService::pdfDanfse}) — útil
 * quando:
 *   - O ambiente do ADN está indisponível
 *   - Há necessidade de customizar o layout (logo do emitente, observações)
 *   - Quer-se evitar dependência operacional do portal pra gerar o PDF
 *
 * Layout segue NT 008/2026 (blocos: cabeçalho + prestador + tomador +
 * serviço + valores + tributos + QR Code + rodapé).
 *
 * Uso típico:
 *   $xml = $nfse->download()->xmlNfse($chave);   // ou usar o retorno de emitir()
 *   $pdf = $nfse->danfse()->gerarDoXml($xml);
 *   file_put_contents('danfse.pdf', $pdf);
 */
final class DanfseService
{
    public function __construct(
        private readonly DanfseXmlParser $parser = new DanfseXmlParser(),
        private readonly DanfseGenerator $generator = new DanfseGenerator(),
    ) {}

    /**
     * Gera o PDF do DANFSE a partir do XML autorizado pelo SEFIN.
     *
     * @return string Bytes do PDF
     */
    public function gerarDoXml(string $xmlNfse, ?DanfseCustomizacao $custom = null): string
    {
        $dados = $this->parser->parse($xmlNfse);
        return $this->generator->gerar($dados, $custom);
    }

    /**
     * Variante low-level — gera o PDF a partir de DTOs já parseados.
     * Permite ao usuário do SDK customizar dados antes da renderização.
     */
    public function gerarDeDados(DanfseDados $dados, ?DanfseCustomizacao $custom = null): string
    {
        return $this->generator->gerar($dados, $custom);
    }

    /**
     * Acesso ao parser pra quem quer só extrair dados sem renderizar.
     */
    public function parser(): DanfseXmlParser
    {
        return $this->parser;
    }
}
