<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

/**
 * Item individual do lote DFe (caixa postal de eventos do SEFIN).
 *
 * Cada DFe representa um documento eletrônico vinculado ao CNPJ do
 * emissor — uma NFS-e nova recebida (quando alguém emite contra ele),
 * um cancelamento, uma substituição, etc.
 */
final class ItemDfe
{
    public function __construct(
        /** Número Sequencial Único — incremental no SEFIN, usado pra paginação. */
        public readonly int $nsu,
        /**
         * Tipo do documento (NFS-e, Evento, etc.). Conforme tabela do
         * leiaute ADN — quando conhecido, mapeia para enum; quando não,
         * fica como string crua.
         */
        public readonly ?string $tipoDocumento,
        /** Chave de acesso do documento (50 dígitos quando aplicável). */
        public readonly ?string $chaveAcesso,
        /** Tipo de evento (`101101`, `105102`, etc.) quando aplicável. */
        public readonly ?string $tipoEvento,
        /** Número sequencial do evento (1..99). */
        public readonly ?int $sequencialEvento,
        /** Data/hora do registro do DFe (ISO 8601). */
        public readonly ?string $dataHora,
        /**
         * Payload bruto do item (útil pra inspeção).
         *
         * @var array<string, mixed>
         */
        public readonly array $bruto,
    ) {}
}
