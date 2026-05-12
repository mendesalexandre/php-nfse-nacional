<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Situação do prestador perante o Simples Nacional (campo `opSimpNac` do DPS).
 *
 * Valores conforme leiaute SefinNacional 1.6 (TSOpSimpNac).
 */
enum SituacaoSimplesNacional: int
{
    case NaoOptante = 1;
    case MEI = 2;
    case MeEpp = 3;
}
