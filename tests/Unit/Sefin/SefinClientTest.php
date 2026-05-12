<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Sefin;

use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Sefin\SefinResposta;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

/**
 * Testes do parser de respostas do SEFIN.
 *
 * `parsearResposta` é privado — testamos via Reflection. São regressions
 * de bugs reais que apareceram em homologação:
 *   - erro singular `erro` (formato do endpoint de eventos)
 *   - erros plural `erros` (formato da emissão)
 *   - sucesso com `*XmlGZipB64` em JSON pretty-printed (regex antigo quebrava)
 *   - E0840 (cancelamento já vinculado) tratado como cStat=840
 */
final class SefinClientTest extends TestCase
{
    private function client(): SefinClient
    {
        $endereco = new Endereco('Av Exemplo', '100', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '12345678000195',
            inscricaoMunicipal: '12345',
            razaoSocial: 'EMPRESA EXEMPLO LTDA',
            endereco: $endereco,
        );
        $config = new Config($prestador, Ambiente::Homologacao);

        // Certificate dummy — não vai ser usado (não chamamos métodos HTTP)
        $cert = new Certificate(
            privateKeyPem: '-----BEGIN PRIVATE KEY-----\nDUMMY\n-----END PRIVATE KEY-----',
            certificatePem: '-----BEGIN CERTIFICATE-----\nDUMMY\n-----END CERTIFICATE-----',
            validade: new \DateTimeImmutable('+1 year'),
            cnpj: '12345678000195',
            subjectCN: 'TESTE',
        );

        return new SefinClient($config, $cert, new SefinEndpoints(Ambiente::Homologacao));
    }

    private function parse(string $body): SefinResposta
    {
        $ref = new ReflectionClass(SefinClient::class);
        $method = $ref->getMethod('parsearResposta');
        $method->setAccessible(true);
        /** @var SefinResposta $r */
        $r = $method->invoke($this->client(), $body);
        return $r;
    }

    public function test_erro_singular_evento_E0840_extrai_cStat_840(): void
    {
        $body = json_encode([
            'tipoAmbiente' => 2,
            'dataHoraProcessamento' => '2026-05-12T17:08:15-03:00',
            'erro' => [[
                'codigo' => 'E0840',
                'descricao' => 'evento de Cancelamento de NFS-e já está vinculado',
            ]],
        ]);
        self::assertNotFalse($body);

        $r = $this->parse($body);
        self::assertSame(840, $r->cStat);
        self::assertNotNull($r->xMotivo);
        self::assertStringContainsString('já está vinculado', $r->xMotivo);
    }

    public function test_erro_plural_emissao_E1235_extrai_cStat_1235(): void
    {
        $body = json_encode([
            'tipoAmbiente' => 2,
            'erros' => [[
                'Codigo' => 'E1235',
                'Descricao' => 'Falha no esquema XML do DF-e',
                'Complemento' => 'Detalhe do erro',
            ]],
        ]);
        self::assertNotFalse($body);

        $r = $this->parse($body);
        self::assertSame(1235, $r->cStat);
        self::assertNotNull($r->xMotivo);
        self::assertStringContainsString('Falha no esquema', $r->xMotivo);
    }

    public function test_erro_aceita_chave_codigo_lowercase_e_uppercase(): void
    {
        // Endpoint de eventos usa lowercase, endpoint de emissão usa Capitalizado.
        $payloads = [
            ['erro' => [['codigo' => 'E0840', 'descricao' => 'lowercase']]],
            ['erro' => [['Codigo' => 'E0840', 'Descricao' => 'uppercase']]],
        ];
        foreach ($payloads as $payload) {
            $json = json_encode($payload);
            self::assertNotFalse($json);
            $r = $this->parse($json);
            self::assertSame(840, $r->cStat);
        }
    }

    public function test_sucesso_emissao_pretty_printed_descomprime_xml(): void
    {
        // JSON pretty-printed (com espaço após `:`) — o bug original era um
        // regex `":"` que não casava com `": "`.
        $xmlInterno = '<?xml version="1.0"?><NFSe versao="1.01" xmlns="http://www.sped.fazenda.gov.br/nfse">'
            . '<infNFSe Id="NFS35012345200001234567890123456789012345678123456789">'
            . '<nNFSe>123</nNFSe><cStat>100</cStat><cVerif>ABC123</cVerif></infNFSe></NFSe>';
        $gz = gzencode($xmlInterno);
        self::assertNotFalse($gz);

        $body = json_encode([
            'tipoAmbiente' => 2,
            'chaveAcesso' => '35012345200001234567890123456789012345678123456789',
            'dataHoraProcessamento' => '2026-05-12T17:00:00-03:00',
            'nfseXmlGZipB64' => base64_encode($gz),
        ], JSON_PRETTY_PRINT);
        self::assertNotFalse($body);

        $r = $this->parse($body);
        self::assertSame(100, $r->cStat);
        self::assertSame('35012345200001234567890123456789012345678123456789', $r->chaveAcesso);
        self::assertSame('123', $r->numeroNfse);
        self::assertSame('ABC123', $r->codigoVerificacao);
        self::assertNotNull($r->xmlRetorno);
        self::assertStringContainsString('<nNFSe>123</nNFSe>', $r->xmlRetorno);
    }

    public function test_sucesso_aceita_qualquer_campo_XmlGZipB64(): void
    {
        // Resposta de eventos usa `eventoNfseXmlGZipB64` (não `nfseXmlGZipB64`)
        $xmlInterno = '<?xml version="1.0"?><evento><cStat>135</cStat></evento>';
        $gz = gzencode($xmlInterno);
        self::assertNotFalse($gz);
        $body = json_encode(['eventoNfseXmlGZipB64' => base64_encode($gz)]);
        self::assertNotFalse($body);

        $r = $this->parse($body);
        self::assertNotNull($r->xmlRetorno);
        self::assertStringContainsString('<cStat>135</cStat>', $r->xmlRetorno);
    }

    public function test_body_xml_puro_retorna_como_esta(): void
    {
        $xml = '<?xml version="1.0"?><resposta><cStat>100</cStat><xMotivo>OK</xMotivo></resposta>';
        $r = $this->parse($xml);
        self::assertSame(100, $r->cStat);
        self::assertSame('OK', $r->xMotivo);
    }
}
