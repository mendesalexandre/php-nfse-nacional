<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Dps;

use DOMDocument;
use DOMXPath;
use PhpNfseNacional\Config;
use PhpNfseNacional\Dps\EventoBuilder;
use PhpNfseNacional\Dps\EventoCancelamento;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\Ambiente;
use PHPUnit\Framework\TestCase;

final class EventoBuilderTest extends TestCase
{
    private const CHAVE_VALIDA = '35012345200001234567890123456789012345678123456789';
    private const CNPJ_PRESTADOR = '12345678000195';

    private function builder(): EventoBuilder
    {
        $endereco = new Endereco('Av Exemplo', '100', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: self::CNPJ_PRESTADOR,
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA EXEMPLO LTDA',
            endereco: $endereco,
        );
        return new EventoBuilder(new Config($prestador, Ambiente::Homologacao));
    }

    private function evento(): EventoCancelamento
    {
        return new EventoCancelamento(
            chaveAcesso: self::CHAVE_VALIDA,
            motivo: MotivoCancelamento::Outros,
            justificativa: 'Cancelamento de teste - justificativa minima',
        );
    }

    private function xpath(string $xml): DOMXPath
    {
        $dom = new DOMDocument();
        self::assertTrue($dom->loadXML($xml));
        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
        return $xpath;
    }

    public function test_build_gera_xml_valido_com_namespace(): void
    {
        $xml = $this->builder()->build($this->evento());
        $xpath = $this->xpath($xml);

        self::assertSame(1, $xpath->query('//n:pedRegEvento')->length);
        self::assertSame(1, $xpath->query('//n:infPedReg')->length);
        self::assertSame(1, $xpath->query('//n:e101101')->length);
    }

    public function test_id_segue_padrao_PRE_chave_tpEvento_59_chars(): void
    {
        $xml = $this->builder()->build($this->evento());
        $xpath = $this->xpath($xml);
        $infPedReg = $xpath->query('//n:infPedReg')->item(0);
        $id = $infPedReg?->attributes?->getNamedItem('Id')?->nodeValue ?? '';

        // PRE (3) + chave (50) + tpEvento (6) = 59 chars
        self::assertStringStartsWith('PRE' . self::CHAVE_VALIDA, $id);
        self::assertStringEndsWith('101101', $id);
        self::assertSame(59, strlen($id));
    }

    public function test_ordem_dos_filhos_do_infPedReg(): void
    {
        // Schema TSinfPedReg exige: tpAmb → verAplic → dhEvento → CNPJAutor → chNFSe → grupo
        $xml = $this->builder()->build($this->evento());
        $xpath = $this->xpath($xml);
        $children = $xpath->query('//n:infPedReg/*');
        self::assertNotFalse($children);

        $ordemEsperada = ['tpAmb', 'verAplic', 'dhEvento', 'CNPJAutor', 'chNFSe', 'e101101'];
        $ordemReal = [];
        foreach ($children as $child) {
            $ordemReal[] = $child->localName;
        }
        self::assertSame($ordemEsperada, $ordemReal);
    }

    public function test_chNFSe_e_cnpjautor_corretos(): void
    {
        $xml = $this->builder()->build($this->evento());
        $xpath = $this->xpath($xml);

        self::assertSame(
            self::CHAVE_VALIDA,
            $xpath->query('//n:chNFSe')->item(0)?->nodeValue,
        );
        self::assertSame(
            self::CNPJ_PRESTADOR,
            $xpath->query('//n:CNPJAutor')->item(0)?->nodeValue,
        );
        self::assertSame('2', $xpath->query('//n:tpAmb')->item(0)?->nodeValue);
    }

    public function test_dhEvento_em_brasilia_com_recuo_60s(): void
    {
        $xml = $this->builder()->build($this->evento());
        $xpath = $this->xpath($xml);
        $dh = $xpath->query('//n:dhEvento')->item(0)?->nodeValue ?? '';

        self::assertMatchesRegularExpression(
            '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}-03:00$/',
            $dh,
        );
    }

    public function test_grupo_e101101_tem_xDesc_cMotivo_xMotivo(): void
    {
        $xml = $this->builder()->build($this->evento());
        $xpath = $this->xpath($xml);

        self::assertSame(
            'Cancelamento de NFS-e',
            $xpath->query('//n:e101101/n:xDesc')->item(0)?->nodeValue,
        );
        self::assertSame(
            '9', // MotivoCancelamento::Outros
            $xpath->query('//n:e101101/n:cMotivo')->item(0)?->nodeValue,
        );
        self::assertStringContainsString(
            'Cancelamento de teste',
            $xpath->query('//n:e101101/n:xMotivo')->item(0)?->nodeValue ?? '',
        );
    }
}
