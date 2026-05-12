<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\Support;

use Normalizer;

/**
 * Sanitização de texto pra campos XML do DPS.
 *
 * O leiaute SEFIN é restritivo: rejeita caracteres de controle, valida
 * tamanho, e algumas validações são case-sensitive. Esse helper:
 *
 *   1. Normaliza unicode (NFC) — combina acentos compostos
 *   2. Remove caracteres de controle (0x00..0x1F exceto \t, \n, \r)
 *   3. Colapsa whitespace múltiplo
 *   4. Trunca em tamanho máximo
 *
 * NÃO remove acentos — o SEFIN aceita UTF-8.
 */
final class TextoSanitizador
{
    public static function paraNFSe(?string $valor, int $maxLength = 2000): string
    {
        if ($valor === null) {
            return '';
        }

        // 1. Normaliza unicode (combinação de acentos)
        if (class_exists(Normalizer::class)) {
            $normalizado = Normalizer::normalize($valor, Normalizer::FORM_C);
            if ($normalizado !== false) {
                $valor = $normalizado;
            }
        }

        // 2. Remove caracteres de controle (preserva \t \n \r)
        $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor) ?? '';

        // 3. Colapsa whitespace múltiplo em espaço único
        $valor = preg_replace('/\s+/u', ' ', $valor) ?? '';

        // 4. Trim e truncate
        $valor = trim($valor);
        if (mb_strlen($valor) > $maxLength) {
            $valor = mb_substr($valor, 0, $maxLength);
        }

        return $valor;
    }
}
