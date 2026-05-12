<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\Enums;

/**
 * Ambiente do Portal Nacional SEFIN.
 *
 * - Producao: emissão real com efeito fiscal
 * - Homologacao: testes (DPS gerado mas sem registro fiscal)
 */
enum Ambiente: int
{
    case Producao = 1;
    case Homologacao = 2;

    public function label(): string
    {
        return match ($this) {
            self::Producao => 'Produção',
            self::Homologacao => 'Homologação',
        };
    }
}
