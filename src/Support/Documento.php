<?php

declare(strict_types=1);

namespace PhpNfseNacional\Support;

/**
 * Helpers pra manipulação de CPF/CNPJ.
 *
 * CPF é sempre numérico (11 dígitos). CNPJ, desde a Instrução Normativa
 * RFB que introduziu o CNPJ alfanumérico (rollout nacional a partir de
 * jul/2026), tem 12 caracteres alfanuméricos (raiz + ordem) seguidos de
 * 2 dígitos verificadores sempre numéricos — ex: `12.ABC.345/01DE-35`.
 * `limpar()` só remove máscara (`.`, `/`, `-`, espaços) e normaliza pra
 * maiúsculas — NÃO usa `\D` (descartaria as letras do CNPJ alfanumérico).
 * Funções idempotentes — podem receber documento formatado ou cru.
 */
final class Documento
{
    /** Pesos do módulo 11 (SERPRO, "Cálculo dos dígitos verificadores de CNPJ alfanumérico"). */
    private const PESOS_DV_CNPJ = [6, 5, 4, 3, 2, 9, 8, 7, 6, 5, 4, 3, 2];

    public static function limpar(?string $valor): string
    {
        if ($valor === null) {
            return '';
        }
        $semMascara = preg_replace('/[.\/\-\s]/', '', $valor) ?? '';
        return strtoupper($semMascara);
    }

    public static function ehCpf(string $valor): bool
    {
        return (bool) preg_match('/^\d{11}$/', self::limpar($valor));
    }

    /**
     * 14 caracteres: 12 alfanuméricos (raiz+ordem) + 2 dígitos verificadores
     * numéricos. Só checa formato/tamanho — não valida o DV (ver
     * `validarDvCnpj()` pra isso). Aceita tanto CNPJ numérico clássico
     * quanto o novo alfanumérico.
     */
    public static function ehCnpj(string $valor): bool
    {
        return (bool) preg_match('/^[A-Z0-9]{12}\d{2}$/', self::limpar($valor));
    }

    /**
     * Formata pra exibição:
     *   - CPF (11 dígitos)         → 000.000.000-00
     *   - CNPJ (14 caracteres)     → 00.000.000/0000-00 (letras preservadas)
     *   - Outro → retorna só o valor limpo
     */
    public static function formatar(string $valor): string
    {
        $d = self::limpar($valor);
        return match (strlen($d)) {
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $d) ?? $d,
            14 => preg_replace('/^(.{2})(.{3})(.{3})(.{4})(.{2})$/', '$1.$2.$3/$4-$5', $d) ?? $d,
            default => $d,
        };
    }

    /**
     * Calcula os 2 dígitos verificadores de um CNPJ a partir da raiz+ordem
     * (12 caracteres alfanuméricos, sem os DVs). Algoritmo oficial SERPRO:
     * módulo 11, pesos 2-9 distribuídos da direita pra esquerda, valor de
     * cada caractere = código ASCII - 48 (dígitos '0'-'9' → 0-9, letras
     * 'A'-'Z' → 17-42). Funciona tanto pra raiz alfanumérica quanto pra
     * CNPJ clássico só-dígitos (é o mesmo algoritmo, generalizado).
     *
     * @throws \InvalidArgumentException se a raiz não tiver 12 caracteres alfanuméricos
     */
    public static function calcularDvCnpj(string $raiz): string
    {
        $raiz = self::limpar($raiz);
        if (!preg_match('/^[A-Z0-9]{12}$/', $raiz)) {
            throw new \InvalidArgumentException(
                'Raiz do CNPJ deve ter 12 caracteres alfanuméricos (sem os dígitos verificadores), recebido: ' . $raiz
            );
        }

        $somaDv1 = 0;
        $somaDv2 = 0;
        for ($i = 0; $i < 12; $i++) {
            $valor = ord($raiz[$i]) - 48;
            $somaDv1 += $valor * self::PESOS_DV_CNPJ[$i + 1];
            $somaDv2 += $valor * self::PESOS_DV_CNPJ[$i];
        }

        $dv1 = self::digitoModulo11($somaDv1);
        $somaDv2 += $dv1 * self::PESOS_DV_CNPJ[12];
        $dv2 = self::digitoModulo11($somaDv2);

        return "{$dv1}{$dv2}";
    }

    /**
     * Valida se os 2 dígitos verificadores de um CNPJ completo (14
     * caracteres) batem com o cálculo a partir da raiz+ordem. Utilitário
     * opt-in — `ehCnpj()` continua checando só formato/tamanho (a lib
     * valida sintaxe, não decide se um documento é fiscalmente válido).
     */
    public static function validarDvCnpj(string $valor): bool
    {
        $cnpj = self::limpar($valor);
        if (!self::ehCnpj($cnpj)) {
            return false;
        }
        return substr($cnpj, -2) === self::calcularDvCnpj(substr($cnpj, 0, 12));
    }

    private static function digitoModulo11(int $soma): int
    {
        $resto = $soma % 11;
        return $resto < 2 ? 0 : 11 - $resto;
    }
}
