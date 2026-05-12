<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Dps;

use PhpNfseNacional\Dps\EventoSubstituicao;
use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class EventoSubstituicaoTest extends TestCase
{
    private const CHAVE_A = '51079092200179028000138000000000005726057774456203';
    private const CHAVE_B = '51079092200179028000138000000000005826057774456204';

    public function test_evento_valido(): void
    {
        $e = new EventoSubstituicao(
            chaveAcesso: self::CHAVE_A,
            chaveSubstituta: self::CHAVE_B,
            motivo: MotivoCancelamento::ErroEmissao,
            justificativa: 'Reemissão por divergência de valor',
        );

        self::assertSame('101102', $e->codigoTipoEvento());
        self::assertSame(self::CHAVE_A, $e->chaveAcesso());
        self::assertSame(self::CHAVE_B, $e->chaveSubstituta);
        self::assertSame(1, $e->nSequencial());
        self::assertSame('Cancelamento por substituição', $e->descricao());

        $grupo = $e->camposGrupo();
        self::assertSame('1', $grupo['cMotivo']);
        self::assertSame('Reemissão por divergência de valor', $grupo['xMotivo']);
        self::assertSame(self::CHAVE_B, $grupo['chSubstituta']);
    }

    public function test_chaves_iguais_sao_rejeitadas(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/não pode ser igual/');
        new EventoSubstituicao(
            chaveAcesso: self::CHAVE_A,
            chaveSubstituta: self::CHAVE_A,
            motivo: MotivoCancelamento::ErroEmissao,
            justificativa: 'Justificativa de pelo menos 15 chars',
        );
    }

    public function test_chave_substituta_invalida_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new EventoSubstituicao(
            chaveAcesso: self::CHAVE_A,
            chaveSubstituta: '123',
            motivo: MotivoCancelamento::ErroEmissao,
            justificativa: 'Justificativa de pelo menos 15 chars',
        );
    }

    public function test_justificativa_curta_demais_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new EventoSubstituicao(
            chaveAcesso: self::CHAVE_A,
            chaveSubstituta: self::CHAVE_B,
            motivo: MotivoCancelamento::ErroEmissao,
            justificativa: 'curta',
        );
    }

    public function test_chaves_aceitam_mascaras(): void
    {
        // Espaços, hífens, etc. são limpos antes da validação
        $a = '51079092200179028000138000000000005726057774456203';
        $b = ' 5107 9092 2001 7902 8000 0138 0000 0000 0058 26057 77445 6204 ';

        $e = new EventoSubstituicao(
            chaveAcesso: $a,
            chaveSubstituta: $b,
            motivo: MotivoCancelamento::ServicoNaoPrestado,
            justificativa: 'Reemissão com tomador correto',
        );

        self::assertSame(50, strlen($e->chaveSubstituta));
    }
}
