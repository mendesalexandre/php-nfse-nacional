<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Tipo de benefício fiscal municipal aplicado ao ISSQN — campo `tpBM`
 * do grupo `<benef>` no DPS (leiaute SefinNacional 1.6).
 *
 * Usado para indicar isenção, redução de base de cálculo ou alíquota
 * diferenciada concedida pelo município ao prestador.
 */
enum TipoBeneficioMunicipal: int
{
    case Isencao = 1;
    case ReducaoBcPercentual = 2;
    case ReducaoBcValor = 3;
    case AliquotaDiferenciada = 4;
}
