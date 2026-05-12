<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\Sefin;

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
        return $this->cStat === 100 && $this->chaveAcesso !== null;
    }

    public function cancelada(): bool
    {
        return in_array($this->cStat, [101, 102, 135, 155], true);
    }

    public function erro(): bool
    {
        return !$this->emitida() && !$this->cancelada();
    }
}
