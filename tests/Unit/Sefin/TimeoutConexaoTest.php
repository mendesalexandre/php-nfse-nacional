<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Sefin;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

final class TimeoutConexaoTest extends TestCase
{
    /** @var list<string> */
    private array $temporarios = [];

    protected function tearDown(): void
    {
        foreach ($this->temporarios as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        parent::tearDown();
    }

    private function prestador(): Prestador
    {
        return new Prestador('12345678000195', '12345', 'EMPRESA TESTE',
            new Endereco('Rua Exemplo', '100', 'Centro', '01310100', '3550308', 'SP'));
    }

    /** @return array{SefinClient, Client} */
    private function transporte(Config $config): array
    {
        $cert = new Certificate('CHAVE_SINTETICA', 'CERTIFICADO_SINTETICO', new \DateTimeImmutable('+1 day'), '12345678000195', 'TESTE');
        $client = new SefinClient($config, $cert, new SefinEndpoints($config->ambiente));
        $http = (new ReflectionProperty(SefinClient::class, 'http'))->getValue($client);
        self::assertInstanceOf(Client::class, $http);
        foreach (['cert', 'ssl_key'] as $campo) {
            $path = $http->getConfig($campo);
            self::assertIsString($path);
            $this->temporarios[] = $path;
        }
        return [$client, $http];
    }

    public function test_padrao30s_no_cliente_mtls_sem_rede_e_sem_quebrar_argumentos_posicionais(): void
    {
        // Os oito argumentos posicionais anteriores permanecem na mesma ordem.
        $config = new Config($this->prestador(), Ambiente::Homologacao, 90, 3, 'teste', false, false, null);
        [, $http] = $this->transporte($config);
        self::assertSame(30, $config->timeoutConexaoSegundos);
        self::assertSame(30, $http->getConfig('connect_timeout'));
        self::assertTrue($http->getConfig('verify'));
        self::assertSame('CERTIFICADO_SINTETICO', file_get_contents($http->getConfig('cert')));
        self::assertSame('CHAVE_SINTETICA', file_get_contents($http->getConfig('ssl_key')));
    }

    public function test_conexao_configuravel_e_total_independente_chegam_ao_transporte(): void
    {
        $config = new Config($this->prestador(), timeoutSegundos: 90, timeoutConexaoSegundos: 45);
        [$client, $http] = $this->transporte($config);
        $stack = $http->getConfig('handler');
        self::assertInstanceOf(HandlerStack::class, $stack);
        $stack->setHandler(new MockHandler([new Response(200, [], '{"erro":[{"Codigo":"E999","Descricao":"Simulado"}]}')]));
        $historico = [];
        $stack->push(Middleware::history($historico));
        $client->enviarDps('<DPS/>'); // MockHandler: nenhum certificado/servidor real.
        self::assertCount(1, $historico);
        self::assertSame(45, $historico[0]['options']['connect_timeout']);
        self::assertSame(90, $historico[0]['options']['timeout']);
        self::assertTrue($historico[0]['options']['verify']);
    }

    /** @return list<array{int}> */
    public static function invalidos(): array
    {
        return [[-1], [0], [301]];
    }

    #[DataProvider('invalidos')]
    public function test_timeout_conexao_invalido_falha_antes_de_qualquer_requisicao(int $valor): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('timeoutConexaoSegundos fora de [1..300]');
        new Config($this->prestador(), timeoutConexaoSegundos: $valor);
    }
}
