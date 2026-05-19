<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Motivo da dispensa de informação do total de tributos.
 *
 * Quando algum caso aplica, o `DpsBuilder` emite `<totTrib><indTotTrib>0</indTotTrib></totTrib>`
 * — indicando "valor total dos tributos NÃO informado" — em vez de
 * `<pTotTrib>` (declaração de alíquota aproximada pela Lei 12.741/2012).
 *
 * O motivo em si não vai pro XML — o leiaute do `<indTotTrib>` é binário
 * (0 = não informado). Esse enum existe para auditoria/documentação: deixa
 * explícito no caller QUAL cenário fiscal justificou a dispensa.
 *
 * Restrição empírica importante (cStat=713): a dispensa só é aceita pelo
 * SEFIN quando o prestador é Optante do Simples Nacional
 * (`SituacaoSimplesNacional::MEI` ou `MeEpp`). Para emissores Não Optante
 * (Lucro Real/Presumido) em cenários de imunidade/isenção, o leiaute
 * exige `<pTotTrib>` mesmo — não use dispensa nesses casos; deixe o
 * campo `null` e o builder emite `<pTotTrib>` com `pTotTribMun=0`.
 */
enum MotivoDispensaIssqn: string
{
    /**
     * Optante do Simples Nacional (MEI ou ME/EPP). ISSQN é recolhido
     * via DAS, então não há "alíquota aproximada" pra declarar.
     */
    case OptanteSimplesNacional = 'OPTANTE_SIMPLES_NACIONAL';

    /** Operação imune (CF/88 art. 150 VI). */
    case OperacaoImune = 'OPERACAO_IMUNE';

    /** Isenção concedida por lei municipal específica. */
    case OperacaoIsenta = 'OPERACAO_ISENTA';

    /** Outras justificativas (não-incidência, suspensão, etc.). */
    case Outros = 'OUTROS';
}
