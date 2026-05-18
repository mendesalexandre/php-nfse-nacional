<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Benefício Municipal aplicado ao ISSQN — grupo `<BM>` dentro de
 * `<tribMun>` no DPS (leiaute SefinNacional V1.00.02, linhas 258-261).
 *
 * O município de incidência cadastra previamente o benefício no
 * Sistema Nacional NFS-e e divulga o `nBM` (14 dígitos). O emitente
 * referencia esse `nBM` na DPS; opcionalmente informa o valor ou
 * percentual de redução da BC (xor — apenas um dos dois).
 *
 * Composição do `nBM`:
 *   - 7 dig (1-7):   código IBGE do município
 *   - 2 dig (8-9):   tipo de parametrização (01-legislação, 02-regimes
 *                    especiais, 03-retenções, 04-outros benefícios)
 *   - 5 dig (10-14): número sequencial do registro no sistema
 *
 * Diferente do enum `TipoBeneficioMunicipal` (que descreve a categoria
 * do benefício como label informativo do envelope NFSe), o DPS só
 * referencia o `nBM`.
 */
final class BeneficioMunicipal
{
    public function __construct(
        /** Identificador único cadastrado pelo município no Sistema Nacional. */
        public readonly string $nBM,
        /**
         * Valor monetário da redução da base de cálculo. **Choice** com
         * `percentualReducaoBc`: informar apenas um dos dois.
         */
        public readonly ?float $valorReducaoBc = null,
        /**
         * Percentual de redução da BC. Limite previamente parametrizado
         * pelo município no cadastro do benefício.
         */
        public readonly ?float $percentualReducaoBc = null,
    ) {
        $errors = [];

        if (!preg_match('/^\d{14}$/', $nBM)) {
            $errors[] = "nBM inválido: '{$nBM}' (esperado 14 dígitos)";
        }

        if ($valorReducaoBc !== null && $percentualReducaoBc !== null) {
            $errors[] = 'Informe apenas um: valorReducaoBc OU percentualReducaoBc (são choice no schema)';
        }
        if ($valorReducaoBc !== null && $valorReducaoBc < 0) {
            $errors[] = 'valorReducaoBc não pode ser negativo';
        }
        if ($percentualReducaoBc !== null && ($percentualReducaoBc < 0 || $percentualReducaoBc > 100)) {
            $errors[] = "percentualReducaoBc inválido: {$percentualReducaoBc} (esperado entre 0 e 100)";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Benefício Municipal inválido');
        }
    }
}
