<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\DTO;

use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\RegimeEspecialTributacao;
use PhpNfseNacional\Enums\SituacaoSimplesNacional;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class PrestadorTest extends TestCase
{
    private function endereco(): Endereco
    {
        return new Endereco('R Exemplo', '100', 'Centro', '01310100', '3550308', 'MT');
    }

    public function test_prestador_valido(): void
    {
        $p = new Prestador(
            cnpj: '12.345.678/0001-95',
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA TESTE',
            endereco: $this->endereco(),
        );

        // Documento::limpar tira a máscara
        self::assertSame('12345678000195', $p->cnpj);
        self::assertSame(RegimeEspecialTributacao::Nenhum, $p->regimeEspecial);
        self::assertSame(SituacaoSimplesNacional::NaoOptante, $p->simplesNacional);
        self::assertFalse($p->incentivadorCultural);
    }

    public function test_cnpj_com_menos_de_14_digitos_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Prestador(
            cnpj: '123',
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA',
            endereco: $this->endereco(),
        );
    }

    public function test_inscricao_municipal_vazia_eh_normalizada_para_null(): void
    {
        $p = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '',
            razaoSocial: 'EMPRESA',
            endereco: $this->endereco(),
        );

        self::assertNull($p->inscricaoMunicipal);
    }

    public function test_inscricao_municipal_null_eh_aceita(): void
    {
        $p = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: null,
            razaoSocial: 'MEI ALEXANDRE TEIXEIRA',
            endereco: $this->endereco(),
            simplesNacional: SituacaoSimplesNacional::MEI,
        );

        self::assertNull($p->inscricaoMunicipal);
    }

    public function test_inscricao_municipal_com_espacos_eh_trimada(): void
    {
        $p = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '  12345  ',
            razaoSocial: 'EMPRESA',
            endereco: $this->endereco(),
        );

        self::assertSame('12345', $p->inscricaoMunicipal);
    }

    public function test_razao_social_vazia_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: '',
            endereco: $this->endereco(),
        );
    }

    public function test_propriedades_opcionais_sao_preservadas(): void
    {
        $p = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA',
            endereco: $this->endereco(),
            regimeEspecial: RegimeEspecialTributacao::NotarioOuRegistrador,
            simplesNacional: SituacaoSimplesNacional::MeEpp,
            incentivadorCultural: true,
            email: 'empresa@example.com',
            telefone: '(11) 4004-0100',
        );

        self::assertSame(RegimeEspecialTributacao::NotarioOuRegistrador, $p->regimeEspecial);
        self::assertSame(SituacaoSimplesNacional::MeEpp, $p->simplesNacional);
        self::assertTrue($p->incentivadorCultural);
        self::assertSame('empresa@example.com', $p->email);
    }
}
