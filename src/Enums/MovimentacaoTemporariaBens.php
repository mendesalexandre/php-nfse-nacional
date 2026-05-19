<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Vínculo da operação a movimentação temporária de bens — campo
 * `<movTempBens>` dentro de `<comExt>` (XSD V1.01 linha 1453).
 */
enum MovimentacaoTemporariaBens: int
{
    case Desconhecido = 0;
    case Nao = 1;
    case VinculadaDeclaracaoImportacao = 2;
    case VinculadaDeclaracaoExportacao = 3;
}
