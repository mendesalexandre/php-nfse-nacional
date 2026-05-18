<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Enums;

use PhpNfseNacional\Enums\ListaNbs;
use PhpNfseNacional\Enums\ListaServicosNacional;
use PhpNfseNacional\Enums\RegimeApuracaoSimplesNacional;
use PhpNfseNacional\Enums\TipoBeneficioMunicipal;
use PhpNfseNacional\Enums\TipoImunidadeIssqn;
use PhpNfseNacional\Enums\TipoRetencaoIssqn;
use PHPUnit\Framework\TestCase;

final class EnumsCoberturaTest extends TestCase
{
    public function test_lista_servicos_codigo_canonico_resolve(): void
    {
        $c = ListaServicosNacional::from('010101');
        self::assertSame(ListaServicosNacional::S010101, $c);
        self::assertSame('010101', $c->value);
        self::assertStringStartsWith('Análise e desenvolvimento de sistemas', $c->descricao());
    }

    public function test_lista_servicos_parsers_quebram_codigo_em_partes(): void
    {
        $c = ListaServicosNacional::S171001; // exemplo: serviços de contabilidade
        self::assertSame('17', $c->item());
        self::assertSame('10', $c->subitem());
        self::assertSame('01', $c->desdobro());
        self::assertSame('171001', $c->value);
    }

    public function test_lista_servicos_descricao_e_mais_rica_que_comentario_truncado(): void
    {
        // Casos cuja descrição oficial passa de 100 chars confirmam que
        // `descricao()` tem o texto íntegro (o comentário inline trunca em ~80).
        $c = ListaServicosNacional::S010301;
        self::assertGreaterThan(80, mb_strlen($c->descricao()));
    }

    public function test_lista_nbs_resolve_codigo_e_parsers(): void
    {
        $c = ListaNbs::from('101011100');
        self::assertNotNull($c);
        self::assertSame('101011100', $c->value);
        self::assertSame('1', $c->secao());
        self::assertSame('01', $c->divisao());
        self::assertSame('01', $c->grupo());
        self::assertSame('11', $c->classe());
        self::assertSame('00', $c->subclasse());
    }

    public function test_tipo_beneficio_municipal_cases(): void
    {
        self::assertCount(4, TipoBeneficioMunicipal::cases());
        self::assertSame(1, TipoBeneficioMunicipal::Isencao->value);
        self::assertSame(4, TipoBeneficioMunicipal::AliquotaDiferenciada->value);
    }

    public function test_tipo_imunidade_issqn_cases(): void
    {
        self::assertCount(6, TipoImunidadeIssqn::cases());
        self::assertSame(0, TipoImunidadeIssqn::NaoInformado->value);
        self::assertSame(2, TipoImunidadeIssqn::TemplosQualquerCulto->value);
        self::assertSame(4, TipoImunidadeIssqn::LivrosJornaisPeriodicosPapel->value);
    }

    public function test_regime_apuracao_simples_nacional_cases(): void
    {
        self::assertCount(3, RegimeApuracaoSimplesNacional::cases());
        self::assertSame(1, RegimeApuracaoSimplesNacional::FederaisEMunicipalPorSN->value);
        self::assertSame(3, RegimeApuracaoSimplesNacional::FederaisEMunicipalPorNfse->value);
    }

    public function test_tipo_retencao_issqn_cases(): void
    {
        self::assertCount(3, TipoRetencaoIssqn::cases());
        self::assertSame(1, TipoRetencaoIssqn::NaoRetido->value);
        self::assertSame(2, TipoRetencaoIssqn::RetidoPeloTomador->value);
        self::assertSame(3, TipoRetencaoIssqn::RetidoPeloIntermediario->value);
    }

    public function test_lista_servicos_tem_cobertura_minima(): void
    {
        // 335 casos transcritos da LC 116/2003 + leiaute SEFIN.
        self::assertGreaterThanOrEqual(300, count(ListaServicosNacional::cases()));
    }

    public function test_lista_nbs_tem_cobertura_minima(): void
    {
        // 917 casos da NBS.
        self::assertGreaterThanOrEqual(900, count(ListaNbs::cases()));
    }
}
