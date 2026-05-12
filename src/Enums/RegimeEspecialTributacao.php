<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\Enums;

/**
 * Regime especial de tributação do prestador.
 *
 * IMPORTANTE: SEFIN Nacional rejeita combinação `regEspTrib != Nenhum` + vDedRed
 * na mesma DPS (erro E0438). Pra cartório (Notario=4) com dedução, o SDK força
 * automaticamente Nenhum=0 antes do envio. Caso contrário, o portal recusa
 * antes mesmo de processar.
 */
enum RegimeEspecialTributacao: int
{
    case Nenhum = 0;
    case MicroempresaMunicipal = 1;
    case Estimativa = 2;
    case SociedadeProfissionais = 3;
    case NotarioOuRegistrador = 4;
    case Cooperativa = 5;
    case MEI = 6;
    case MeEppSimples = 7;
}
