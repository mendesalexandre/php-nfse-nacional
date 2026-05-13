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
use PhpNfseNacional\DTO\MotivoSubstituicao;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Exceptions\SefinException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Services\SubstituicaoService;
use PHPUnit\Framework\TestCase;

final class SubstituicaoServiceTest extends TestCase
{
    private const CHAVE_ORIG  = '51079092200179028000138000000000005726057774456203';
    private const CHAVE_SUBST = '51079092200179028000138000000000005826057774456204';

    public function test_aceita_resposta_envelope_gzip_como_sucesso(): void
    {
        $service = $this->buildService($this->respostaOk());

        $resp = $service->substituir(
            self::CHAVE_ORIG,
            self::CHAVE_SUBST,
            MotivoSubstituicao::DesenquadramentoSimples,
            'Reemissão por divergência de valor',
        );

        // parsearResposta atribui cStat=100 implícito quando há eventoNfseXmlGZipB64
        self::assertSame(100, $resp->cStat);
    }

    public function test_aceita_E0840_idempotente(): void
    {
        $service = $this->buildService($this->respostaErro('E0840', 'Substituição já vinculada'));

        $resp = $service->substituir(
            self::CHAVE_ORIG,
            self::CHAVE_SUBST,
            MotivoSubstituicao::DesenquadramentoSimples,
            'Reemissão por divergência de valor',
        );

        self::assertSame(840, $resp->cStat);
    }

    public function test_rejeita_outros_cStat_lanca_SefinException(): void
    {
        $service = $this->buildService($this->respostaErro('E1235', 'Falha no esquema XML'));

        $this->expectException(SefinException::class);
        $service->substituir(
            self::CHAVE_ORIG,
            self::CHAVE_SUBST,
            MotivoSubstituicao::DesenquadramentoSimples,
            'Reemissão por divergência de valor',
        );
    }

    private function buildService(Response $response): SubstituicaoService
    {
        $endereco = new Endereco('Av X', '100', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador('00179028000138', '11408', 'CARTORIO TESTE', $endereco);
        $config = new Config($prestador, Ambiente::Homologacao);
        $endpoints = new SefinEndpoints(Ambiente::Homologacao);

        $cert = new Certificate(
            privateKeyPem: 'DUMMY',
            certificatePem: 'DUMMY',
            validade: new \DateTimeImmutable('+1 year'),
            cnpj: '00179028000138',
            subjectCN: 'TESTE',
        );

        $http = new Client(['handler' => HandlerStack::create(new MockHandler([$response]))]);
        $client = new SefinClient($config, $cert, $endpoints, $http);

        $signer = new class($cert) extends Signer {
            public function sign(string $xml, string $elementName, string $idAttribute = 'Id'): string
            {
                return $xml;
            }
        };

        return new SubstituicaoService(
            builder: new EventoBuilder($config),
            signer: $signer,
            client: $client,
            endpoints: $endpoints,
        );
    }

    private function respostaOk(): Response
    {
        $xmlInterno = '<?xml version="1.0"?><evento><cStat>135</cStat></evento>';
        $body = json_encode([
            'tipoAmbiente' => 2,
            'eventoNfseXmlGZipB64' => base64_encode(gzencode($xmlInterno) ?: ''),
        ]);
        self::assertNotFalse($body);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }

    private function respostaErro(string $codigo, string $descricao): Response
    {
        $body = json_encode([
            'tipoAmbiente' => 2,
            'erro' => [['codigo' => $codigo, 'descricao' => $descricao]],
        ]);
        self::assertNotFalse($body);
        return new Response(200, ['Content-Type' => 'application/json'], $body);
    }
}
