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
        self::assertTrue(Documento::ehCpf('12345678900'));
        self::assertTrue(Documento::ehCpf('123.456.789-00'));
        self::assertFalse(Documento::ehCpf('12345678000190'));
    }

    public function test_eh_cnpj_com_14_digitos(): void
    {
        self::assertTrue(Documento::ehCnpj('12345678000190'));
        self::assertTrue(Documento::ehCnpj('12.345.678/0001-90'));
        self::assertFalse(Documento::ehCnpj('12345678900'));
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
}
