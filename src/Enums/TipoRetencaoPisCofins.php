<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Tipo de retenção PIS/COFINS/CSLL — campo `<tpRetPisCofins>` dentro de
 * `<piscofins>`.
 *
 * Domínio ampliado pela NT 007/2026: além dos valores históricos `1` e `2`
 * (que tratavam só PIS/COFINS), foram acrescentados `0` e `3`–`9`, que passam
 * a englobar também a CSLL. Os retidos dos três tributos são somados em
 * `<vRetCSLL>` conforme este indicador.
 *
 * Atenção: `Retido` (1) e `NaoRetido` (2) serão suprimidos do schema quando os
 * grupos `IBSCBS` se tornarem obrigatórios — prefira os códigos `0` e `3`–`9`
 * em novas integrações.
 */
enum TipoRetencaoPisCofins: int
{
    /** PIS/COFINS/CSLL Não Retidos. */
    case NenhumRetido = 0;
    /** PIS/COFINS Retido (legado — sem CSLL; será suprimido). */
    case Retido = 1;
    /** PIS/COFINS Não Retido (legado — sem CSLL; será suprimido). */
    case NaoRetido = 2;
    /** PIS/COFINS/CSLL Retidos. */
    case PisCofinsCsllRetidos = 3;
    /** PIS/COFINS Retidos, CSLL Não Retido. */
    case PisCofinsRetidosCsllNao = 4;
    /** PIS Retido, COFINS/CSLL Não Retido. */
    case PisRetidoCofinsCsllNao = 5;
    /** COFINS Retido, PIS/CSLL Não Retido. */
    case CofinsRetidoPisCsllNao = 6;
    /** PIS Não Retido, COFINS/CSLL Retidos. */
    case PisNaoCofinsCsllRetidos = 7;
    /** PIS/COFINS Não Retidos, CSLL Retido. */
    case PisCofinsNaoCsllRetido = 8;
    /** COFINS Não Retido, PIS/CSLL Retidos. */
    case CofinsNaoPisCsllRetidos = 9;
}
