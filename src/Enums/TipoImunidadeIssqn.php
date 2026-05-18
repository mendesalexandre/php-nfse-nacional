<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Tipo de imunidade do ISSQN — campo `tpImunidade` do grupo
 * `<tribImunidade>` no DPS (leiaute SefinNacional 1.6), aplicável
 * quando `tribISSQN = 4` (Imunidade).
 *
 * Referência: CF/88 art. 150, VI (imunidades tributárias).
 */
enum TipoImunidadeIssqn: int
{
    /** CF 150 VI "a" — patrimônio, renda ou serviços entre entes federativos. */
    case PatrimonioRendaServicosEntes = 1;

    /** CF 150 VI "b" — templos de qualquer culto. */
    case TemplosQualquerCulto = 2;

    /** CF 150 VI "c" — partidos políticos, sindicatos, entidades de educação/assistência. */
    case PartidosSindicatosEducacaoAssistencia = 3;

    /** CF 150 VI "d" — livros, jornais, periódicos e o papel destinado à impressão. */
    case LivrosJornaisPeriodicosPapel = 4;

    /** CF 150 VI "e" (EC 75/2013) — fonogramas e videofonogramas musicais brasileiros. */
    case FonogramasVideofonogramasMusicaisBR = 5;
}
