<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Tipo de retenção do ISSQN — campo `tpRetISSQN` do DPS
 * (leiaute SefinNacional 1.6, grupo `<tribMun>`).
 *
 * Hoje o SDK consome o legacy `Valores::$issqnRetido` (bool, true = retido
 * pelo tomador). Este enum cobre o leiaute completo (3 cases) e será o
 * campo canônico em versão futura. O `bool` segue suportado por compat.
 */
enum TipoRetencaoIssqn: int
{
    case NaoRetido = 1;
    case RetidoPeloTomador = 2;
    case RetidoPeloIntermediario = 3;
}
