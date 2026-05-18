<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Regime de apuração para optantes do Simples Nacional — campo
 * `regApTribSN` do DPS, exigido quando `opSimpNac ∈ {2, 3}`
 * (MEI ou ME/EPP).
 *
 * Define onde os tributos federais e o ISSQN são apurados:
 * pelo próprio Simples (DAS) ou pela NFS-e conforme legislação
 * municipal/federal específica.
 */
enum RegimeApuracaoSimplesNacional: int
{
    /** Federais e ISSQN apurados pelo SN (DAS). Cenário típico do MEI. */
    case FederaisEMunicipalPorSN = 1;

    /** Federais pelo SN; ISSQN pela NFS-e conforme legislação municipal. */
    case FederaisPorSnMunicipalPorNfse = 2;

    /** Federais e ISSQN pela NFS-e conforme legislações federal/municipal. */
    case FederaisEMunicipalPorNfse = 3;
}
