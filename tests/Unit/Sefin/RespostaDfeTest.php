<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Sefin;

use PhpNfseNacional\Sefin\ItemDfe;
use PhpNfseNacional\Sefin\RespostaDfe;
use PHPUnit\Framework\TestCase;

final class RespostaDfeTest extends TestCase
{
    private const CHAVE_A = '35503082212345678000195000000000000126036346526345';
    private const CHAVE_B = '35503082212345678000195000000000005526051882737530';
    private const CHAVE_C = '35503082212345678000195000000000007226050575727908';
    private const CHAVE_D = '35503082212345678000195000000000007326050394544295';

    /**
     * Fixture típica baseada no smoke real (emissor de exemplo, mai/2026):
     *
     *   A → NFSE + CANCELAMENTO
     *   B → NFSE + CANCELAMENTO
     *   C → NFSE + CONFIRMACAO_PRESTADOR
     *   D → NFSE + REJEICAO_PRESTADOR
     *   E (chave nova) → NFSE só, sem eventos
     */
    private function fixtureLote(): RespostaDfe
    {
        return new RespostaDfe(
            itens: [
                $this->nfse(2, self::CHAVE_A),
                $this->evento(3, self::CHAVE_A, 'CANCELAMENTO'),
                $this->nfse(57, self::CHAVE_B),
                $this->evento(58, self::CHAVE_B, 'CANCELAMENTO'),
                $this->nfse(76, self::CHAVE_C),
                $this->evento(77, self::CHAVE_C, 'CONFIRMACAO_PRESTADOR'),
                $this->nfse(78, self::CHAVE_D),
                $this->evento(79, self::CHAVE_D, 'REJEICAO_PRESTADOR'),
                $this->nfse(80, '35503082212345678000195000000000010026053624663742'),
            ],
            ultimoNsu: 80,
            statusProcessamento: 'NenhumDocumentoLocalizado',
            temMais: false,
        );
    }

    public function test_itensNfse_filtra_apenas_tipoDocumento_NFSE(): void
    {
        $resp = $this->fixtureLote();
        $nfses = $resp->itensNfse();
        self::assertCount(5, $nfses);
        foreach ($nfses as $i) {
            self::assertSame('NFSE', $i->tipoDocumento);
        }
    }

    public function test_itensEvento_filtra_apenas_tipoDocumento_EVENTO(): void
    {
        $resp = $this->fixtureLote();
        $eventos = $resp->itensEvento();
        self::assertCount(4, $eventos);
        foreach ($eventos as $i) {
            self::assertSame('EVENTO', $i->tipoDocumento);
        }
    }

    public function test_chavesCanceladas_retorna_chaves_unicas_com_cancelamento(): void
    {
        $resp = $this->fixtureLote();
        $canceladas = $resp->chavesCanceladas();
        self::assertCount(2, $canceladas);
        self::assertContains(self::CHAVE_A, $canceladas);
        self::assertContains(self::CHAVE_B, $canceladas);
    }

    public function test_chavesConfirmadas_match_substring_CONFIRMACAO(): void
    {
        // CONFIRMACAO_PRESTADOR / CONFIRMACAO_TOMADOR / CONFIRMACAO_INTERMEDIARIO
        // todos devem aparecer.
        $resp = $this->fixtureLote();
        self::assertSame([self::CHAVE_C], $resp->chavesConfirmadas());
    }

    public function test_chavesRejeitadas_match_substring_REJEICAO(): void
    {
        $resp = $this->fixtureLote();
        self::assertSame([self::CHAVE_D], $resp->chavesRejeitadas());
    }

    public function test_chavesSubstituidas_vazio_quando_sem_substituicao(): void
    {
        $resp = $this->fixtureLote();
        self::assertSame([], $resp->chavesSubstituidas());
    }

    public function test_chavesSubstituidas_detecta_evento(): void
    {
        $resp = new RespostaDfe(
            itens: [
                $this->nfse(1, self::CHAVE_A),
                $this->evento(2, self::CHAVE_A, 'SUBSTITUICAO'),
            ],
            ultimoNsu: 2,
            statusProcessamento: null,
            temMais: false,
        );
        self::assertSame([self::CHAVE_A], $resp->chavesSubstituidas());
    }

