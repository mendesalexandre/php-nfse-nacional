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

        // Pattern oficial do XSD `TSNumProcExigSuspensa` = `[0-9]{30}` —
        // exatamente 30 dígitos numéricos, sem letras, sem pontuação.
        // Confirmado contra `docs/schemas/1.01/tiposSimples_v1.01.xsd`
        // após `cStat=1235` rejeitar todos os formatos CNJ tradicionais.
        //
        // O formato exato é convenção do leiaute SefinNacional — provavelmente
        // CNJ (20 dígitos) + complemento. Como o XSD não documenta a
        // semântica dos 30 dígitos, recomenda-se confirmar com o município
        // de incidência qual formato esperam (geralmente CNJ + zeros à
        // esquerda ou à direita).
        if (!preg_match('/^\d{30}$/', $numeroProcesso)) {
            $errors[] = sprintf(
                "numeroProcesso inválido: '%s' (esperado exatamente 30 dígitos, recebeu %d chars '%s')",
                substr($numeroProcesso, 0, 50),
                strlen($numeroProcesso),
                preg_match('/[^0-9]/', $numeroProcesso) ? 'com não-dígitos' : 'só dígitos',
            );
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Exigibilidade Suspensa inválida');
        }
    }
}
