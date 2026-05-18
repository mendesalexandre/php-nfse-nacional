<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Tipo da dedução/redução — campo `<tpDedRed>` dentro de `<docDedRed>`
 * (leiaute SefinNacional V1.00.02, linha 547).
 *
 * Aplicável quando o emissor referencia documentos pra abater do valor
 * do serviço — construção civil (materiais, subempreitada), agências
 * de turismo (repasses), hotelaria (alimentação), etc.
 */
enum TipoDeducaoReducao: string
{
    case AlimentacaoBebidasFrigobar = '01';
    case Materiais = '02';
    case ProducaoExterna = '03';
    case ReembolsoDespesas = '04';
    case RepasseConsorciado = '05';
    case RepassePlanoSaude = '06';
    case Servicos = '07';
    case SubempreitadaMaoObra = '08';

    /** Outras deduções — exige `xDescOutDed` preenchido. */
    case Outras = '99';
}
