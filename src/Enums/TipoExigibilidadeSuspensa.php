<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Tipo de suspensão da exigibilidade do ISSQN — elemento `<tpSusp>`
 * do grupo `<exigSusp>` no DPS (leiaute SefinNacional V1.00.02,
 * linha 263).
 *
 * Aplicável quando o ISSQN tem exigibilidade suspensa por processo
 * judicial ou administrativo. Acompanha o `<nProcesso>` (número do
 * processo correspondente) dentro do mesmo grupo.
 */
enum TipoExigibilidadeSuspensa: int
{
    case ProcessoJudicial = 1;
    case ProcessoAdministrativo = 2;
}
