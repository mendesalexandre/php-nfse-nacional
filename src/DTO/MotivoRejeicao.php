<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

/**
 * Códigos de motivo aceitos pelo SEFIN no evento de Rejeição de NFS-e
 * (cMotivo do TCInfoEventoRejeicao).
 *
 *   1 — NFS-e em duplicidade
 *   2 — NFS-e já emitida pelo tomador
 *   3 — Não ocorrência do fato gerador
 *   4 — Erro quanto à responsabilidade tributária
 *   5 — Erro quanto ao valor do serviço, deduções, serviço prestado ou data
 *   9 — Outros (exige `xMotivo` descrevendo)
 */
enum MotivoRejeicao: int
{
    case Duplicidade           = 1;
    case JaEmitidaPeloTomador  = 2;
    case SemFatoGerador        = 3;
    case ErroResponsabilidade  = 4;
    case ErroValorOuData       = 5;
    case Outros                = 9;

    public function label(): string
    {
        return match ($this) {
            self::Duplicidade           => 'NFS-e em duplicidade',
            self::JaEmitidaPeloTomador  => 'NFS-e já emitida pelo tomador',
            self::SemFatoGerador        => 'Não ocorrência do fato gerador',
            self::ErroResponsabilidade  => 'Erro quanto à responsabilidade tributária',
            self::ErroValorOuData       => 'Erro quanto ao valor do serviço, deduções, serviço prestado ou data do fato gerador',
            self::Outros                => 'Outros',
        };
    }

    /**
     * Quando true, o `xMotivo` (descrição livre) é obrigatório no evento.
     * Validado pelo SEFIN/ADN com cStat=1944/1949/1954.
     */
    public function exigeXMotivo(): bool
    {
        return $this === self::Outros;
    }
}
