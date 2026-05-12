<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Exceptions\SefinException;
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Services\DownloadService;
use PHPUnit\Framework\TestCase;

final class DownloadServiceTest extends TestCase
{
    private const CHAVE = '51079092200179028000138000000000005726057774456203';
    private const CHAVE_INVALIDA = '123';

    public function test_pdfDanfse_retorna_bytes_quando_resposta_eh_pdf(): void
    {
        $pdfBytes = "%PDF-1.4\n%fakepdf\n%%EOF\n";
        $service = $this->buildService(new Response(200, ['Content-Type' => 'application/pdf'], $pdfBytes));

        $resultado = $service->pdfDanfse(self::CHAVE);

        self::assertSame($pdfBytes, $resultado);
    }

    public function test_pdfDanfse_lanca_quando_resposta_nao_eh_pdf(): void
    {
        $service = $this->buildService(new Response(200, [], '<html>not a pdf</html>'));

        $this->expectException(SefinException::class);
        $service->pdfDanfse(self::CHAVE);
    }

    public function test_pdfDanfse_lanca_quando_status_nao_200(): void
    {
        $service = $this->buildService(new Response(404, [], 'not found'));

        $this->expectException(SefinException::class);
        $service->pdfDanfse(self::CHAVE);
    }

    public function test_chave_invalida_lanca_validation_antes_da_chamada_http(): void
    {
        // MockHandler vazio: se o serviço fizer HTTP, Guzzle lança RuntimeException.
        // ValidationException tem que vir antes.
        $service = $this->buildService(null);

        $this->expectException(ValidationException::class);
        $service->pdfDanfse(self::CHAVE_INVALIDA);
    }

    public function test_xmlNfse_extrai_xml_do_envelope_gzip(): void
    {
        $xmlInterno = '<?xml version="1.0"?><NFSe><infNFSe><nNFSe>123</nNFSe></infNFSe></NFSe>';
        $body = json_encode([
            'tipoAmbiente' => 2,
            'chaveAcesso' => self::CHAVE,
            'nfseXmlGZipB64' => base64_encode(gzencode($xmlInterno) ?: ''),
        ]);
        self::assertNotFalse($body);
        $service = $this->buildService(new Response(200, ['Content-Type' => 'application/json'], $body));

        $resultado = $service->xmlNfse(self::CHAVE);

        self::assertStringContainsString('<nNFSe>123</nNFSe>', $resultado);
    }

    private function buildService(?Response $response): DownloadService
    {
        $endereco = new Endereco('Av Exemplo', '100', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador('00179028000138', '11408', 'CARTORIO TESTE', $endereco);
        $config = new Config($prestador, Ambiente::Homologacao);

        $cert = new Certificate(
            privateKeyPem: 'DUMMY',
            certificatePem: 'DUMMY',
            validade: new \DateTimeImmutable('+1 year'),
            cnpj: '00179028000138',
            subjectCN: 'TESTE',
        );

        $endpoints = new SefinEndpoints(Ambiente::Homologacao);

        $mock = new MockHandler($response !== null ? [$response] : []);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new SefinClient($config, $cert, $endpoints, $http);

        return new DownloadService($client, $endpoints);
    }
}
