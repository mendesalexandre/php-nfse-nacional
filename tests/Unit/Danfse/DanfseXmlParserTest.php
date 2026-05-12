<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Danfse\DanfseXmlParser;
use PhpNfseNacional\Exceptions\NfseException;
use PHPUnit\Framework\TestCase;

final class DanfseXmlParserTest extends TestCase
{
    private function xmlAutorizado(): string
    {
        $path = __DIR__ . '/../../fixtures/nfse-autorizada.xml';
        $conteudo = file_get_contents($path);
        self::assertNotFalse($conteudo, "Fixture {$path} não encontrada");
        return $conteudo;
    }

    public function test_xml_invalido_lanca_excecao(): void
    {
        $this->expectException(NfseException::class);
        (new DanfseXmlParser())->parse('<nao-eh-xml-valido');
    }

    public function test_identificacao_extrai_chave_numero_e_codigo_verificacao(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertSame('35012345200001234567890123456789012345678123456789', $dados->chave());
        self::assertSame('123', $dados->numero());
        // O retorno SEFIN traz cStat=100 quando emitida
        self::assertSame('100', $dados->identificacao['cStat']);
    }

    public function test_prestador_extrai_cnpj_e_endereco(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertSame('12345678000195', $dados->prestador['documento']);
        self::assertSame('CNPJ', $dados->prestador['tipo_documento']);
        self::assertSame('12345', $dados->prestador['inscricao_municipal']);
        self::assertSame('01310100', $dados->prestador['cep']);
        self::assertSame('SP', $dados->prestador['uf']);
    }

    public function test_tomador_extrai_cpf_e_marca_tipo_documento(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertSame('12345678909', $dados->tomador['documento']);
        self::assertSame('CPF', $dados->tomador['tipo_documento']);
    }

    public function test_servico_extrai_cTribNac_210101(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        // 21.01.01 — serviços de registros públicos (item da lista LC 116/2003)
        self::assertSame('210101', $dados->servico['codigo_tributacao_nacional']);
        self::assertNotEmpty($dados->servico['descricao_servico']);
    }

    public function test_valor_total_extraido_corretamente(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        // Valores do fixture: vBC=23,08, ISSQN=0,92, vServ=32,97, vDR=9,89, aliq=4%
        // (cenário "ISSQN por dentro" — vDR inclui o ISSQN na dedução redutora)
        self::assertSame(32.97, $dados->valorTotal['valor_servicos']);
        self::assertSame(32.97, $dados->valorTotal['valor_liquido']);
        self::assertSame(0.92, $dados->valorTotal['issqn_apurado']);
    }

    public function test_tributacao_municipal_extraida(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertSame(23.08, $dados->tributacaoMunicipal['base_calculo_issqn']);
        self::assertSame(0.92, $dados->tributacaoMunicipal['issqn_apurado']);
        self::assertSame(4.0, $dados->tributacaoMunicipal['aliquota_aplicada']);
        self::assertSame(9.89, $dados->tributacaoMunicipal['total_deducoes_reducoes']);
    }

    public function test_cancelada_false_quando_cStat_100(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());
        self::assertFalse($dados->cancelada);
    }

    public function test_qr_code_url_aponta_pro_portal_nacional(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());
        self::assertNotNull($dados->qrCodeUrl);
        self::assertStringContainsString('nfse.gov.br', $dados->qrCodeUrl);
        self::assertStringContainsString($dados->chave() ?? 'N/A', $dados->qrCodeUrl);
    }
}
