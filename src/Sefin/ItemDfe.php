<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

/**
 * Item individual do lote DFe (caixa postal de eventos do SEFIN).
 *
 * Cada DFe representa um documento eletrônico vinculado ao CNPJ do
 * emissor — uma NFS-e nova recebida (quando alguém emite contra ele),
 * um cancelamento, uma substituição, etc.
 *
 * Estrutura validada empiricamente contra ADN homologação (18/mai/2026,
 * cartório de Sinop, 162 DFes consumidos).
 */
final class ItemDfe
{
    public function __construct(
        /** Número Sequencial Único — incremental no SEFIN, usado pra paginação. */
        public readonly int $nsu,
        /**
         * Tipo do documento. Valores empíricos vistos: `"NFSE"`, `"EVENTO"`.
         */
        public readonly ?string $tipoDocumento,
        /** Chave de acesso do documento (50 dígitos quando aplicável). */
        public readonly ?string $chaveAcesso,
        /**
         * Tipo de evento — STRING DESCRITIVA, não código numérico.
         * Valores empíricos vistos: `"CANCELAMENTO"`, `"CONFIRMACAO_PRESTADOR"`,
         * `"REJEICAO_PRESTADOR"`, `"SUBSTITUICAO"`, `"ANULACAO_REJEICAO"`.
         * Null quando `tipoDocumento === "NFSE"` (NFS-e original, não evento).
         */
        public readonly ?string $tipoEvento,
        /** Número sequencial do evento (1..99). Pode não vir na resposta. */
        public readonly ?int $sequencialEvento,
        /**
         * Data/hora da geração do DFe (ISO 8601 com milissegundos).
         * Campo vem como `DataHoraGeracao` na resposta do ADN.
         */
        public readonly ?string $dataHora,
        /**
         * XML do documento embutido (gzip+base64). Acesso direto sem
         * precisar chamar `baixarXml(chave)` separadamente — economia de
         * uma round-trip HTTP por DFe. Use `arquivoXmlDecodificado()`
         * pra obter o XML cru.
         */
        public readonly ?string $arquivoXmlGzipB64,
        /**
         * Payload bruto do item (útil pra inspeção).
         *
         * @var array<string, mixed>
         */
        public readonly array $bruto,
    ) {}

    /**
     * Descomprime e decodifica o `arquivoXmlGzipB64` retornando o XML cru.
     * Retorna null se o campo não veio na resposta (ou falha na descompressão).
     */
    public function arquivoXmlDecodificado(): ?string
    {
        if ($this->arquivoXmlGzipB64 === null || $this->arquivoXmlGzipB64 === '') {
            return null;
        }
        $decoded = base64_decode($this->arquivoXmlGzipB64, true);
        if ($decoded === false) {
            return null;
        }
        $gz = @gzdecode($decoded);
        return $gz !== false ? $gz : null;
    }
}
