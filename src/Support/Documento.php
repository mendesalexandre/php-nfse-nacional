<?php

declare(strict_types=1);

namespace PhpNfseNacional\Support;

/**
 * Helpers pra manipulação de CPF/CNPJ.
 *
 * Sempre persistimos só dígitos (sem máscara). Funções idempotentes —
 * podem receber CNPJ formatado ou cru e devolvem o normalizado.
 */
final class Documento
{
    public static function limpar(?string $valor): string
    {
        if ($valor === null) {
            return '';
        }
        return preg_replace('/\D/', '', $valor) ?? '';
    }

    public static function ehCpf(string $valor): bool
    {
        return strlen(self::limpar($valor)) === 11;
    }

    public static function ehCnpj(string $valor): bool
    {
        return strlen(self::limpar($valor)) === 14;
    }

    /**
     * Formata pra exibição:
     *   - CPF (11 dígitos)  → 000.000.000-00
     *   - CNPJ (14 dígitos) → 00.000.000/0000-00
     *   - Outro → retorna só os dígitos
     */
    public static function formatar(string $valor): string
    {
        $d = self::limpar($valor);
        return match (strlen($d)) {
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d) ?? $d,
            14 => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $d) ?? $d,
            default => $d,
        };
    }
}
