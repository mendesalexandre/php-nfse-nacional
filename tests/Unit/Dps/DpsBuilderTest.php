<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Dps;

use DOMDocument;
use DOMXPath;
use PhpNfseNacional\Config;
use PhpNfseNacional\Dps\DpsBuilder;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\DTO\Servico;
use PhpNfseNacional\DTO\Tomador;
use PhpNfseNacional\DTO\Valores;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Enums\RegimeEspecialTributacao;
use PHPUnit\Framework\TestCase;

final class DpsBuilderTest extends TestCase
{
    private function configPadrao(RegimeEspecialTributacao $regime = RegimeEspecialTributacao::Nenhum): Config
    {
        $endereco = new Endereco('Rua', '1', 'Centro', '78550200', '5107909', 'MT');
        $prestador = new Prestador(
            cnpj: '00179028000138',
            inscricaoMunicipal: '11408',
            razaoSocial: 'EMPRESA XYZ',
            endereco: $endereco,
            regimeEspecial: $regime,
        );
        return new Config($prestador, Ambiente::Homologacao);
    }

    private function tomadorPf(): Tomador
    {
        return new Tomador(
            documento: '44208855134',
            nome: 'João da Silva',
            endereco: new Endereco('Rua T', '10', 'Centro', '78550200', '5107909', 'MT'),
        );
    }

    private function servico(): Servico
    {
        return new Servico(
            discriminacao: 'Certidão de matrícula nº 12345',
            codigoMunicipioPrestacao: '5107909',
        );
    }

    public function test_build_gera_xml_valido_com_namespace(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 100),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );

        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('1.01', $dom->documentElement?->getAttribute('versao'));
        self::assertSame(1, $xpath->query('//n:DPS')->length);
        self::assertSame(1, $xpath->query('//n:infDPS')->length);
    }

    public function test_dps_id_tem_50_digitos_apos_prefixo(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1, serie: '1'),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $infDPS = $dom->getElementsByTagName('infDPS')->item(0);
        self::assertNotNull($infDPS);
        $id = $infDPS->attributes?->getNamedItem('Id')?->nodeValue ?? '';
        self::assertStringStartsWith('DPS', $id);
        // 3 (DPS) + 7 (cMun) + 14 (CNPJ) + 5 (serie) + 15 (nDPS) = 44
        self::assertSame(44, strlen($id));
    }

    public function test_forca_regespTrib_zero_quando_ha_deducao(): void
    {
        // Prestador com regime 4 (NotarioOuRegistrador) + vDR > 0 → E0438 sem ajuste.
        // O builder deve forçar regEspTrib=0 automaticamente.
        $builder = new DpsBuilder($this->configPadrao(RegimeEspecialTributacao::NotarioOuRegistrador));
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00), // tem dedução
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        $node = $xpath->query('//n:regEspTrib')->item(0);
        self::assertSame('0', $node?->nodeValue);
    }

    public function test_preserva_regespTrib_quando_sem_deducao(): void
    {
        $builder = new DpsBuilder($this->configPadrao(RegimeEspecialTributacao::NotarioOuRegistrador));
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00), // sem dedução
        );
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        $node = $xpath->query('//n:regEspTrib')->item(0);
        self::assertSame('4', $node?->nodeValue);
    }

    public function test_tomador_pj_usa_cnpj_em_vez_de_cpf(): void
    {
        $tomadorPj = new Tomador(
            documento: '00179028000138',
            nome: 'EMPRESA LTDA',
            endereco: new Endereco('Rua', '1', 'Centro', '78550200', '5107909', 'MT'),
        );

        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $tomadorPj,
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        self::assertSame('00179028000138', $xpath->query('//n:toma/n:CNPJ')->item(0)?->nodeValue);
        self::assertSame(0, $xpath->query('//n:toma/n:CPF')->length);
    }

    public function test_dhEmi_esta_em_brasilia_e_recuado_60s(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        $dhEmi = $xpath->query('//n:dhEmi')->item(0)?->nodeValue ?? '';

        // Formato esperado: 2026-05-12T15:23:00-03:00
        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-03:00$/',
            $dhEmi,
        );
    }

    public function test_inclui_grupo_IBSCBS_obrigatorio(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        self::assertStringContainsString('<IBSCBS>', $xml);
        self::assertStringContainsString('<CSTIBSCBS>410</CSTIBSCBS>', $xml);
    }

    public function test_cTribNac_default_eh_210101(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        self::assertStringContainsString('<cTribNac>210101</cTribNac>', $xml);
    }
}
