<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Support;

use PhpNfseNacional\Support\TextoSanitizador;
use PHPUnit\Framework\TestCase;

final class TextoSanitizadorTest extends TestCase
{
    public function test_substitui_en_dash_e_em_dash_por_hifen(): void
    {
        $entrada = "Serviço – consultoria — janeiro";
        $saida = TextoSanitizador::paraNFSe($entrada);
        self::assertSame('Serviço - consultoria - janeiro', $saida);
    }

    public function test_substitui_aspas_curvas_por_aspas_retas(): void
    {
        $entrada = 'Cliente disse "OK" e ‘confirmou’ tudo';
        $saida = TextoSanitizador::paraNFSe($entrada);
        self::assertSame('Cliente disse "OK" e \'confirmou\' tudo', $saida);
    }

    public function test_substitui_ellipsis_unicode_por_tres_pontos(): void
    {
        $entrada = "Detalhes…";
        $saida = TextoSanitizador::paraNFSe($entrada);
        self::assertSame('Detalhes...', $saida);
    }

    public function test_remove_zero_width_space_e_bom(): void
    {
        $entrada = "AB\u{200B}CD\u{FEFF}EF";
        $saida = TextoSanitizador::paraNFSe($entrada);
        self::assertSame('ABCDEF', $saida);
    }

    public function test_substitui_nbsp_por_espaco_normal(): void
    {
        // NBSP entre "A" e "B" deve virar espaço normal e ser preservado
        $entrada = "A\u{00A0}B";
        $saida = TextoSanitizador::paraNFSe($entrada);
        self::assertSame('A B', $saida);
    }

    public function test_preserva_acentos_portugueses(): void
    {
        $entrada = 'Cartório de Registro de Imóveis — Avaliação';
        $saida = TextoSanitizador::paraNFSe($entrada);
        self::assertSame('Cartório de Registro de Imóveis - Avaliação', $saida);
    }

    public function test_remove_caracteres_de_controle(): void
    {
        $entrada = "linha1\x00\x01\x07linha2";
        $saida = TextoSanitizador::paraNFSe($entrada);
        self::assertSame('linha1linha2', $saida);
    }

    public function test_trunca_em_maxLength(): void
    {
        $entrada = str_repeat('a', 100);
        $saida = TextoSanitizador::paraNFSe($entrada, maxLength: 10);
        self::assertSame(10, mb_strlen($saida));
    }

    public function test_null_retorna_string_vazia(): void
    {
        self::assertSame('', TextoSanitizador::paraNFSe(null));
    }

    public function test_colapsa_quebras_de_linha_por_padrao(): void
    {
        $entrada = "1 - ordem\n2 - ordem\n3 - ordem";
        $saida = TextoSanitizador::paraNFSe($entrada);
        self::assertSame('1 - ordem 2 - ordem 3 - ordem', $saida);
    }

    public function test_preserva_quebras_de_linha_quando_habilitado(): void
    {
        $entrada = "1 - ordem\r\n2 - ordem  \n  3 - ordem";
        $saida = TextoSanitizador::paraNFSe($entrada, preservarQuebras: true);
        self::assertSame("1 - ordem\n2 - ordem\n3 - ordem", $saida);
    }

    public function test_limita_quebras_consecutivas_quando_preserva(): void
    {
        $entrada = "linha1\n\n\n\nlinha2";
        $saida = TextoSanitizador::paraNFSe($entrada, preservarQuebras: true);
        self::assertSame("linha1\n\nlinha2", $saida);
    }
}
