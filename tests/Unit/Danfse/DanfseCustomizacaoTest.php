<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Danfse\DanfseCustomizacao;
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Services\DanfseService;
use PHPUnit\Framework\TestCase;

final class DanfseCustomizacaoTest extends TestCase
{
    private function xmlAutorizado(): string
    {
        $path = __DIR__ . '/../../fixtures/nfse-autorizada.xml';
        $conteudo = file_get_contents($path);
        self::assertNotFalse($conteudo);
        return $conteudo;
    }

    public function test_default_sem_customizacao_funciona(): void
    {
        $custom = new DanfseCustomizacao();
        self::assertFalse($custom->temLogoPrestador());
        self::assertFalse($custom->temObservacoesAdicionais());
    }

    public function test_logo_path_inexistente_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/não encontrado/');
        new DanfseCustomizacao(logoPrestadorPath: '/nope/logo.png');
    }

    public function test_observacoes_acima_de_2000_chars_rejeitadas(): void
    {
        $this->expectException(ValidationException::class);
        new DanfseCustomizacao(observacoesAdicionais: str_repeat('a', 2001));
    }

    public function test_pdf_gerado_com_observacoes_inclui_o_texto(): void
    {
        $custom = new DanfseCustomizacao(
            observacoesAdicionais: 'OBSERVACAO_DE_TESTE_CUSTOMIZADA_SDK',
        );

        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado(), $custom);

        self::assertStringStartsWith('%PDF-', $pdf);

        // Salva pra extrair texto e validar via pdftotext
        $tmp = tempnam(sys_get_temp_dir(), 'danfse_obs_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);

        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível no ambiente');
        }
        self::assertStringContainsString('OBSERVACAO_DE_TESTE_CUSTOMIZADA_SDK', $texto);
    }

    public function test_pdf_sem_customizacao_continua_funcionando(): void
    {
        // Sanity check — null não quebra o pipeline existente
        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado());
        self::assertStringStartsWith('%PDF-', $pdf);
    }
}
