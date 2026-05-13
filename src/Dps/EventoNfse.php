<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

/**
 * Contrato pra eventos vinculados a uma NFS-e (cancelamento, substituição,
 * carta de correção, etc.).
 *
 * Cada tipo de evento implementa essa interface com seus campos específicos.
 * O `EventoBuilder` monta o XML genericamente, sem hardcode de nenhum tipo.
 *
 * Códigos de evento conforme leiaute SefinNacional 1.6 (TS_CodEvento):
 *   - 101101 → Cancelamento de NFS-e
 *   - 105102 → Cancelamento por substituição
 *   - 101103 → Solicitação de Análise Fiscal pra Cancelamento
 *   - 105104 / 105105 → Cancelamento Deferido / Indeferido por Análise Fiscal
 *   - 202201 / 203202 / 204203 → Confirmação (Prestador/Tomador/Intermediário)
 *   - 205204 → Confirmação Tácita (gerado automaticamente pelo sistema)
 *   - 202205 / 203206 / 204207 → Rejeição (Prestador/Tomador/Intermediário)
 *   - 205208 → Anulação da Rejeição
 *   - 305101 / 305102 / 305103 → Cancelamento / Bloqueio / Desbloqueio por Ofício
 *
 * Pra adicionar um evento novo, basta implementar essa interface e usar o
 * mesmo EventoBuilder. Sem mudança no SDK.
 */
interface EventoNfse
{
    /**
     * Chave de acesso (50 dígitos) da NFS-e à qual o evento se refere.
     */
    public function chaveAcesso(): string;

    /**
     * Código do tipo de evento (6 dígitos do leiaute).
     * Ex: '101101' pra cancelamento.
     */
    public function codigoTipoEvento(): string;

    /**
     * Número sequencial do evento — geralmente 1 (primeira tentativa).
     * Incrementado em retentativas/correções de evento anterior.
     */
    public function nSequencial(): int;

    /**
     * Texto livre que descreve o evento (vai em <xDesc>).
     * Ex: 'Cancelamento de NFS-e'.
     */
    public function descricao(): string;

    /**
     * Campos específicos do grupo do evento, aplicados como child elements
     * do nó <e{codigoTipoEvento}>.
     *
     * Pra cancelamento: ['cMotivo' => '1', 'xMotivo' => 'justificativa...'].
     * Pra outros eventos: campos próprios do leiaute.
     *
     * @return array<string, string>
     */
    public function camposGrupo(): array;
}
