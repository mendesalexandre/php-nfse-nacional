<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Sefin;

use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PHPUnit\Framework\TestCase;

final class SefinEndpointsTest extends TestCase
{
    public function test_homologacao_aponta_para_producao_restrita(): void
    {
        $ep = new SefinEndpoints(Ambiente::Homologacao);
        self::assertStringContainsString('producaorestrita', $ep->baseUrl());
        self::assertStringContainsString('producaorestrita', $ep->adnBaseUrl());
    }

    public function test_producao_aponta_para_url_oficial(): void
    {
        $ep = new SefinEndpoints(Ambiente::Producao);
        self::assertStringContainsString('sefin.nfse.gov.br', $ep->baseUrl());
        self::assertStringContainsString('adn.nfse.gov.br', $ep->adnBaseUrl());
    }

    public function test_enviarDps_path_correto(): void
    {
        $ep = new SefinEndpoints(Ambiente::Homologacao);
        self::assertStringEndsWith('/nfse', $ep->enviarDps());
    }

    public function test_downloadDanfse_usa_adn(): void
    {
        $ep = new SefinEndpoints(Ambiente::Homologacao);
        $chave = str_repeat('1', 50);
        $url = $ep->downloadDanfse($chave);
        self::assertStringContainsString('adn.producaorestrita', $url);
        self::assertStringEndsWith('/danfse/' . $chave, $url);
    }

    public function test_sincronizarDfe_inclui_cnpj_e_lote(): void
    {
        $ep = new SefinEndpoints(Ambiente::Homologacao);
        $url = $ep->sincronizarDfe(42, '12345678000195', lote: true);
        self::assertStringContainsString('/contribuintes/DFe/42', $url);
        self::assertStringContainsString('cnpjConsulta=12345678000195', $url);
        self::assertStringContainsString('lote=true', $url);
    }

    public function test_sincronizarDfe_lote_false(): void
    {
        $ep = new SefinEndpoints(Ambiente::Homologacao);
        $url = $ep->sincronizarDfe(0, '12345678000195', lote: false);
        self::assertStringContainsString('lote=false', $url);
    }

    public function test_listarEventosNfse_path_correto(): void
    {
        $ep = new SefinEndpoints(Ambiente::Producao);
        $chave = str_repeat('5', 50);
        $url = $ep->listarEventosNfse($chave);
        self::assertStringContainsString('adn.nfse.gov.br', $url);
        self::assertStringEndsWith('/contribuintes/NFSe/' . $chave . '/Eventos', $url);
    }
}
