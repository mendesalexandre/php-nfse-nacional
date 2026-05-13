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
        return new Endereco('R Nogueiras', '1108', 'Centro', '78550200', '5107909', 'MT');
    }

    public function test_prestador_valido(): void
    {
        $p = new Prestador(
            cnpj: '00.179.028/0001-38',
            inscricaoMunicipal: '11408',
            razaoSocial: 'CARTORIO TESTE',
            endereco: $this->endereco(),
        );

        // Documento::limpar tira a máscara
        self::assertSame('00179028000138', $p->cnpj);
        self::assertSame(RegimeEspecialTributacao::Nenhum, $p->regimeEspecial);
        self::assertSame(SituacaoSimplesNacional::NaoOptante, $p->simplesNacional);
        self::assertFalse($p->incentivadorCultural);
    }

    public function test_cnpj_com_menos_de_14_digitos_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new Prestador(
            cnpj: '123',
            inscricaoMunicipal: '11408',
            razaoSocial: 'CARTORIO',
            endereco: $this->endereco(),
        );
    }

    public function test_inscricao_municipal_vazia_eh_normalizada_para_null(): void
    {
        $p = new Prestador(
            cnpj: '00179028000138',
            inscricaoMunicipal: '',
            razaoSocial: 'CARTORIO',
            endereco: $this->endereco(),
        );

        self::assertNull($p->inscricaoMunicipal);
    }

    public function test_inscricao_municipal_null_eh_aceita(): void
    {
        $p = new Prestador(
            cnpj: '00179028000138',
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
            cnpj: '00179028000138',
            inscricaoMunicipal: '  11408  ',
            razaoSocial: 'CARTORIO',
            endereco: $this->endereco(),
        );

        self::assertSame('11408', $p->inscricaoMunicipal);
    }

    public function test_razao_social_vazia_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new Prestador(
            cnpj: '00179028000138',
            inscricaoMunicipal: '11408',
            razaoSocial: '',
            endereco: $this->endereco(),
        );
    }

    public function test_propriedades_opcionais_sao_preservadas(): void
    {
        $p = new Prestador(
            cnpj: '00179028000138',
            inscricaoMunicipal: '11408',
            razaoSocial: 'CARTORIO',
            endereco: $this->endereco(),
            regimeEspecial: RegimeEspecialTributacao::NotarioOuRegistrador,
            simplesNacional: SituacaoSimplesNacional::MeEpp,
            incentivadorCultural: true,
            email: 'cartorio@example.com',
            telefone: '(66) 3531-1108',
        );

        self::assertSame(RegimeEspecialTributacao::NotarioOuRegistrador, $p->regimeEspecial);
        self::assertSame(SituacaoSimplesNacional::MeEpp, $p->simplesNacional);
        self::assertTrue($p->incentivadorCultural);
        self::assertSame('cartorio@example.com', $p->email);
    }
}
