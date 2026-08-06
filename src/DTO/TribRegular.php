<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Grupo `<gTribRegular>` dentro de `<gIBSCBS>` — informa qual seria a
 * classificação tributária REGULAR (CST/cClassTrib), pra referência,
 * quando a operação em si usa uma classificação diferente (redução,
 * isenção, diferimento, etc.). Leiaute `TCRTCInfoTributosTribRegular`
 * (tiposComplexos_v1.01.xsd) — CSTReg + cClassTribReg, ambos obrigatórios
 * quando o grupo é informado.
 */
final class TribRegular
{
    public function __construct(
        public readonly string $cstReg,
        public readonly string $cClassTribReg,
    ) {
        $errors = [];

        if (!preg_match('/^\d{3}$/', $cstReg)) {
            $errors[] = "cstReg inválido: '{$cstReg}' (esperado 3 dígitos)";
        }
        if (!preg_match('/^\d{6}$/', $cClassTribReg)) {
            $errors[] = "cClassTribReg inválido: '{$cClassTribReg}' (esperado 6 dígitos)";
        } elseif (preg_match('/^\d{3}$/', $cstReg) && !str_starts_with($cClassTribReg, $cstReg)) {
            $errors[] = "cClassTribReg ('{$cClassTribReg}') não pertence ao grupo do CSTReg informado "
                . "('{$cstReg}') — os 3 primeiros dígitos de cClassTribReg devem ser o CSTReg";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'TribRegular inválido');
        }
    }
}
