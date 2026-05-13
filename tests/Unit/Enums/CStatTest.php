<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Enums;

use PhpNfseNacional\Enums\CStat;
use PHPUnit\Framework\TestCase;

final class CStatTest extends TestCase
{
    public function test_codigos_principais_existem(): void
    {
        self::assertSame(100, CStat::Emitida->value);
        self::assertSame(101, CStat::Cancelada->value);
        self::assertSame(102, CStat::CanceladaPorSubstituicao->value);
        self::assertSame(135, CStat::EventoRegistrado->value);
        self::assertSame(155, CStat::CancelamentoHomologado->value);
        self::assertSame(840, CStat::EventoVinculado->value);
        self::assertSame(15, CStat::ErroCompetPosteriorAoEmi->value);
        self::assertSame(38, CStat::ErroConvenioInativo->value);
        self::assertSame(440, CStat::ErroDeducaoNaoPermitida->value);
        self::assertSame(1235, CStat::ErroSchemaXml->value);
        self::assertSame(9996, CStat::ErroEmitenteNaoHabilitado->value);
    }

    public function test_descricao_existe_pra_todos_os_cases(): void
    {
        foreach (CStat::cases() as $cStat) {
            $msg = $cStat->descricao();
            self::assertNotSame('', trim($msg), "{$cStat->name} sem descrição");
        }
    }

    public function test_ehSucesso_classifica_corretamente(): void
    {
        $sucessos = [
            CStat::Emitida,
            CStat::Cancelada,
            CStat::CanceladaPorSubstituicao,
            CStat::EventoRegistrado,
            CStat::CancelamentoHomologado,
            CStat::EventoVinculado,
        ];
        foreach ($sucessos as $s) {
            self::assertTrue($s->ehSucesso(), "{$s->name} deveria ser sucesso");
        }

        // Erros não devem ser sucesso
        self::assertFalse(CStat::ErroSchemaXml->ehSucesso());
        self::assertFalse(CStat::ErroConvenioInativo->ehSucesso());
        self::assertFalse(CStat::AdnAssinaturaComErro->ehSucesso());
    }

    public function test_ehErroSefin_classifica_erros_nao_adn(): void
    {
        self::assertTrue(CStat::ErroCompetPosteriorAoEmi->ehErroSefin());
        self::assertTrue(CStat::ErroConvenioInativo->ehErroSefin());
        self::assertTrue(CStat::ErroSchemaXml->ehErroSefin());          // 1235 — fora da faixa ADN
        self::assertTrue(CStat::ErroEmitenteNaoHabilitado->ehErroSefin()); // 9996

        // Sucesso não é erro
        self::assertFalse(CStat::Emitida->ehErroSefin());
        // ADN nunca é SEFIN
        self::assertFalse(CStat::AdnAssinaturaComErro->ehErroSefin());
    }

    public function test_ehErroAdn_classifica_codigos_1800_plus(): void
    {
        self::assertTrue(CStat::AdnPrazoLeiauteExpirado->ehErroAdn());
        self::assertTrue(CStat::AdnAssinaturaComErro->ehErroAdn());
        self::assertTrue(CStat::AdnCancelImpedidoPorEvento->ehErroAdn());

        // SEFIN core não é ADN
        self::assertFalse(CStat::ErroSchemaXml->ehErroAdn());
        self::assertFalse(CStat::Emitida->ehErroAdn());
    }

    public function test_aceitosEvento_inclui_idempotente(): void
    {
        $aceitos = CStat::aceitosEvento();

        self::assertContains(100, $aceitos);
        self::assertContains(135, $aceitos);
        self::assertContains(155, $aceitos);
        self::assertContains(840, $aceitos);

        self::assertNotContains(101, $aceitos);  // estado terminal, não evento aceito
        self::assertNotContains(0, $aceitos);
    }

    public function test_estadosCancelada(): void
    {
        $estados = CStat::estadosCancelada();

        self::assertContains(101, $estados);  // Cancelada
        self::assertContains(102, $estados);  // CanceladaPorSubstituicao
        self::assertContains(135, $estados);  // EventoRegistrado
        self::assertContains(155, $estados);  // CancelamentoHomologado
    }

    public function test_tryFrom_retorna_null_pra_codigo_desconhecido(): void
    {
        // Códigos desconhecidos do SDK retornam null — não enumerados
        self::assertNull(CStat::tryFrom(999));
        self::assertNull(CStat::tryFrom(0));
        self::assertNull(CStat::tryFrom(7777));
    }

    public function test_tryFrom_retorna_enum_pra_codigo_conhecido(): void
    {
        self::assertSame(CStat::Emitida, CStat::tryFrom(100));
        self::assertSame(CStat::EventoVinculado, CStat::tryFrom(840));
    }
}
