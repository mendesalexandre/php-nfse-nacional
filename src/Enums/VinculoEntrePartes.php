<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Vínculo entre as partes no negócio (prestador/tomador) — campo
 * `<vincPrest>` dentro de `<comExt>` (XSD V1.01 linha 1378).
 */
enum VinculoEntrePartes: int
{
    case SemVinculo = 0;
    case Controlada = 1;
    case Controladora = 2;
    case Coligada = 3;
    case Matriz = 4;
    case FilialOuSucursal = 5;
    case OutroVinculo = 6;
    case Desconhecido = 9;
}
