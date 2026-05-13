<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

/**
 * Códigos de justificativa pra evento de Substituição de NFS-e (e105102).
 *
 * Tipo do leiaute: `TSCodJustSubst` — DIFERENTE de `TSCodJustCanc` (cancelamento).
 *
 *   01 — Desenquadramento de NFS-e do Simples Nacional
 *   02 — Enquadramento de NFS-e no Simples Nacional
 *   03 — Inclusão Retroativa de Imunidade/Isenção pra NFS-e
 *   04 — Exclusão Retroativa de Imunidade/Isenção pra NFS-e
 *   05 — Rejeição de NFS-e pelo tomador ou intermediário (responsável pelo recolhimento)
 *   99 — Outros (exige `xMotivo` descritivo)
 *
 * NÃO confunda com `MotivoCancelamento` (1=ErroEmissao, 2=ServicoNaoPrestado,
 * 9=Outros) — usado SÓ no e101101. Substituição (e105102) tem semântica
 * diferente porque não se trata de erro do prestador, mas de mudança de
 * situação tributária ou rejeição do destinatário.
 */
enum MotivoSubstituicao: int
{
    case DesenquadramentoSimples = 1;
    case EnquadramentoSimples    = 2;
    case InclusaoImunidade       = 3;
    case ExclusaoImunidade       = 4;
    case RejeicaoTomador         = 5;
    case Outros                  = 99;

    public function label(): string
    {
        return match ($this) {
            self::DesenquadramentoSimples => 'Desenquadramento de NFS-e do Simples Nacional',
            self::EnquadramentoSimples    => 'Enquadramento de NFS-e no Simples Nacional',
            self::InclusaoImunidade       => 'Inclusão Retroativa de Imunidade/Isenção para NFS-e',
            self::ExclusaoImunidade       => 'Exclusão Retroativa de Imunidade/Isenção para NFS-e',
            self::RejeicaoTomador         => 'Rejeição de NFS-e pelo tomador ou intermediário',
            self::Outros                  => 'Outros',
        };
    }

    /**
     * Quando true, o `xMotivo` (descrição livre) é obrigatório no evento.
     */
    public function exigeXMotivo(): bool
    {
        return $this === self::Outros;
    }

    /**
     * Formato do código no XML — sempre 2 dígitos zero-padded (TSCodJustSubst).
     */
    public function codigoFormatado(): string
    {
        return str_pad((string) $this->value, 2, '0', STR_PAD_LEFT);
    }
}
