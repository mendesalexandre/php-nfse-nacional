<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Support;

use DateTimeImmutable;
use PhpNfseNacional\Support\IndicadorOperacao;
use PHPUnit\Framework\TestCase;

final class IndicadorOperacaoTest extends TestCase
{
    public function test_nbs_com_um_unico_indicador_em_2026_eh_sugerido(): void
    {
        // Default do SDK (cTribNac 210101 / NBS 113040000) até 2026-11-30.
        self::assertSame(['100301'], IndicadorOperacao::indicadores('113040000', '2026-11-20', '210101'));
        self::assertSame('100301', IndicadorOperacao::sugerido('113040000', '2026-11-20', '210101'));
    }

    public function test_tabela_muda_em_2026_12_01_e_fica_ambigua(): void
    {
        // A partir de 01/12/2026 serviço notarial admite 030101/030102
        // (fruído presencialmente) além de 100301 (não presencial).
        $ind = IndicadorOperacao::indicadores('113040000', new DateTimeImmutable('2026-12-01'), '210101');
        self::assertSame(['030101', '030102', '100301'], $ind);
        self::assertNull(IndicadorOperacao::sugerido('113040000', '2026-12-01', '210101'));
    }

    public function test_valida_combinacao_vigente_e_rejeita_invalida(): void
    {
        self::assertTrue(IndicadorOperacao::valida('113040000', '100301', '000001', '210101', '2026-11-20'));
        self::assertFalse(IndicadorOperacao::valida('113040000', '100301', '000001', '210101', '2025-01-01'));
        self::assertFalse(IndicadorOperacao::valida('113040000', '010101', '000001', '210101', '2026-11-20'));
        self::assertFalse(IndicadorOperacao::valida('999999999', '100301', '000001', null, '2026-11-20'));
    }

    public function test_cTribNac_restringe_por_prefixo_e_formata_nbs_com_pontos(): void
    {
        // Serviço de outro item da LC 116 não casa com o NBS do cartório.
        self::assertSame([], IndicadorOperacao::combinacoes('113040000', '2026-11-20', '010101'));
        // NBS com máscara é normalizado.
        self::assertSame(
            IndicadorOperacao::combinacoes('113040000', '2026-11-20'),
            IndicadorOperacao::combinacoes('1.1304.00.00', '2026-11-20'),
        );
    }

    public function test_descricao_e_procedencia(): void
    {
        $d = IndicadorOperacao::descricao('100301');
        self::assertNotNull($d);
        self::assertStringContainsString('Demais serviços', $d['descricao']);
        self::assertNull(IndicadorOperacao::descricao('000000'));
        self::assertNotSame('', IndicadorOperacao::baseDados()['versaoBase']);
    }

    public function test_dados_embarcados_cobrem_todos_os_cIndOp_referenciados(): void
    {
        $j = json_decode((string) file_get_contents(__DIR__ . '/../../../resources/data/indop-nbs.json'), true);
        foreach ($j['nbs'] as $linhas) {
            foreach ($linhas as $l) {
                self::assertArrayHasKey($l[1], $j['indop'], "cIndOp {$l[1]} sem descrição");
                self::assertMatchesRegularExpression('/^\d{6}$/', $l[2]);
            }
        }
    }
}
