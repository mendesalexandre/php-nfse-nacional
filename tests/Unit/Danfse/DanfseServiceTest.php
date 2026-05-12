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

    public function test_pdf_contem_blocos_obrigatorios_NT008(): void
    {
        // Extrai texto do PDF e valida que os 13 blocos do Anexo I estão lá.
        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado());

        // Salva em temp e roda pdftotext (disponível em CI Linux/macOS)
        $tmp = tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);

        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível no ambiente');
        }

        // Blocos sempre presentes (com ou sem dados). Os blocos opcionais
        // (Destinatário/Intermediário) podem ser suprimidos com linha única —
        // testados em test_pdf_suprime_blocos_opcionais.
        $blocosObrigatorios = [
            'DANFSe v2.0',
            'Documento Auxiliar da NFS-e',
            'CHAVE DE ACESSO DA NFS-E',
            'NÚMERO DA NFS-E',
            'COMPETÊNCIA DA NFS-E',
            'PRESTADOR / FORNECEDOR',
            'TOMADOR / ADQUIRENTE',
            'SERVIÇO PRESTADO',
            'TRIBUTAÇÃO MUNICIPAL (ISSQN)',
            'TRIBUTAÇÃO FEDERAL (EXCETO CBS)',
            'TRIBUTAÇÃO IBS / CBS',
            'VALOR TOTAL DA NFS-E',
            'INFORMAÇÕES COMPLEMENTARES',
            'Totais Aproximados dos Tributos',
        ];

        foreach ($blocosObrigatorios as $bloco) {
            self::assertStringContainsString(
                $bloco,
                $texto,
                "Bloco obrigatório NT 008 ausente: '{$bloco}'",
            );
        }
    }

    public function test_pdf_suprime_blocos_opcionais_com_texto_unico(): void
    {
        // Fixture tem CPF do tomador = destinatário, sem intermediário.
        // NT 008 item 2.3.1/2.3.2 — blocos suprimidos viram linha única.
        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado());
        $tmp = tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);

        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível');
        }

        self::assertStringContainsString('O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE', $texto);
        self::assertStringContainsString('INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e', $texto);
    }

    public function test_pdf_homologacao_inclui_tarja_sem_validade(): void
    {
        // Fixture tem ambGer=2 (homologação) → deve exibir "NFS-e SEM VALIDADE JURÍDICA"
        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado());

        $tmp = tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);

        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível no ambiente');
        }

        self::assertStringContainsString('NFS-e SEM VALIDADE JURÍDICA', $texto);
    }
}
