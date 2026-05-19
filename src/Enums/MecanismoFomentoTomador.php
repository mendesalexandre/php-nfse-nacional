<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Mecanismo de apoio/fomento ao Comércio Exterior utilizado pelo
 * TOMADOR do serviço — campo `<mecAFComexT>` dentro de `<comExt>`
 * (XSD V1.01 linha 1419).
 *
 * 26 cases conforme tabela oficial. Quando em dúvida, use `Nenhum`.
 */
enum MecanismoFomentoTomador: string
{
    case Desconhecido = '00';
    case Nenhum = '01';
    case AdmPublicaERepresentacaoInternacional = '02';
    case AlugueisArrendamentoMercantilMaquinas = '03';
    case ArrendamentoMercantilAeronaveTransportePublico = '04';
    case ComissaoAgentesExternosExportacao = '05';
    case DespesasArmazenagemMovimentacaoTransporte = '06';
    case EventosFifaSubsidiaria = '07';
    case EventosFifa = '08';
    case FretesArrendamentos = '09';
    case MaterialAeronautico = '10';
    case PromocaoBensExterior = '11';
    case PromocaoDestinosTuristicosBrasileiros = '12';
    case PromocaoBrasilExterior = '13';
    case PromocaoServicosExterior = '14';
    case Recine = '15';
    case Recopa = '16';
    case RegistroManutencaoMarcasPatentes = '17';
    case Reicomp = '18';
    case Reidi = '19';
    case Repenec = '20';
    case Repes = '21';
    case Retaero = '22';
    case Retid = '23';
    case RoyaltiesAssistenciaTecnica = '24';
    case ServicosAvaliacaoConformidadeOmc = '25';
    case Zpe = '26';
}
