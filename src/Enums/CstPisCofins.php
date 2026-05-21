<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Código de Situação Tributária do PIS/COFINS — campo `<CST>` dentro de
 * `<piscofins>`.
 *
 * Convenção nacional (mesma da NF-e modelo 55). 2 dígitos zero-padded.
 * Domínio ampliado pela NT 007/2026 (AnexoVI V1.03.00) — códigos de crédito,
 * crédito presumido e aquisição (50–75, 98) além das operações de saída.
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
    case OperacaoIsentaContribuicao = '07';
    case OperacaoSemIncidenciaContribuicao = '08';
    case OperacaoComSuspensaoContribuicao = '09';
    case OutrasOperacoesSaida = '49';
    case CreditoVinculadoExclReceitaTributadaMercadoInterno = '50';
    case CreditoVinculadoExclReceitaNaoTributadaMercadoInterno = '51';
    case CreditoVinculadoExclReceitaExportacao = '52';
    case CreditoVinculadoReceitasTributadasNaoTributadasMercadoInterno = '53';
    case CreditoVinculadoReceitasTributadasMercadoInternoExportacao = '54';
    case CreditoVinculadoReceitasNaoTributadasMercadoInternoExportacao = '55';
    case CreditoVinculadoReceitasTributadasNaoTributadasMercadoInternoExportacao = '56';
    case CreditoPresumidoVinculadoExclReceitaTributadaMercadoInterno = '60';
    case CreditoPresumidoVinculadoExclReceitaNaoTributadaMercadoInterno = '61';
    case CreditoPresumidoVinculadoExclReceitaExportacao = '62';
    case CreditoPresumidoVinculadoReceitasTributadasNaoTributadasMercadoInterno = '63';
    case CreditoPresumidoVinculadoReceitasTributadasMercadoInternoExportacao = '64';
    case CreditoPresumidoVinculadoReceitasNaoTributadasMercadoInternoExportacao = '65';
    case CreditoPresumidoVinculadoReceitasTributadasNaoTributadasMercadoInternoExportacao = '66';
    case CreditoPresumidoOutrasOperacoes = '67';
    case AquisicaoSemDireitoCredito = '70';
    case AquisicaoComIsencao = '71';
    case AquisicaoComSuspensao = '72';
    case AquisicaoAliquotaZero = '73';
    case AquisicaoSemIncidenciaContribuicao = '74';
    case AquisicaoSubstituicaoTributaria = '75';
    case OutrasOperacoesEntrada = '98';
    case OutrasOperacoes = '99';
}
