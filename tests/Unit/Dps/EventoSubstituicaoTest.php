<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Dps;

use PhpNfseNacional\Dps\EventoSubstituicao;
use PhpNfseNacional\DTO\MotivoSubstituicao;
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
            motivo: MotivoSubstituicao::DesenquadramentoSimples,
            justificativa: 'Reemissão por divergência de valor',
        );

        self::assertSame('105102', $e->codigoTipoEvento());
        self::assertSame(self::CHAVE_A, $e->chaveAcesso());
        self::assertSame(self::CHAVE_B, $e->chaveSubstituta);
        self::assertSame(1, $e->nSequencial());
        self::assertSame('Cancelamento de NFS-e por Substituição', $e->descricao());

        $grupo = $e->camposGrupo();
        // TSCodJustSubst usa formato 2 dígitos zero-padded (01..99)
        self::assertSame('01', $grupo['cMotivo']);
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
            motivo: MotivoSubstituicao::DesenquadramentoSimples,
            justificativa: 'Justificativa de pelo menos 15 chars',
        );
    }

    public function test_chave_substituta_invalida_eh_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new EventoSubstituicao(
            chaveAcesso: self::CHAVE_A,
            chaveSubstituta: '123',
            motivo: MotivoSubstituicao::DesenquadramentoSimples,
            justificativa: 'Justificativa de pelo menos 15 chars',
        );
    }

    public function test_justificativa_curta_demais_eh_rejeitada_com_motivo_outros(): void
    {
        // xMotivo só é obrigatório quando motivo=Outros (TSCodJustSubst 99)
        $this->expectException(ValidationException::class);
        new EventoSubstituicao(
            chaveAcesso: self::CHAVE_A,
            chaveSubstituta: self::CHAVE_B,
            motivo: MotivoSubstituicao::Outros,
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
            motivo: MotivoSubstituicao::EnquadramentoSimples,
            justificativa: 'Reemissão com tomador correto',
        );

        self::assertSame(50, strlen($e->chaveSubstituta));
    }
}
