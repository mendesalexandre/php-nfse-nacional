<?php

declare(strict_types=1);

namespace PhpNfseNacional\Support;

use Normalizer;

/**
 * Sanitização de texto pra campos XML do DPS.
 *
 * O leiaute SEFIN é restritivo: rejeita caracteres de controle, valida
 * tamanho, e algumas validações são case-sensitive. Esse helper:
 *
 *   1. Normaliza unicode (NFC) — combina acentos compostos
 *   2. Substitui caracteres Unicode "tipográficos" comuns (en-dash, aspas
 *      curvas, ellipsis) pelos equivalentes ASCII. Resolve E1235 quando
 *      texto copiado do Word/Google Docs traz caracteres exóticos
 *   3. Remove caracteres de controle (0x00..0x1F exceto \t, \n, \r)
 *   4. Colapsa whitespace múltiplo
 *   5. Trunca em tamanho máximo
 *
 * NÃO remove acentos — o SEFIN aceita UTF-8. A normalização é só de
 * caracteres tipográficos não-ASCII pra ASCII equivalente.
 */
final class TextoSanitizador
{
    /**
     * Mapping Unicode → ASCII para caracteres tipográficos que podem
     * vir de copy-paste (Word, Google Docs, mensageria) e que o leiaute
     * SEFIN tende a rejeitar ou validar incorretamente.
     *
     * Inspirado no `SUBSTITUICOES_LATIN1` da brans-nfe (MIT).
     *
     * @var array<string, string>
     */
    private const SUBSTITUICOES_TIPOGRAFICAS = [
        // Travessões e hífens longos
        "\u{2013}" => '-',  // EN DASH "–"
        "\u{2014}" => '-',  // EM DASH "—"
        "\u{2015}" => '-',  // HORIZONTAL BAR "―"
        "\u{2212}" => '-',  // MINUS SIGN "−"

        // Aspas tipográficas
        "\u{2018}" => "'",  // LEFT SINGLE QUOTATION MARK "‘"
        "\u{2019}" => "'",  // RIGHT SINGLE QUOTATION MARK "’"
        "\u{201A}" => "'",  // SINGLE LOW-9 QUOTATION MARK "‚"
        "\u{201C}" => '"',  // LEFT DOUBLE QUOTATION MARK """
        "\u{201D}" => '"',  // RIGHT DOUBLE QUOTATION MARK """
        "\u{201E}" => '"',  // DOUBLE LOW-9 QUOTATION MARK "„"
        "\u{00AB}" => '"',  // « LEFT-POINTING DOUBLE ANGLE QUOTATION MARK
        "\u{00BB}" => '"',  // » RIGHT-POINTING DOUBLE ANGLE QUOTATION MARK

        // Ellipsis e bullets
        "\u{2026}" => '...', // HORIZONTAL ELLIPSIS "…"
        "\u{2022}" => '*',   // BULLET "•"
        "\u{00B7}" => '.',   // MIDDLE DOT "·"

        // Espaços não-quebráveis e variantes
        "\u{00A0}" => ' ',  // NO-BREAK SPACE
        "\u{2007}" => ' ',  // FIGURE SPACE
        "\u{2009}" => ' ',  // THIN SPACE
        "\u{200B}" => '',   // ZERO WIDTH SPACE (remove)
        "\u{FEFF}" => '',   // ZERO WIDTH NO-BREAK SPACE / BOM (remove)
    ];

    public static function paraNFSe(?string $valor, int $maxLength = 2000, bool $preservarQuebras = false): string
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

        // 2. Substitui caracteres tipográficos por ASCII equivalente
        $valor = strtr($valor, self::SUBSTITUICOES_TIPOGRAFICAS);

        // 3. Remove caracteres de controle (preserva \t \n \r)
        $valor = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $valor) ?? '';

        // 4. Colapsa whitespace múltiplo
        if ($preservarQuebras) {
            // Normaliza CRLF/CR → LF
            $valor = preg_replace('/\r\n?/u', "\n", $valor) ?? '';
            // Colapsa espaços/tabs horizontais (preserva \n)
            $valor = preg_replace('/[^\S\n]+/u', ' ', $valor) ?? '';
            // Remove espaços nas pontas de cada linha
            $valor = preg_replace('/[^\S\n]*\n[^\S\n]*/u', "\n", $valor) ?? '';
            // Limita a no máximo 2 quebras consecutivas
            $valor = preg_replace('/\n{3,}/u', "\n\n", $valor) ?? '';
        } else {
            // Colapsa todo whitespace (inclusive \n) em espaço único
            $valor = preg_replace('/\s+/u', ' ', $valor) ?? '';
        }

        // 5. Trim e truncate
        $valor = trim($valor);
        if (mb_strlen($valor) > $maxLength) {
            $valor = mb_substr($valor, 0, $maxLength);
        }

        return $valor;
    }
}
