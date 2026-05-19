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
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Services\DfeService;
use PHPUnit\Framework\TestCase;

final class DfeServiceTest extends TestCase
{
    public function test_sincronizar_retorna_vazio_quando_sem_lote(): void
    {
        $body = json_encode([
            'StatusProcessamento' => 'NenhumDocumentoLocalizado',
            'LoteDFe' => [],
        ]);
        self::assertNotFalse($body);
        $service = $this->buildService([new Response(200, [], $body)]);

        $resp = $service->sincronizar(ultimoNsu: 0);
        self::assertTrue($resp->vazio());
        self::assertSame(0, $resp->ultimoNsu);
        self::assertSame('NenhumDocumentoLocalizado', $resp->statusProcessamento);
        self::assertFalse($resp->temMais);
    }

    public function test_sincronizar_pagina_ate_esgotar(): void
    {
        // Página 1: 2 itens (NSU 1 e 2). Página 2: 1 item (NSU 3) + status fim.
        $pag1 = json_encode([
            'StatusProcessamento' => 'ProcessamentoNormal',
            'LoteDFe' => [
                ['NSU' => 1, 'TipoDocumento' => 'NFS-e', 'ChaveAcesso' => '3550308...'],
                ['NSU' => 2, 'TipoEvento' => '101101', 'SequencialEvento' => 1],
            ],
        ]);
        $pag2 = json_encode([
            'StatusProcessamento' => 'NenhumDocumentoLocalizado',
            'LoteDFe' => [
                ['NSU' => 3, 'TipoDocumento' => 'Evento'],
            ],
        ]);
        self::assertNotFalse($pag1);
        self::assertNotFalse($pag2);

        $service = $this->buildService([
            new Response(200, [], $pag1),
            new Response(200, [], $pag2),
        ]);

        $resp = $service->sincronizar(ultimoNsu: 0, maxPaginas: 5);
        self::assertSame(3, $resp->quantidade());
        self::assertSame(3, $resp->ultimoNsu);
        self::assertFalse($resp->temMais);

        // Verifica parsing dos campos
        $itens = $resp->itens;
        self::assertSame(1, $itens[0]->nsu);
        self::assertSame('NFS-e', $itens[0]->tipoDocumento);
        self::assertSame('101101', $itens[1]->tipoEvento);
        self::assertSame(1, $itens[1]->sequencialEvento);
    }

    public function test_sincronizar_para_em_lote_vazio_mesmo_com_status_normal(): void
    {
        $pag1 = json_encode([
            'StatusProcessamento' => 'ProcessamentoNormal',
            'LoteDFe' => [],
        ]);
        self::assertNotFalse($pag1);

        $service = $this->buildService([new Response(200, [], $pag1)]);

        $resp = $service->sincronizar(ultimoNsu: 100);
        self::assertSame(100, $resp->ultimoNsu);
        self::assertTrue($resp->vazio());
    }

    public function test_sincronizar_aceita_chaves_lowercase(): void
    {
        $body = json_encode([
            'statusProcessamento' => 'NenhumDocumentoLocalizado',
            'loteDFe' => [
                ['nsu' => 5, 'tipoDocumento' => 'NFS-e'],
            ],
        ]);
        self::assertNotFalse($body);
        $service = $this->buildService([new Response(200, [], $body)]);

        $resp = $service->sincronizar();
        self::assertSame(1, $resp->quantidade());
        self::assertSame(5, $resp->ultimoNsu);
    }

    public function test_sincronizar_rejeita_nsu_negativo(): void
    {
        $service = $this->buildService(null);
        $this->expectException(ValidationException::class);
        $service->sincronizar(ultimoNsu: -1);
    }

    public function test_sincronizar_rejeita_maxPaginas_zero(): void
    {
        $service = $this->buildService(null);
        $this->expectException(ValidationException::class);
        $service->sincronizar(maxPaginas: 0);
    }

    /**
     * @param list<Response>|null $responses
     */
    private function buildService(?array $responses): DfeService
    {
        $endereco = new Endereco('Av Exemplo', '100', 'Centro', '01310100', '3550308', 'SP');
        $prestador = new Prestador('12345678000195', '12345', 'EMPRESA TESTE', $endereco);
        $config = new Config($prestador, Ambiente::Homologacao);

        $cert = new Certificate(
            privateKeyPem: 'DUMMY',
            certificatePem: 'DUMMY',
            validade: new \DateTimeImmutable('+1 year'),
            cnpj: '12345678000195',
            subjectCN: 'TESTE',
        );

        $endpoints = new SefinEndpoints(Ambiente::Homologacao);
        $mock = new MockHandler($responses ?? []);
        $http = new Client(['handler' => HandlerStack::create($mock)]);
        $client = new SefinClient($config, $cert, $endpoints, $http);

        return new DfeService($client, $cert);
    }
}
