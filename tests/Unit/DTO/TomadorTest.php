<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\DTO;

use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Tomador;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class TomadorTest extends TestCase
{
    private function endereco(): Endereco
    {
        return new Endereco(
            logradouro: 'Rua A',
            numero: '100',
            bairro: 'Centro',
            cep: '78550200',
            codigoMunicipioIbge: '5107909',
            uf: 'MT',
        );
    }

    public function test_aceita_cpf_valido(): void
    {
        $tomador = new Tomador('44208855134', 'João da Silva', $this->endereco());
        self::assertSame('44208855134', $tomador->documento);
        self::assertTrue($tomador->ehPessoaFisica());
    }

    public function test_aceita_cnpj_valido(): void
    {
        $tomador = new Tomador('00179028000138', 'Empresa LTDA', $this->endereco());
        self::assertSame('00179028000138', $tomador->documento);
        self::assertFalse($tomador->ehPessoaFisica());
    }

    public function test_normaliza_documento_com_mascara(): void
    {
        $tomador = new Tomador('00.179.028/0001-38', 'Empresa', $this->endereco());
        self::assertSame('00179028000138', $tomador->documento);
    }

    public function test_rejeita_documento_invalido(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Documento do tomador inválido');
        new Tomador('123', 'X', $this->endereco());
    }

    public function test_rejeita_nome_vazio(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Nome do tomador vazio');
        new Tomador('44208855134', '   ', $this->endereco());
    }

    public function test_rejeita_email_invalido(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Email do tomador inválido');
        new Tomador(
            documento: '44208855134',
            nome: 'João',
            endereco: $this->endereco(),
            email: 'nao-eh-email',
        );
    }

    public function test_aceita_email_vazio_como_omissao(): void
    {
        $tomador = new Tomador(
            documento: '44208855134',
            nome: 'João',
            endereco: $this->endereco(),
            email: null,
        );
        self::assertNull($tomador->email);
    }
}
