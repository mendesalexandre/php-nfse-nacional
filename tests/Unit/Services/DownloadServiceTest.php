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

    public function test_pdfDanfse_retry_em_502_e_depois_sucesso(): void
    {
        // Mock devolve 502 → 200 (PDF). Com tentativas=2, deve dar sucesso.
        $pdfBytes = "%PDF-1.4\nfake\n%%EOF";
        $service = $this->buildService([
            new Response(502, [], 'Bad Gateway'),
            new Response(200, ['Content-Type' => 'application/pdf'], $pdfBytes),
        ]);

        $resultado = $service->pdfDanfse(self::CHAVE, tentativas: 2);
        self::assertSame($pdfBytes, $resultado);
    }

    public function test_pdfDanfse_lanca_apos_esgotar_tentativas_502(): void
    {
        // 3x 502 — deve lançar SefinException
        $service = $this->buildService([
            new Response(502, [], 'gw1'),
            new Response(503, [], 'gw2'),
            new Response(504, [], 'gw3'),
        ]);

        $this->expectException(SefinException::class);
        $service->pdfDanfse(self::CHAVE, tentativas: 3);
    }

    public function test_pdfDanfse_lanca_imediato_em_4xx(): void
    {
        // 404 não retenta — lança na primeira
        $service = $this->buildService([new Response(404, [], 'not found')]);

        $this->expectException(SefinException::class);
        $service->pdfDanfse(self::CHAVE, tentativas: 3);
    }

    public function test_verificarDps_retorna_true_em_HTTP_200(): void
    {
        $service = $this->buildService([new Response(200, [], '')]);
        self::assertTrue($service->verificarDps('DPS' . str_repeat('0', 47)));
    }

    public function test_verificarDps_retorna_false_em_HTTP_404(): void
    {
        $service = $this->buildService([new Response(404, [], 'not found')]);
        self::assertFalse($service->verificarDps('DPS' . str_repeat('0', 47)));
    }

    public function test_verificarDps_lanca_em_outros_status(): void
    {
        $service = $this->buildService([new Response(500, [], 'oops')]);
        $this->expectException(SefinException::class);
        $service->verificarDps('DPS' . str_repeat('0', 47));
    }

    public function test_verificarDps_lanca_quando_id_vazio(): void
    {
        $service = $this->buildService(null);
        $this->expectException(ValidationException::class);
        $service->verificarDps('');
    }

    public function test_listarEventosNfse_parsea_array_direto(): void
    {
        $eventos = [
            ['tipoEvento' => '101101', 'nSeqEvento' => 1, 'dhRegEvento' => '2026-05-18T10:00:00-03:00'],
            ['tipoEvento' => '105102', 'nSeqEvento' => 1, 'dhRegEvento' => '2026-05-18T11:00:00-03:00'],
        ];
        $body = json_encode($eventos);
        self::assertNotFalse($body);
        $service = $this->buildService([new Response(200, ['Content-Type' => 'application/json'], $body)]);

        $resultado = $service->listarEventosNfse(self::CHAVE);
        self::assertCount(2, $resultado);
    }

    public function test_listarEventosNfse_aceita_objeto_envelope_Eventos(): void
    {
        $body = json_encode(['Eventos' => [
            ['tipoEvento' => '101101'],
        ]]);
        self::assertNotFalse($body);
        $service = $this->buildService([new Response(200, ['Content-Type' => 'application/json'], $body)]);

        $resultado = $service->listarEventosNfse(self::CHAVE);
        self::assertCount(1, $resultado);
    }

    public function test_listarEventosNfse_retorna_vazio_em_404(): void
    {
        $service = $this->buildService([new Response(404, [], '')]);
        self::assertSame([], $service->listarEventosNfse(self::CHAVE));
    }

    /**
     * @param Response|list<Response>|null $responses
     */
    private function buildService(Response|array|null $responses): DownloadService
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

        $queue = $responses === null
            ? []
            : (is_array($responses) ? $responses : [$responses]);
        $mock = new MockHandler($queue);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new SefinClient($config, $cert, $endpoints, $http);
        // Sleeper no-op pra retry tests não congelarem a suite
        $client->setSleeper(static fn (int $_) => null);

        return new DownloadService($client, $endpoints);
    }
}
