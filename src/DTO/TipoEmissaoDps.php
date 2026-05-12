<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\DTO;

/**
 * Tipo de emissão da DPS.
 *
 * - Normal: envio online direto ao SEFIN (default)
 * - Contingencia: emissão em modo offline (portal fora ou indisponível)
 * - ContingenciaOffline: variante mais restrita
 */
enum TipoEmissaoDps: int
{
    case Normal = 1;
    case Contingencia = 2;
    case ContingenciaOffline = 3;
}