    public function test_foiCancelada_true_quando_tem_cancelamento(): void
    {
        $resp = $this->fixtureLote();
        self::assertTrue($resp->foiCancelada(self::CHAVE_A));
        self::assertTrue($resp->foiCancelada(self::CHAVE_B));
    }

    public function test_foiCancelada_true_para_substituicao(): void
    {
        $resp = new RespostaDfe(
            itens: [
                $this->nfse(1, self::CHAVE_A),
                $this->evento(2, self::CHAVE_A, 'SUBSTITUICAO'),
            ],
            ultimoNsu: 2,
            statusProcessamento: null,
            temMais: false,
        );
        // Substituição também cancela a NFS-e original
        self::assertTrue($resp->foiCancelada(self::CHAVE_A));
    }

    public function test_foiCancelada_false_para_confirmada_ou_emitida(): void
    {
        $resp = $this->fixtureLote();
        self::assertFalse($resp->foiCancelada(self::CHAVE_C));
        self::assertFalse($resp->foiCancelada('35503082212345678000195000000000010026053624663742'));
    }

    public function test_eventosDaChave_retorna_so_eventos_da_chave(): void
    {
        $resp = $this->fixtureLote();
        $eventos = $resp->eventosDaChave(self::CHAVE_A);
        self::assertCount(1, $eventos);
        self::assertSame('CANCELAMENTO', $eventos[0]->tipoEvento);

        // Chave sem evento → array vazio
        self::assertSame([], $resp->eventosDaChave('35503082212345678000195000000000010026053624663742'));
    }

    public function test_statusPorChave_hierarquia_correta(): void
    {
        $resp = $this->fixtureLote();
        self::assertSame(RespostaDfe::STATUS_CANCELADA, $resp->statusPorChave(self::CHAVE_A));
        self::assertSame(RespostaDfe::STATUS_CONFIRMADA, $resp->statusPorChave(self::CHAVE_C));
        self::assertSame(RespostaDfe::STATUS_REJEITADA, $resp->statusPorChave(self::CHAVE_D));
        self::assertSame(
            RespostaDfe::STATUS_EMITIDA,
            $resp->statusPorChave('35503082212345678000195000000000010026053624663742'),
        );
    }

    public function test_statusPorChave_null_para_chave_fora_do_lote(): void
    {
        $resp = $this->fixtureLote();
        self::assertNull($resp->statusPorChave('99999999999999999999999999999999999999999999999999'));
    }

    public function test_statusPorChave_SUBSTITUIDA_tem_prioridade_sobre_CANCELADA(): void
    {
        $resp = new RespostaDfe(
            itens: [
                $this->nfse(1, self::CHAVE_A),
                $this->evento(2, self::CHAVE_A, 'CANCELAMENTO'),
                $this->evento(3, self::CHAVE_A, 'SUBSTITUICAO'),
            ],
            ultimoNsu: 3,
            statusProcessamento: null,
            temMais: false,
        );
        self::assertSame(RespostaDfe::STATUS_SUBSTITUIDA, $resp->statusPorChave(self::CHAVE_A));
    }

    public function test_agruparPorChave_inclui_chaves_sem_evento(): void
    {
        $resp = $this->fixtureLote();
        $grupos = $resp->agruparPorChave();
        self::assertSame(['CANCELAMENTO'], $grupos[self::CHAVE_A]);
        self::assertSame(['CONFIRMACAO_PRESTADOR'], $grupos[self::CHAVE_C]);
        self::assertSame(
            [],
            $grupos['35503082212345678000195000000000010026053624663742'],
        );
    }

    private function nfse(int $nsu, string $chave): ItemDfe
    {
        return new ItemDfe(
            nsu: $nsu,
            tipoDocumento: 'NFSE',
            chaveAcesso: $chave,
            tipoEvento: null,
            sequencialEvento: null,
            dataHora: '2026-05-18T10:00:00',
            arquivoXmlGzipB64: null,
            bruto: [],
        );
    }

    private function evento(int $nsu, string $chave, string $tipoEvento): ItemDfe
    {
        return new ItemDfe(
            nsu: $nsu,
            tipoDocumento: 'EVENTO',
            chaveAcesso: $chave,
            tipoEvento: $tipoEvento,
            sequencialEvento: 1,
            dataHora: '2026-05-18T11:00:00',
            arquivoXmlGzipB64: null,
            bruto: [],
        );
    }
}
