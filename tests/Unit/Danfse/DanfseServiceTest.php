<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Services\DanfseService;
use PHPUnit\Framework\TestCase;

final class DanfseServiceTest extends TestCase
{
    private function xmlAutorizado(): string
    {
        $path = __DIR__ . '/../../fixtures/nfse-autorizada.xml';
        $conteudo = file_get_contents($path);
        self::assertNotFalse($conteudo);
        return $conteudo;
    }

    public function test_gerarDoXml_retorna_pdf_valido(): void
    {
        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado());

        // Magic bytes do PDF
        self::assertStringStartsWith('%PDF-', $pdf);
        // EOF do PDF (com possível padding/whitespace no final)
        self::assertStringContainsString('%%EOF', $pdf);
        // Tamanho mínimo razoável — DANFSE simples deve passar de 5 KB
        self::assertGreaterThan(5_000, strlen($pdf));
    }

    public function test_gerarDeDados_aceita_dados_parseados(): void
    {
        $service = new DanfseService();
        $dados = $service->parser()->parse($this->xmlAutorizado());
        $pdf = $service->gerarDeDados($dados);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(5_000, strlen($pdf));
    }

    public function test_parser_acessivel_via_getter(): void
    {
        $service = new DanfseService();
        $dados = $service->parser()->parse($this->xmlAutorizado());
        self::assertSame('123', $dados->numero());
    }
}
