<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

/**
 * Tipo de emissão da DPS — IDENTIFICA QUEM EMITIU.
 *
 * **Diferente do tpEmit da NF-e** (que distingue normal/contingência).
 * No leiaute SefinNacional 1.6, o tpEmit identifica o ator que originou
 * a DPS:
 *
 * - Prestador (1): emissão normal — quem prestou o serviço (default).
 * - Tomador (2): permitido pelo leiaute mas **não habilitado nesta versão**
 *   da aplicação SEFIN. Tentar emitir resulta em cStat=9996
 *   ("Nesta versão da aplicação, não é permitida a emissão de NFS-e
 *   pelo tomador ou intermediário").
 * - Intermediario (3): mesma situação — leiaute aceita, aplicação SEFIN
 *   ainda não habilitou (cStat=9996).
 *
 * **Não existe "contingência" como flag dedicada na SefinNacional 1.6.**
 * Cenários offline são tratados via `dhEmi` retroativo + tpEmit=1 normal.
 *
 * Validado empiricamente em homologação 13/05/2026 — tentamos tpEmit=2 e
 * tpEmit=3, ambos rejeitados com cStat=9996.
 */
enum TipoEmissaoDps: int
{
    case Prestador = 1;
    case Tomador = 2;
    case Intermediario = 3;
}
