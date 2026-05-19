<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Modo de prestação do serviço em operação de comércio exterior —
 * campo `<mdPrestacao>` dentro de `<comExt>` (XSD V1.01 linha 1366).
 */
enum ModoPrestacao: int
{
    case Desconhecido = 0;
    case Transfronteirico = 1;
    case ConsumoNoBrasil = 2;
    case MovimentoTemporarioPessoasFisicas = 3;
    case ConsumoNoExterior = 4;
}
