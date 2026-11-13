<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Services;

use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Request;
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
use PhpNfseNacional\Services\ParametrosMunicipaisService;
use PHPUnit\Framework\TestCase;

final class ParametrosMunicipaisServiceTest extends TestCase
{
    /** @var list<Request> */
    private array $requisicoes = [];

    public function test_convenio_mapeia_os_campos_e_booleanos_numericos(): void
    {
        $body = json_encode([
            'parametrosConvenio' => [
                'aderenteAmbienteNacional' => 1,
                'aderenteEmissorNacional' => true,
                'situacaoEmissaoPadraoContribuintesRFB' => 2,
                'aderenteMAN' => 0,
                'permiteAproveitametoDeCreditos' => '1',
            ],
            'mensagem' => 'ok',
        ]);
        self::assertNotFalse($body);
        $service = $this->buildService([new Response(200, [], $body)]);

        $convenio = $service->convenio('35.50308');

        self::assertTrue($convenio->aderenteAmbienteNacional);
        self::assertTrue($convenio->aderenteEmissorNacional);
        self::assertSame(2, $convenio->situacaoEmissaoPadraoContribuintesRfb);
        self::assertFalse($convenio->aderenteMan);
        self::assertTrue($convenio->permiteAproveitamentoCreditos);
        self::assertSame('ok', $convenio->mensagem);
        self::assertSame(
            'https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/convenio',
            (string) $this->requisicoes[0]->getUri(),
        );
    }

    public function test_aliquota_monta_url_com_servico_formatado_e_competencia_mm_dd_aaaa(): void
    {
        $service = $this->buildService([new Response(200, [], $this->corpoAliquota())]);

        $service->aliquota('3550308', '210101', new \DateTimeImmutable('2026-11-13'));

        self::assertSame(
            'https://adn.producaorestrita.nfse.gov.br/parametrizacao/3550308/21.01.01.000/11-13-2026/aliquota',
            (string) $this->requisicoes[0]->getUri(),
        );
    }

    public function test_aliquota_escolhe_a_vigencia_que_cobre_a_competencia(): void
    {
        $service = $this->buildService([new Response(200, [], $this->corpoAliquota())]);

        $aliquota = $service->aliquota('3550308', '21.01.01', new \DateTimeImmutable('2026-11-13'));

        self::assertSame('21.01.01.000', $aliquota->codigoServico);
        self::assertSame(4.0, $aliquota->aliquotaPercentual);
        self::assertTrue($aliquota->incidencia);
        self::assertSame('2025-01-01T00:00:00', $aliquota->vigenciaInicio);
        self::assertNull($aliquota->vigenciaFim);
    }

    public function test_aliquota_de_competencia_antiga_pega_a_vigencia_anterior(): void
    {
        $service = $this->buildService([new Response(200, [], $this->corpoAliquota())]);

        $aliquota = $service->aliquota('3550308', '210101000', new \DateTimeImmutable('2024-06-10'));

        self::assertSame(3.0, $aliquota->aliquotaPercentual);
    }

    public function test_aliquota_sem_publicacao_para_o_servico_lanca_sefin_exception(): void
    {
        $body = json_encode(['aliquotas' => [], 'mensagem' => 'nada']);
        self::assertNotFalse($body);
        $service = $this->buildService([new Response(200, [], $body)]);

        $this->expectException(SefinException::class);
        $this->expectExceptionMessage('sem alíquota publicada');
        $service->aliquota('3550308', '210101');
    }

    public function test_http_404_vira_sefin_exception_com_a_mensagem_do_adn(): void
    {
        $body = json_encode(['mensagem' => 'Município não encontrado']);
        self::assertNotFalse($body);
        $service = $this->buildService([new Response(404, [], $body)]);

        $this->expectException(SefinException::class);
        $this->expectExceptionMessage('HTTP 404 — Município não encontrado');
        $service->convenio('3550308');
    }

    public function test_municipio_e_servico_invalidos_sao_recusados_antes_da_chamada(): void
    {
        $service = $this->buildService([]);

        try {
            $service->convenio('123');
            self::fail('município inválido deveria lançar ValidationException');
        } catch (ValidationException) {
        }

        $this->expectException(ValidationException::class);
        $service->aliquota('3550308', '2101');
    }

    public function test_incidencia_sim_nao_do_adn_vira_booleano(): void
    {
        $com = json_encode(['aliquotas' => ['21.01.01.000' => [['Incidencia' => 'SIM', 'Aliq' => 4, 'DtIni' => '2024-10-30T00:00:00', 'DtFim' => null]]]]);
        $sem = json_encode(['aliquotas' => ['21.01.01.000' => [['Incidencia' => 'NAO', 'Aliq' => 4, 'DtIni' => '2024-10-30T00:00:00', 'DtFim' => null]]]]);
        self::assertNotFalse($com);
        self::assertNotFalse($sem);

        $a = $this->buildService([new Response(200, [], $com)])->aliquota('3550308', '210101');
        $b = $this->buildService([new Response(200, [], $sem)])->aliquota('3550308', '210101');

        self::assertTrue($a->incidencia);
        self::assertFalse($b->incidencia);
    }

    private function corpoAliquota(): string
    {
        $body = json_encode(['aliquotas' => ['21.01.01.000' => [
            ['Incidencia' => 1, 'Aliq' => 3.0, 'DtIni' => '2020-01-01T00:00:00', 'DtFim' => '2024-12-31T00:00:00'],
            ['Incidencia' => 1, 'Aliq' => 4.0, 'DtIni' => '2025-01-01T00:00:00', 'DtFim' => null],
        ]]]);
        self::assertNotFalse($body);

        return $body;
    }

    /** @param list<Response> $responses */
    private function buildService(array $responses): ParametrosMunicipaisService
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
        $this->requisicoes = [];
        $stack = HandlerStack::create(new MockHandler($responses));
        $stack->push(function (callable $handler): callable {
            return function (Request $request, array $options) use ($handler) {
                $this->requisicoes[] = $request;

                return $handler($request, $options);
            };
        });
        $client = new SefinClient($config, $cert, $endpoints, new Client(['handler' => $stack]));

        return new ParametrosMunicipaisService($client, $endpoints);
    }
}
