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
        $endereco = new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA EXEMPLO LTDA',
            endereco: $endereco,
            regimeEspecial: $regime,
        );
        return new Config($prestador, Ambiente::Homologacao);
    }

    private function tomadorPf(): Tomador
    {
        return new Tomador(
            documento: '12345678909',
            nome: 'João da Silva',
            endereco: new Endereco('Rua T', '10', 'Centro', '01310100', '3550308', 'SP'),
        );
    }

    private function servico(): Servico
    {
        return new Servico(
            discriminacao: 'Certidão de matrícula nº 12345',
            codigoMunicipioPrestacao: '3550308',
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
        // 3 (DPS) + 7 (cMun) + 1 (tipoInsc) + 14 (CNPJ) + 5 (serie) + 15 (nDPS) = 45
        self::assertSame(45, strlen($id));
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
            documento: '12345678000195',
            nome: 'EMPRESA LTDA',
            endereco: new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP'),
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
        self::assertSame('12345678000195', $xpath->query('//n:toma/n:CNPJ')->item(0)?->nodeValue);
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

    public function test_dCompet_usa_timezone_do_dhEmi_evita_E0015(): void
    {
        // Regressão: PHP em UTC + horário noturno causava dCompet > dhEmi.date.
        // Servidor em UTC à 01:00 (= 22:00 SP do dia anterior). Sem fix, dCompet
        // formatava no tz default (UTC, dia seguinte) enquanto dhEmi recuado
        // ficava em SP no dia anterior — SEFIN rejeitava com E0015.
        $tzOriginal = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            // Hora UTC pós-meia-noite mas antes das 03:00 (SP ainda no dia anterior)
            $dataCompetUtc = new \DateTimeImmutable('2026-05-13 01:00:00', new \DateTimeZone('UTC'));

            $builder = new DpsBuilder($this->configPadrao());
            $xml = $builder->build(
                new Identificacao(numeroDps: 1, dataCompetencia: $dataCompetUtc),
                $this->tomadorPf(),
                $this->servico(),
                new Valores(100.00, 20.00, 4.00),
            );
            $dom = new DOMDocument();
            $dom->loadXML($xml);
            $xpath = new DOMXPath($dom);
            $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
            $dCompet = $xpath->query('//n:dCompet')->item(0)?->nodeValue ?? '';

            // Em SP, 2026-05-13 01:00 UTC = 2026-05-12 22:00 -03:00 → "2026-05-12"
            self::assertSame('2026-05-12', $dCompet);
        } finally {
            date_default_timezone_set($tzOriginal);
        }
    }

    public function test_omite_IBSCBS_por_padrao(): void
    {
        // Default: incluirIbsCbs=false. SEFIN aceita DPS sem o bloco.
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        self::assertStringNotContainsString('<IBSCBS>', $xml);
    }

    public function test_inclui_IBSCBS_quando_toggle_ligado(): void
    {
        $endereco = new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA EXEMPLO LTDA',
            endereco: $endereco,
        );
        $config = new Config(
            prestador: $prestador,
            ambiente: Ambiente::Homologacao,
            incluirIbsCbs: true,
        );
        $builder = new DpsBuilder($config);
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        self::assertStringContainsString('<IBSCBS>', $xml);
        self::assertStringContainsString('<CST>000</CST>', $xml);
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
