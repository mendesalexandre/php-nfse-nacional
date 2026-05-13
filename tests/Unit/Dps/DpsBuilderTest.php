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

    public function test_dhEmi_aceita_override_via_Identificacao_dataEmissao(): void
    {
        // Cenário "contingência": DPS gerada offline com dhEmi de ontem,
        // enviada quando rede voltou. SDK deve usar o override em vez de now().
        $tz = new \DateTimeZone('America/Sao_Paulo');
        $ontemMeiaTarde = new \DateTimeImmutable('2026-05-12 14:30:00', $tz);

        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(
                numeroDps: 1,
                dataCompetencia: $ontemMeiaTarde,
                dataEmissao: $ontemMeiaTarde,
            ),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        $dhEmi = $xpath->query('//n:dhEmi')->item(0)?->nodeValue ?? '';

        // Override exato (sem margem de 60s) e em SP -03:00
        self::assertSame('2026-05-12T14:30:00-03:00', $dhEmi);
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

    public function test_pTotTribMun_formatado_com_2_casas_decimais(): void
    {
        // SefinNacional 1.6 restringe pTotTrib* ao tipo TSDec3V2 (exatamente
        // 2 casas decimais). Diferente da NF-e (NT 03.14, 4 casas) — confirmado
        // empiricamente em homologação SEFIN: passar '4.0000' resulta em E1235
        // ("Pattern constraint failed").
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        self::assertStringContainsString('<pTotTribMun>4.00</pTotTribMun>', $xml);
        self::assertStringContainsString('<pTotTribFed>0.00</pTotTribFed>', $xml);
        self::assertStringContainsString('<pTotTribEst>0.00</pTotTribEst>', $xml);
    }

    public function test_pTotTribMun_arredonda_aliquota_com_mais_de_2_casas(): void
    {
        // Alíquota 3.5125% (redução tributária) — number_format arredonda HALF_UP
        // pra 2 casas. Caveat conhecido do leiaute (TSDec3V2).
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 3.5125),
        );
        // 3.5125 → 3.51 (HALF_UP da 3ª casa decimal)
        self::assertStringContainsString('<pTotTribMun>3.51</pTotTribMun>', $xml);
    }

    public function test_tomador_IM_omitida_quando_null(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $tomador = new Tomador(
            documento: '12345678909',
            nome: 'João',
            endereco: new Endereco('R', '1', 'C', '01310100', '3550308', 'SP'),
        );
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $tomador,
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        // <toma> existe mas SEM <IM>
        self::assertStringContainsString('<toma>', $xml);
        $tomaBlock = substr($xml, strpos($xml, '<toma>'), strpos($xml, '</toma>') - strpos($xml, '<toma>'));
        self::assertStringNotContainsString('<IM>', $tomaBlock);
    }

    public function test_tomador_IM_emitida_quando_preenchida(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $tomador = new Tomador(
            documento: '12345678000195',
            nome: 'EMPRESA TOMADORA LTDA',
            endereco: new Endereco('R', '1', 'C', '01310100', '3550308', 'SP'),
            inscricaoMunicipal: '987654',
        );
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $tomador,
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );
        self::assertStringContainsString('<IM>987654</IM>', $xml);
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

    public function test_prestador_IM_omitida_quando_null(): void
    {
        $endereco = new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: null,
            razaoSocial: 'MEI ALEXANDRE TEIXEIRA',
            endereco: $endereco,
            simplesNacional: \PhpNfseNacional\Enums\SituacaoSimplesNacional::MEI,
        );
        $builder = new DpsBuilder(new Config($prestador, Ambiente::Homologacao));
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $prestBlock = substr($xml, strpos($xml, '<prest>'), strpos($xml, '</prest>') - strpos($xml, '<prest>'));
        self::assertStringNotContainsString('<IM>', $prestBlock);
    }

    public function test_prestador_IM_emitida_quando_preenchida(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 20.00, 4.00),
        );

        $prestBlock = substr($xml, strpos($xml, '<prest>'), strpos($xml, '</prest>') - strpos($xml, '<prest>'));
        self::assertStringContainsString('<IM>12345</IM>', $prestBlock);
    }
}
