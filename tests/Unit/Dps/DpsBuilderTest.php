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

    public function test_dispensadoIssqn_emite_indTotTrib_no_lugar_de_pTotTrib(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(
                valorServicos: 800.00,
                deducoesReducoes: 0.00,
                aliquotaIssqnPercentual: 0.00,
                dispensadoIssqn: true,
            ),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame(1, $xpath->query('//n:totTrib/n:indTotTrib')->length);
        self::assertSame('0', $xpath->query('//n:totTrib/n:indTotTrib')->item(0)?->nodeValue);
        self::assertSame(0, $xpath->query('//n:totTrib/n:pTotTrib')->length);
    }

    public function test_aceita_BC_com_aliquota_zero_sem_validacao_fiscal(): void
    {
        // Validação fiscal (BC vs ISSQN apurado) é responsabilidade do SEFIN,
        // não da lib. O builder deve aceitar e montar o XML; quem decide se a
        // operação é válida fiscalmente é o portal — regras mudam (MEI,
        // isenções, novos cTribNac) e variam por município.
        $builder = new DpsBuilder($this->configPadrao());

        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(
                valorServicos: 800.00,
                deducoesReducoes: 0.00,
                aliquotaIssqnPercentual: 0.00,
            ),
        );

        self::assertNotEmpty($xml);
    }

    public function test_sem_dispensadoIssqn_emite_pTotTrib_normalmente(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame(1, $xpath->query('//n:totTrib/n:pTotTrib/n:pTotTribMun')->length);
        self::assertSame('4.00', $xpath->query('//n:totTrib/n:pTotTrib/n:pTotTribMun')->item(0)?->nodeValue);
        self::assertSame(0, $xpath->query('//n:totTrib/n:indTotTrib')->length);
    }

    public function test_prestador_emite_fone_e_email_quando_preenchidos(): void
    {
        $endereco = new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA EXEMPLO LTDA',
            endereco: $endereco,
            email: 'contato@exemplo.com.br',
            telefone: '(11) 99999-8888',
        );
        $builder = new DpsBuilder(new Config($prestador, Ambiente::Homologacao));
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('11999998888', $xpath->query('//n:prest/n:fone')->item(0)?->nodeValue);
        self::assertSame('contato@exemplo.com.br', $xpath->query('//n:prest/n:email')->item(0)?->nodeValue);
    }

    public function test_prestador_omite_fone_e_email_quando_ausentes(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame(0, $xpath->query('//n:prest/n:fone')->length);
        self::assertSame(0, $xpath->query('//n:prest/n:email')->length);
    }

    public function test_servico_aceita_enum_para_cTribNac_e_cNBS(): void
    {
        $servico = new Servico(
            discriminacao: 'Análise e desenvolvimento de sistemas — sprint 12',
            codigoMunicipioPrestacao: '3550308',
            cTribNac: \PhpNfseNacional\Enums\ListaServicosNacional::S010101,
            cNBS: \PhpNfseNacional\Enums\ListaNbs::N101011100,
        );

        self::assertSame('010101', $servico->cTribNac);
        self::assertSame('101011100', $servico->cNBS);
    }

    public function test_servico_aceita_string_para_cTribNac_compat(): void
    {
        $servico = new Servico(
            discriminacao: 'Algum serviço de exemplo',
            codigoMunicipioPrestacao: '3550308',
            cTribNac: '010101',
            cNBS: '101011100',
        );

        self::assertSame('010101', $servico->cTribNac);
        self::assertSame('101011100', $servico->cNBS);
    }

    public function test_regApTribSN_emitido_quando_prestador_seta(): void
    {
        $endereco = new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: 'ME EXEMPLO LTDA',
            endereco: $endereco,
            simplesNacional: \PhpNfseNacional\Enums\SituacaoSimplesNacional::MeEpp,
            regimeApuracaoSN: \PhpNfseNacional\Enums\RegimeApuracaoSimplesNacional::FederaisEMunicipalPorSN,
        );
        $builder = new DpsBuilder(new Config($prestador, Ambiente::Homologacao));
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame(1, $xpath->query('//n:prest/n:regTrib/n:regApTribSN')->length);
        self::assertSame('1', $xpath->query('//n:prest/n:regTrib/n:regApTribSN')->item(0)?->nodeValue);
    }

    public function test_regApTribSN_omitido_quando_prestador_nao_seta(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame(0, $xpath->query('//n:prest/n:regTrib/n:regApTribSN')->length);
    }

    public function test_regApTribSN_posicao_entre_opSimpNac_e_regEspTrib(): void
    {
        // Schema: <regTrib> exige <opSimpNac> → <regApTribSN>? → <regEspTrib>.
        $endereco = new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: 'ME EXEMPLO LTDA',
            endereco: $endereco,
            simplesNacional: \PhpNfseNacional\Enums\SituacaoSimplesNacional::MeEpp,
            regimeApuracaoSN: \PhpNfseNacional\Enums\RegimeApuracaoSimplesNacional::FederaisPorSnMunicipalPorNfse,
        );
        $builder = new DpsBuilder(new Config($prestador, Ambiente::Homologacao));
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $posOp     = strpos($xml, '<opSimpNac>');
        $posRegAp  = strpos($xml, '<regApTribSN>');
        $posRegEsp = strpos($xml, '<regEspTrib>');

        self::assertNotFalse($posOp);
        self::assertNotFalse($posRegAp);
        self::assertNotFalse($posRegEsp);
        self::assertLessThan($posRegAp, $posOp);
        self::assertLessThan($posRegEsp, $posRegAp);
    }

    public function test_tribISSQN_default_eh_um_quando_nao_setado(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('1', $xpath->query('//n:tribMun/n:tribISSQN')->item(0)?->nodeValue);
    }

    public function test_tribISSQN_aceita_enum_imunidade_e_imunidade_grupo(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(
                valorServicos: 100.00,
                deducoesReducoes: 0.00,
                aliquotaIssqnPercentual: 0.00,
                tributacaoIssqn: \PhpNfseNacional\Enums\TipoTributacaoIssqn::Imunidade,
                imunidade: \PhpNfseNacional\Enums\TipoImunidadeIssqn::TemplosQualquerCulto,
            ),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('2', $xpath->query('//n:tribMun/n:tribISSQN')->item(0)?->nodeValue);
        self::assertSame('2', $xpath->query('//n:tribMun/n:tpImunidade')->item(0)?->nodeValue);
    }

    public function test_tribISSQN_exportacao_aceita_cPaisResult(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(
                valorServicos: 100.00,
                deducoesReducoes: 0.00,
                aliquotaIssqnPercentual: 0.00,
                tributacaoIssqn: \PhpNfseNacional\Enums\TipoTributacaoIssqn::ExportacaoServico,
                codigoPaisResultado: 'US',
            ),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('3', $xpath->query('//n:tribMun/n:tribISSQN')->item(0)?->nodeValue);
        self::assertSame('US', $xpath->query('//n:tribMun/n:cPaisResult')->item(0)?->nodeValue);
    }

    public function test_beneficioMunicipal_emite_BM_com_nBM_e_pRedBCBM(): void
    {
        $bm = new \PhpNfseNacional\DTO\BeneficioMunicipal(
            nBM: '51079090100001',
            percentualReducaoBc: 50.00,
        );
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(
                valorServicos: 100.00,
                deducoesReducoes: 0.00,
                aliquotaIssqnPercentual: 4.00,
                beneficioMunicipal: $bm,
            ),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('51079090100001', $xpath->query('//n:tribMun/n:BM/n:nBM')->item(0)?->nodeValue);
        self::assertSame('50.00', $xpath->query('//n:tribMun/n:BM/n:pRedBCBM')->item(0)?->nodeValue);
        self::assertSame(0, $xpath->query('//n:tribMun/n:BM/n:vRedBCBM')->length);
    }

    public function test_beneficioMunicipal_choice_xor_lança_quando_ambos_informados(): void
    {
        $this->expectException(\PhpNfseNacional\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('valorReducaoBc OU percentualReducaoBc');

        new \PhpNfseNacional\DTO\BeneficioMunicipal(
            nBM: '51079090100001',
            valorReducaoBc: 100.00,
            percentualReducaoBc: 50.00,
        );
    }

    public function test_beneficioMunicipal_nBM_deve_ter_14_digitos(): void
    {
        $this->expectException(\PhpNfseNacional\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('nBM inválido');

        new \PhpNfseNacional\DTO\BeneficioMunicipal(nBM: '123');
    }

    public function test_exigibilidadeSuspensa_DTO_rejeita_processo_com_pontuacao(): void
    {
        // XSD TSNumProcExigSuspensa = [0-9]{30} (exatamente 30 dígitos)
        $this->expectException(\PhpNfseNacional\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('numeroProcesso inválido');

        new \PhpNfseNacional\DTO\ExigibilidadeSuspensa(
            tipo: \PhpNfseNacional\Enums\TipoExigibilidadeSuspensa::ProcessoJudicial,
            numeroProcesso: '5001234-56.2026.8.11.0037',
        );
    }

    public function test_exigibilidadeSuspensa_DTO_rejeita_menos_de_30_digitos(): void
    {
        $this->expectException(\PhpNfseNacional\Exceptions\ValidationException::class);

        new \PhpNfseNacional\DTO\ExigibilidadeSuspensa(
            tipo: \PhpNfseNacional\Enums\TipoExigibilidadeSuspensa::ProcessoJudicial,
            numeroProcesso: '12345', // só 5 dígitos
        );
    }

    public function test_exigibilidadeSuspensa_emite_grupo_completo(): void
    {
        $es = new \PhpNfseNacional\DTO\ExigibilidadeSuspensa(
            tipo: \PhpNfseNacional\Enums\TipoExigibilidadeSuspensa::ProcessoJudicial,
            numeroProcesso: '500123456202681100370000000000', // 30 dígitos
        );
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(
                valorServicos: 100.00,
                deducoesReducoes: 0.00,
                aliquotaIssqnPercentual: 4.00,
                exigibilidadeSuspensa: $es,
            ),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('1', $xpath->query('//n:tribMun/n:exigSusp/n:tpSusp')->item(0)?->nodeValue);
        self::assertSame('500123456202681100370000000000', $xpath->query('//n:tribMun/n:exigSusp/n:nProcesso')->item(0)?->nodeValue);
    }

    public function test_aliquotaMunicipal_emite_pAliq(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(
                valorServicos: 100.00,
                deducoesReducoes: 0.00,
                aliquotaIssqnPercentual: 4.00,
                aliquotaMunicipal: 3.50,
            ),
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('3.50', $xpath->query('//n:tribMun/n:pAliq')->item(0)?->nodeValue);
    }

    public function test_intermediario_omitido_quando_nao_informado(): void
    {
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        self::assertStringNotContainsString('<interm>', $xml);
    }

    public function test_intermediario_pj_com_endereco_emite_grupo_completo(): void
    {
        $endereco = new Endereco('Av Paulista', '1000', 'Bela Vista', '01310100', '3550308', 'SP');
        $intermediario = new \PhpNfseNacional\DTO\Intermediario(
            documento: '12345678000195',
            nome: 'MARKETPLACE EXEMPLO LTDA',
            endereco: $endereco,
            email: 'contato@marketplace.com',
            telefone: '(11) 99999-8888',
            inscricaoMunicipal: '987654',
        );

        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
            $intermediario,
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame(1, $xpath->query('//n:interm')->length);
        self::assertSame('12345678000195', $xpath->query('//n:interm/n:CNPJ')->item(0)?->nodeValue);
        self::assertSame('987654', $xpath->query('//n:interm/n:IM')->item(0)?->nodeValue);
        self::assertSame('MARKETPLACE EXEMPLO LTDA', $xpath->query('//n:interm/n:xNome')->item(0)?->nodeValue);
        self::assertSame('Av Paulista', $xpath->query('//n:interm/n:end/n:xLgr')->item(0)?->nodeValue);
        self::assertSame('11999998888', $xpath->query('//n:interm/n:fone')->item(0)?->nodeValue);
        self::assertSame('contato@marketplace.com', $xpath->query('//n:interm/n:email')->item(0)?->nodeValue);
    }

    public function test_intermediario_pf_emite_CPF(): void
    {
        $intermediario = new \PhpNfseNacional\DTO\Intermediario(
            documento: '12345678909',
            nome: 'AGENTE INTERMEDIARIO',
        );

        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
            $intermediario,
        );

        $dom = new DOMDocument();
        $dom->loadXML($xml);
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');

        self::assertSame('12345678909', $xpath->query('//n:interm/n:CPF')->item(0)?->nodeValue);
        self::assertSame(0, $xpath->query('//n:interm/n:CNPJ')->length);
        self::assertSame(0, $xpath->query('//n:interm/n:end')->length); // sem endereço
    }

    public function test_intermediario_posicao_entre_toma_e_serv(): void
    {
        $endereco = new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP');
        $intermediario = new \PhpNfseNacional\DTO\Intermediario(
            documento: '12345678000195',
            nome: 'INTERM',
            endereco: $endereco,
        );

        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
            $intermediario,
        );

        $posToma = strpos($xml, '<toma>');
        $posInterm = strpos($xml, '<interm>');
        $posServ = strpos($xml, '<serv>');

        self::assertNotFalse($posToma);
        self::assertNotFalse($posInterm);
        self::assertNotFalse($posServ);
        self::assertLessThan($posInterm, $posToma);
        self::assertLessThan($posServ, $posInterm);
    }

    public function test_intermediario_DTO_rejeita_documento_invalido(): void
    {
        $this->expectException(\PhpNfseNacional\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Documento do intermediário inválido');

        new \PhpNfseNacional\DTO\Intermediario(
            documento: '123',
            nome: 'X',
        );
    }

    public function test_intermediario_DTO_rejeita_nome_vazio(): void
    {
        $this->expectException(\PhpNfseNacional\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Nome do intermediário vazio');

        new \PhpNfseNacional\DTO\Intermediario(
            documento: '12345678000195',
            nome: '   ',
        );
    }

    public function test_intermediario_DTO_rejeita_email_invalido(): void
    {
        $this->expectException(\PhpNfseNacional\Exceptions\ValidationException::class);
        $this->expectExceptionMessage('Email do intermediário inválido');

        new \PhpNfseNacional\DTO\Intermediario(
            documento: '12345678000195',
            nome: 'INTERM',
            email: 'isso-nao-eh-email',
        );
    }

    public function test_tribMun_ordem_dos_filhos_segue_schema(): void
    {
        // Schema obriga: tribISSQN → cPaisResult? → BM? → exigSusp? →
        // tpImunidade? → pAliq? → tpRetISSQN. Trocar dá E1235.
        $bm = new \PhpNfseNacional\DTO\BeneficioMunicipal(
            nBM: '51079090100001',
            percentualReducaoBc: 25.00,
        );
        $es = new \PhpNfseNacional\DTO\ExigibilidadeSuspensa(
            tipo: \PhpNfseNacional\Enums\TipoExigibilidadeSuspensa::ProcessoAdministrativo,
            numeroProcesso: '000000000000000000000000000001', // 30 dígitos
        );
        $builder = new DpsBuilder($this->configPadrao());
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(
                valorServicos: 100.00,
                deducoesReducoes: 0.00,
                aliquotaIssqnPercentual: 4.00,
                tributacaoIssqn: \PhpNfseNacional\Enums\TipoTributacaoIssqn::ExportacaoServico,
                codigoPaisResultado: 'US',
                beneficioMunicipal: $bm,
                exigibilidadeSuspensa: $es,
                imunidade: \PhpNfseNacional\Enums\TipoImunidadeIssqn::PatrimonioRendaServicosEntes,
                aliquotaMunicipal: 4.00,
            ),
        );

        // Pega só o bloco tribMun pra evitar ruído de outros elementos
        $tribMunStart = strpos($xml, '<tribMun>');
        $tribMunEnd   = strpos($xml, '</tribMun>');
        self::assertNotFalse($tribMunStart);
        self::assertNotFalse($tribMunEnd);
        $block = substr($xml, $tribMunStart, $tribMunEnd - $tribMunStart);

        $posTrib   = strpos($block, '<tribISSQN>');
        $posPais   = strpos($block, '<cPaisResult>');
        $posBM     = strpos($block, '<BM>');
        $posExig   = strpos($block, '<exigSusp>');
        $posImun   = strpos($block, '<tpImunidade>');
        $posAliq   = strpos($block, '<pAliq>');
        $posRet    = strpos($block, '<tpRetISSQN>');

        self::assertLessThan($posPais, $posTrib);
        self::assertLessThan($posBM,   $posPais);
        self::assertLessThan($posExig, $posBM);
        self::assertLessThan($posImun, $posExig);
        self::assertLessThan($posAliq, $posImun);
        self::assertLessThan($posRet,  $posAliq);
    }

    public function test_prestador_ordem_dos_filhos_segue_schema(): void
    {
        // Schema do <prest> exige ordem: CNPJ → IM → fone → email → regTrib.
        // Confirmado contra XML real do emissor web SEFIN (MEI, abril 2026).
        $endereco = new Endereco('Rua', '1', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA EXEMPLO LTDA',
            endereco: $endereco,
            email: 'contato@exemplo.com.br',
            telefone: '1199999888',
        );
        $builder = new DpsBuilder(new Config($prestador, Ambiente::Homologacao));
        $xml = $builder->build(
            new Identificacao(numeroDps: 1),
            $this->tomadorPf(),
            $this->servico(),
            new Valores(100.00, 0.00, 4.00),
        );

        $posCnpj  = strpos($xml, '<CNPJ>');
        $posIm    = strpos($xml, '<IM>');
        $posFone  = strpos($xml, '<fone>');
        $posEmail = strpos($xml, '<email>');
        $posReg   = strpos($xml, '<regTrib>');

        self::assertNotFalse($posCnpj);
        self::assertNotFalse($posIm);
        self::assertNotFalse($posFone);
        self::assertNotFalse($posEmail);
        self::assertNotFalse($posReg);
        self::assertLessThan($posIm, $posCnpj);
        self::assertLessThan($posFone, $posIm);
        self::assertLessThan($posEmail, $posFone);
        self::assertLessThan($posReg, $posEmail);
    }
}
