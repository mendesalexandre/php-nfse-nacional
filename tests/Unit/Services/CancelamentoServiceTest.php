<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Config;
use PhpNfseNacional\Dps\EventoBuilder;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Exceptions\SefinException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Services\CancelamentoService;
use PHPUnit\Framework\TestCase;

/**
 * Cobertura da regra "quais cStat o SEFIN devolve em evento de cancelamento
 * são aceitos como sucesso". O serviço aceita {100, 135, 155} como evento
 * registrado e 840 como idempotente — qualquer outro vira SefinException.
 */
final class CancelamentoServiceTest extends TestCase
{
    private const CHAVE = '51079092200179028000138000000000005726057774456203';

    public function test_aceita_resposta_envelope_gzip_como_sucesso(): void
    {
        // Resposta de evento bem-sucedido vem como JSON com `eventoNfseXmlGZipB64`.
        // O parser atribui cStat=100 implícito quando há esse envelope (não extrai
        // o cStat do XML interno) — 100 está na lista de aceitos do serviço.
        $service = $this->buildService($this->respostaCancelamentoOk(135));

        $resp = $service->cancelar(self::CHAVE, MotivoCancelamento::ErroEmissao, str_repeat('a', 20));

        self::assertSame(100, $resp->cStat);
    }

    public function test_aceita_cStat_840_como_cancelamento_ja_existente(): void
    {
        $service = $this->buildService($this->respostaErroEvento('E0840', 'Cancelamento já vinculado'));

        $resp = $service->cancelar(self::CHAVE, MotivoCancelamento::ErroEmissao, str_repeat('a', 20));

        self::assertSame(840, $resp->cStat);
    }

    public function test_rejeita_cStat_diferente_lanca_SefinException(): void
    {
        $service = $this->buildService($this->respostaErroEvento('E1235', 'Falha no esquema XML'));

        $this->expectException(SefinException::class);
        $service->cancelar(self::CHAVE, MotivoCancelamento::ErroEmissao, str_repeat('a', 20));
    }

    private function buildService(Response $response): CancelamentoService
    {
        $config = $this->buildConfig();
        $endpoints = new SefinEndpoints(Ambiente::Homologacao);
        $cert = $this->dummyCertificate();

        $mock = new MockHandler([$response]);
        $handler = HandlerStack::create($mock);
        $http = new Client(['handler' => $handler]);

        $client = new SefinClient($config, $cert, $endpoints, $http);

        return new CancelamentoService(
            builder: new EventoBuilder($config),
            signer: $this->fakeSigner(),
            client: $client,
            endpoints: $endpoints,
        );
    }

    private function buildConfig(): Config
    {
        $endereco = new Endereco('Av Exemplo', '100', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador(
            cnpj: '00179028000138',
            inscricaoMunicipal: '11408',
            razaoSocial: 'CARTORIO TESTE',
            endereco: $endereco,
        );
        return new Config($prestador, Ambiente::Homologacao);
    }

    private function dummyCertificate(): Certificate
    {
        return new Certificate(
            privateKeyPem: '-----BEGIN PRIVATE KEY-----\nDUMMY\n-----END PRIVATE KEY-----',
            certificatePem: '-----BEGIN CERTIFICATE-----\nDUMMY\n-----END CERTIFICATE-----',
            validade: new \DateTimeImmutable('+1 year'),
            cnpj: '00179028000138',
            subjectCN: 'TESTE',
        );
    }

    /**
     * Signer "fake" — devolve o XML cru sem assinar. Suficiente porque a
     * resposta do mock HTTP é independente do conteúdo enviado.
     */
    private function fakeSigner(): Signer
    {
        return new class($this->dummyCertificate()) extends Signer {
            public function sign(string $xml, string $elementName, string $idAttribute = 'Id'): string
            {
                return $xml;
            }
        };
    }

    private function respostaCancelamentoOk(int $cStat): Response
    {
        $xmlInterno = '<?xml version="1.0"?><evento><cStat>' . $cStat . '</cStat></evento>';
        $body = json_encode([
            'tipoAmbiente' => 2,
            'eventoNfseXmlGZipB64' => base64_encode(gzencode($xmlInterno) ?: ''),
        ]);
        self::assertNotFalse($body);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    private function respostaErroEvento(string $codigo, string $descricao): Response
    {
        $body = json_encode([
            'tipoAmbiente' => 2,
            'erro' => [['codigo' => $codigo, 'descricao' => $descricao]],
        ]);
        self::assertNotFalse($body);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }
}
