<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Código de Situação Tributária do PIS/COFINS — campo `<CST>` dentro de
 * `<piscofins>` (leiaute SefinNacional V1.00.02, linha 270).
 *
 * Convenção nacional (mesma da NF-e modelo 55). 2 dígitos zero-padded.
 */
enum CstPisCofins: string
{
    case Nenhum = '00';
    case OperacaoTributavelAliquotaBasica = '01';
    case OperacaoTributavelAliquotaDiferenciada = '02';
    case OperacaoTributavelAliquotaPorUnidadeMedida = '03';
    case OperacaoTributavelMonofasicaRevendaAliquotaZero = '04';
    case OperacaoTributavelSubstituicaoTributaria = '05';
    case OperacaoTributavelAliquotaZero = '06';
    case OperacaoTributavelContribuicao = '07';
    case OperacaoSemIncidenciaContribuicao = '08';
    case OperacaoComSuspensaoContribuicao = '09';
}
