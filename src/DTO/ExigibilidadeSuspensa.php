<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Enums\TipoExigibilidadeSuspensa;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Suspensão da exigibilidade do ISSQN — grupo `<exigSusp>` dentro de
 * `<tribMun>` no DPS (leiaute SefinNacional V1.00.02, linhas 262-264).
 *
 * Quando informado, o ISSQN não é exigido na emissão da NFS-e por
 * conta de decisão judicial ou processo administrativo. O `nProcesso`
 * é o número do processo correspondente.
 */
final class ExigibilidadeSuspensa
{
    public function __construct(
        public readonly TipoExigibilidadeSuspensa $tipo,
        public readonly string $numeroProcesso,
    ) {
        $errors = [];

        $proc = trim($numeroProcesso);
        if ($proc === '') {
            $errors[] = 'numeroProcesso vazio';
        }
        if (mb_strlen($proc) > 30) {
            $errors[] = "numeroProcesso muito longo: '{$proc}' (máx 30 chars)";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Exigibilidade Suspensa inválida');
        }
    }
}
