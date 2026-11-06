<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Versão do leiaute/XSD do DPS aceita pelo SEFIN Nacional.
 *
 * O SEFIN publicou em 20/10/2026 um schema novo na produção restrita
 * (homologação), enquanto a produção segue no anterior. Os dois ficam
 * versionados em `docs/schemas/` (ver `docs/README.md`).
 *
 * Diferenças que o `DpsBuilder` trata:
 *
 * | Ponto            | V1_01 (produção)          | V1_01_PRODREST (homologação)         |
 * |------------------|---------------------------|--------------------------------------|
 * | `finNFSe`        | dentro de `<IBSCBS>`      | em `infDPS`, após `tpEmit`, sempre   |
 * | `cServ`          | `xDescServ` → `cNBS`      | `cNBS` → `xDescServ`                 |
 * | dedução da BC    | `vDedRed` (`vDR`/`pDR`)   | `vAjusteBC` (`vAjusteBCISSQN`/`pAjusteBCISSQN`) |
 */
enum LeiauteDps: string
{
    /** Leiaute vigente em produção (XSD v1.01 de 09/02/2026). */
    case V1_01 = '1.01';

    /** Leiaute da produção restrita publicado em 20/10/2026. */
    case V1_01_PRODREST = '1.01-prodrest-20261020';

    /** Leiaute padrão de cada ambiente, quando o `Config` não força um. */
    public static function padraoDoAmbiente(Ambiente $ambiente): self
    {
        return match ($ambiente) {
            Ambiente::Producao => self::V1_01,
            Ambiente::Homologacao => self::V1_01_PRODREST,
        };
    }

    /** `vAjusteBC` no lugar de `vDedRed`, `finNFSe` em `infDPS`, `cNBS` antes do `xDescServ`. */
    public function isPosRtc20261020(): bool
    {
        return $this === self::V1_01_PRODREST;
    }
}
