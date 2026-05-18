<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Tipo de tributação do ISSQN sobre o serviço prestado — elemento
 * `<tribISSQN>` (obrigatório dentro de `<tribMun>`).
 *
 * Mapeamento conforme leiaute SefinNacional V1.00.02 (Anexo IV,
 * linha 256). Valores 2/3/4 estavam invertidos no `DanfseLayout`
 * legado e em pressuposições anteriores — corrigido na v0.9.1.
 */
enum TipoTributacaoIssqn: int
{
    case OperacaoTributavel = 1;
    case Imunidade = 2;
    case ExportacaoServico = 3;
    case NaoIncidencia = 4;
}
