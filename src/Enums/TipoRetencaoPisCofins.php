<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Tipo de retenção PIS/COFINS — campo `<tpRetPisCofins>` dentro de
 * `<piscofins>` (leiaute SefinNacional V1.00.02, linha 276).
 */
enum TipoRetencaoPisCofins: int
{
    case Retido = 1;
    case NaoRetido = 2;
}
