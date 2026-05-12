<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Descrição do serviço prestado.
 *
 * - cTribNac: 6 dígitos do código de tributação nacional (LC 116/2003).
 *   Default '210101' (serviços notariais e de registro). Ajuste conforme
 *   o item da LC 116 do seu serviço.
 * - cNBS: 9 dígitos do código NBS — derivado do item da LC 116.
 * - cIndOp: 6 dígitos do indicador de operação (ex: 100301 = serviço puro).
 * - codigoMunicipioPrestacao: IBGE 7 dígitos do município onde o serviço foi prestado.
 */
final class Servico
{
    public function __construct(
        public readonly string $discriminacao,
        public readonly string $codigoMunicipioPrestacao,
        public readonly string $cTribNac = '210101',
        public readonly string $cNBS = '113040000',
        public readonly string $cIndOp = '100301',
    ) {
        $errors = [];

        if (mb_strlen(trim($discriminacao)) < 10) {
            $errors[] = 'Discriminação muito curta (mínimo 10 caracteres)';
        }
        if (mb_strlen($discriminacao) > 2000) {
            $errors[] = 'Discriminação muito longa (máximo 2000 caracteres)';
        }
        if (!preg_match('/^\d{7}$/', $codigoMunicipioPrestacao)) {
            $errors[] = "codigoMunicipioPrestacao inválido: {$codigoMunicipioPrestacao}";
        }
        if (!preg_match('/^\d{6}$/', $cTribNac)) {
            $errors[] = "cTribNac inválido: {$cTribNac} (esperado 6 dígitos)";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Serviço inválido');
        }
    }
}
