<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

use PhpNfseNacional\Enums\CStat;

/**
 * Resposta normalizada do Portal Nacional após envio do DPS.
 *
 * Tipo readonly — quem usar o SDK recebe uma instância imutável.
 *
 * Campos esperados no retorno do SEFIN (decodificado do gzip+base64):
 *   - chave_acesso: 50 dígitos (NFS-e válida) ou null (rejeitado)
 *   - cStat: 100=Emitida, 101=Cancelada, 102=Cancel substituição, 135/155=Evento ok
 *   - xMotivo: mensagem amigável do portal
 *   - protocolo: id da operação no SEFIN
 *   - xml_retorno: XML bruto recebido (pra debug / s3)
 */
final class SefinResposta
{
    public function __construct(
        public readonly ?string $chaveAcesso,
        public readonly ?int $cStat,
        public readonly ?string $xMotivo,
        public readonly ?string $protocolo,
        public readonly ?string $numeroNfse,
        public readonly ?string $codigoVerificacao,
        public readonly ?string $dataProcessamento,
        public readonly ?string $xmlRetorno,
        public readonly string $rawResponse,
    ) {}

    public function emitida(): bool
    {
        return $this->cStat === CStat::Emitida->value && $this->chaveAcesso !== null;
    }

    /**
     * NFS-e cancelada (estado final no SEFIN). Use após consultar a NFS-e
     * (não após enviar o evento — esse caso é tratado em CancelamentoService).
     */
    public function cancelada(): bool
    {
        return in_array($this->cStat, CStat::estadosCancelada(), true);
    }

    public function erro(): bool
    {
        return !$this->emitida() && !$this->cancelada();
    }

    /**
     * Operação foi tratada como idempotente — o evento já estava vinculado
     * à NFS-e antes desta tentativa. Útil pra distinguir "cancelei agora"
     * de "já estava cancelada antes" na resposta de
     * `CancelamentoService::cancelar` ou `SubstituicaoService::substituir`.
     */
    public function eventoIdempotente(): bool
    {
        return $this->cStat === CStat::EventoVinculado->value;
    }

    /**
     * Devolve o cStat tipado quando o código é conhecido pelo SDK; null
     * quando é um cStat fora da lista enumerada (qualquer erro novo do
     * SEFIN/ADN). Use pra log/análise:
     *
     *   $stat = $resp->cStatTipado();
     *   if ($stat?->ehErroSchema()) { ... }
     */
    public function cStatTipado(): ?CStat
    {
        return $this->cStat !== null ? CStat::tryFrom($this->cStat) : null;
    }
}
