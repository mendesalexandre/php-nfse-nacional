<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\DTO;

use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class EnderecoTest extends TestCase
{
    public function test_endereco_valido_eh_aceito(): void
    {
        $end = new Endereco('Av Paulista', '1000', 'Bela Vista', '01310100', '3550308', 'SP');

        self::assertSame('Av Paulista', $end->logradouro);
        self::assertSame('SP', $end->uf);
        self::assertNull($end->complemento);
    }

    public function test_cep_aceita_mascara(): void
    {
        // 8 dígitos pós-limpeza — não deve lançar
        $end = new Endereco('Av X', '1', 'Centro', '01310-100', '3550308', 'SP');
        self::assertSame('01310-100', $end->cep); // valor original preservado
    }

    public function test_cep_com_menos_de_8_digitos_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Endereco('Av X', '1', 'Centro', '1234567', '3550308', 'SP');
    }

    public function test_codigo_ibge_com_6_digitos_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Endereco('Av X', '1', 'Centro', '01310100', '355030', 'SP');
    }

    public function test_uf_minuscula_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Endereco('Av X', '1', 'Centro', '01310100', '3550308', 'sp');
    }

    public function test_logradouro_vazio_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Endereco('', '1', 'Centro', '01310100', '3550308', 'SP');
    }

    public function test_acumula_multiplos_erros_em_uma_excecao(): void
    {
        try {
            new Endereco('', '', '', 'xxx', 'yyy', 'sp');
            self::fail('esperava ValidationException');
        } catch (ValidationException $e) {
            self::assertGreaterThanOrEqual(4, count($e->errors()));
        }
    }
}
