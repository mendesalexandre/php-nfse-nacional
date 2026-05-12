<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

/**
 * Snapshot imutável dos dados extraídos do XML da NFS-e, prontos pra
 * renderização do DANFSE.
 *
 * Os campos seguem a estrutura visual da NT 008/2026 (blocos:
 * identificação, prestador, tomador, serviço, valores, tributos, QR).
 */
final class DanfseDados
{
    /**
     * @param array<string, string|null> $identificacao Chave, número, datas, código de verificação
     * @param array<string, string|null> $prestador     CNPJ, IM, razão social, endereço, contatos
     * @param array<string, string|null> $tomador       Documento (CPF/CNPJ), nome, endereço, contatos
     * @param array<string, string|null> $servico       cTribNac, descrição, NBS, local prestação
     * @param array<string, float|null> $valores Valores monetários e alíquotas
     * @param array<string, string|null> $tributos      Outras informações tributárias
     * @param string|null $qrCodeUrl                    URL pra QR Code (consulta Portal)
     * @param bool $cancelada                           Status — true se cStat=101/102
     */
    public function __construct(
        public readonly array $identificacao,
        public readonly array $prestador,
        public readonly array $tomador,
        public readonly array $servico,
        public readonly array $valores,
        public readonly array $tributos,
        public readonly ?string $qrCodeUrl,
        public readonly bool $cancelada,
    ) {}

    public function chave(): ?string
    {
        return $this->identificacao['chave'] ?? null;
    }

    public function numero(): ?string
    {
        return $this->identificacao['numero'] ?? null;
    }
}
