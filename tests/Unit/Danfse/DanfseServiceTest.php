<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Enums\DanfseVersao;
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
        // Fixture tem tpAmb=2 (homologação) → deve exibir "NFS-e SEM VALIDADE JURÍDICA"
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

    public function test_versao_default_eh_v2(): void
    {
        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado());

        $tmp = tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);

        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível');
        }

        self::assertStringContainsString('DANFSe v2.0', $texto);
        self::assertStringNotContainsString('DANFSe v1.0', $texto);
    }

    public function test_versao_v1_renderiza_layout_legado_adn(): void
    {
        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado(), versao: DanfseVersao::V1);

        // PDF válido
        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(5_000, strlen($pdf));

        $tmp = tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);

        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível');
        }

        // Header V1 (não V2)
        self::assertStringContainsString('DANFSe v1.0', $texto);
        self::assertStringNotContainsString('DANFSe v2.0', $texto);
        // Label V1 — "Inscrição Municipal" (V2 usa "Indicador Municipal", bug do v0.19.1+)
        self::assertStringContainsString('Inscrição Municipal', $texto);
        // V1 não tem bloco IBS/CBS (Reforma ainda em rampa, ADN não inclui)
        self::assertStringNotContainsString('TRIBUTAÇÃO IBS / CBS', $texto);
        // V1 tem bloco TOTAIS APROXIMADOS DOS TRIBUTOS dedicado
        self::assertStringContainsString('TOTAIS APROXIMADOS DOS TRIBUTOS', $texto);
        // EMITENTE + label-anchor "Prestador do Serviço"
        self::assertStringContainsString('EMITENTE DA NFS-e', $texto);
        self::assertStringContainsString('Prestador do Serviço', $texto);
    }

    public function test_versao_v1_via_gerarDeDados(): void
    {
        $service = new DanfseService();
        $dados = $service->parser()->parse($this->xmlAutorizado());
        $pdf = $service->gerarDeDados($dados, versao: DanfseVersao::V1);

        self::assertStringStartsWith('%PDF-', $pdf);
        self::assertGreaterThan(5_000, strlen($pdf));
    }

    public function test_versao_v1_homologacao_tem_tarja_sem_validade(): void
    {
        $service = new DanfseService();
        $pdf = $service->gerarDoXml($this->xmlAutorizado(), versao: DanfseVersao::V1);

        $tmp = tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);

        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível');
        }

        // Mesma regra do V2 — tarja só com tpAmb=2 (independente de ambGer)
        self::assertStringContainsString('NFS-e SEM VALIDADE JURÍDICA', $texto);
    }

    public function test_pdf_producao_via_sistema_nacional_nao_inclui_tarja(): void
    {
        // Regressão do bug corrigido em v0.18.1: ambGer=2 (Sistema Nacional)
        // NÃO é homologação. Combinado com tpAmb=1 (produção) deve gerar
        // DANFSe sem a tarja "NFS-e SEM VALIDADE JURÍDICA". É o cenário mais
        // comum do consumidor em produção.
        $xmlHomol = $this->xmlAutorizado();
        $xmlProd = str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', $xmlHomol);
        self::assertNotSame($xmlHomol, $xmlProd, 'fixture precisa conter <tpAmb>2</tpAmb>');

        $service = new DanfseService();
        $pdf = $service->gerarDoXml($xmlProd);

        $tmp = tempnam(sys_get_temp_dir(), 'danfse_');
        file_put_contents($tmp, $pdf);
        $texto = shell_exec('pdftotext -layout ' . escapeshellarg($tmp) . ' - 2>/dev/null') ?: '';
        unlink($tmp);

        if ($texto === '') {
            self::markTestSkipped('pdftotext não disponível no ambiente');
        }

        self::assertStringNotContainsString('NFS-e SEM VALIDADE JURÍDICA', $texto);
    }
}
