<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

/**
 * Alíquota do ISS de um serviço em um município, vigente numa competência
 * (API de Parâmetros Municipais do ADN).
 */
final class AliquotaMunicipal
{
    public function __construct(
        public readonly string $codigoServico,
        public readonly bool $incidencia,
        public readonly float $aliquotaPercentual,
        public readonly ?string $vigenciaInicio,
        public readonly ?string $vigenciaFim,
    ) {}
}
