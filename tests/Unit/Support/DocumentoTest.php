<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Support;

use PhpNfseNacional\Support\Documento;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentoTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function casosLimpeza(): array
    {
        return [
            'cpf formatado'   => ['123.456.789-00', '12345678900'],
            'cnpj formatado'  => ['12.345.678/0001-90', '12345678000190'],
            'só dígitos'      => ['12345678900', '12345678900'],
            'string vazia'    => ['', ''],
            'com espaços'     => [' 12.345.678/0001-90 ', '12345678000190'],
        ];
    }

    #[DataProvider('casosLimpeza')]
    public function test_limpar_remove_mascara(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, Documento::limpar($entrada));
    }

    public function test_limpar_aceita_null(): void
    {
        self::assertSame('', Documento::limpar(null));
    }

    public function test_eh_cpf_com_11_digitos(): void
    {
        self::assertTrue(Documento::isCPF('12345678900'));
        self::assertTrue(Documento::isCPF('123.456.789-00'));
        self::assertFalse(Documento::isCPF('12345678000190'));
    }

    public function test_eh_cnpj_com_14_digitos(): void
    {
        self::assertTrue(Documento::isCNPJ('12345678000190'));
        self::assertTrue(Documento::isCNPJ('12.345.678/0001-90'));
        self::assertFalse(Documento::isCNPJ('12345678900'));
    }

    public function test_formatar_cpf(): void
    {
        self::assertSame('123.456.789-00', Documento::formatar('12345678900'));
    }

    public function test_formatar_cnpj(): void
    {
        self::assertSame('12.345.678/0001-90', Documento::formatar('12345678000190'));
    }

    public function test_formatar_documento_invalido_retorna_digitos(): void
    {
        self::assertSame('123', Documento::formatar('123'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function casosLimpezaAlfanumerico(): array
    {
        return [
            'cnpj alfanumerico formatado' => ['12.ABC.345/01DE-35', '12ABC34501DE35'],
            'cnpj alfanumerico minusculo' => ['12.abc.345/01de-35', '12ABC34501DE35'],
            'cnpj alfanumerico do BB'     => ['00.000.000/E08G-12', '00000000E08G12'],
        ];
    }

    #[DataProvider('casosLimpezaAlfanumerico')]
    public function test_limpar_preserva_letras_do_cnpj_alfanumerico(string $entrada, string $esperado): void
    {
        self::assertSame($esperado, Documento::limpar($entrada));
    }

    public function test_eh_cnpj_aceita_alfanumerico(): void
    {
        self::assertTrue(Documento::isCNPJ('12.ABC.345/01DE-35'));
        self::assertTrue(Documento::isCNPJ('00.000.000/E08G-12'));
        self::assertFalse(Documento::isCNPJ('12.ABC.345/01D-35')); // 13 chars
    }

    public function test_eh_cpf_continua_exigindo_so_digitos(): void
    {
        self::assertFalse(Documento::isCPF('123.456.78A-00'));
    }

    public function test_formatar_cnpj_alfanumerico(): void
    {
        self::assertSame('12.ABC.345/01DE-35', Documento::formatar('12ABC34501DE35'));
        self::assertSame('00.000.000/E08G-12', Documento::formatar('00000000E08G12'));
    }

    /**
     * Exemplo oficial do documento SERPRO "Cálculo dos dígitos
     * verificadores de CNPJ alfanumérico" — raiz 12ABC34501DE, DV=35.
     */
    public function test_calcularDvCnpj_bate_com_exemplo_oficial_serpro(): void
    {
        self::assertSame('35', Documento::calcularDvCnpj('12ABC34501DE'));
    }

    /**
     * CNPJ alfanumérico real, primeiro emitido pelo Banco do Brasil:
     * 00.000.000/E08G-12.
     */
    public function test_calcularDvCnpj_bate_com_cnpj_real_banco_do_brasil(): void
    {
        self::assertSame('12', Documento::calcularDvCnpj('00000000E08G'));
    }

    public function test_calcularDvCnpj_funciona_pra_raiz_so_numerica(): void
    {
        // CNPJ clássico 11.222.333/0001-81 é DV-válido — mesmo algoritmo.
        self::assertSame('81', Documento::calcularDvCnpj('112223330001'));
    }

    public function test_calcularDvCnpj_rejeita_raiz_com_tamanho_errado(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        Documento::calcularDvCnpj('12ABC345');
    }

    public function test_validarDvCnpj(): void
    {
        self::assertTrue(Documento::validarDvCnpj('12.ABC.345/01DE-35'));
        self::assertTrue(Documento::validarDvCnpj('00.000.000/E08G-12'));
        self::assertFalse(Documento::validarDvCnpj('12.ABC.345/01DE-00'));
        self::assertFalse(Documento::validarDvCnpj('123'));
    }

    public function test_ehCpf_e_ehCnpj_aliases_deprecados_delegam(): void
    {
        self::assertTrue(Documento::ehCpf('12345678900'));
        self::assertTrue(Documento::ehCnpj('00.000.000/E08G-12'));
    }
}
