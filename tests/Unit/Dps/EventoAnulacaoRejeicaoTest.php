<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Dps;

use PhpNfseNacional\Dps\EventoAnulacaoRejeicao;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class EventoAnulacaoRejeicaoTest extends TestCase
{
    private const CHAVE = '51079092200179028000138000000000005826056662521939';
    // PRE + 50 dígitos chave + 6 dígitos tipoEvento (203206 = Rejeição Tomador)
    // SDK normaliza pra TSIdNumEvento (59 dígitos puros: chave+tipoEvento+nSeq001).
    private const ID_REJ_PRE = 'PRE51079092200179028000138000000000005826056662521939203206';
    private const ID_REJ_NUM = '51079092200179028000138000000000005826056662521939203206001';

    private const CPF = '12345678909';

    public function test_evento_valido(): void
    {
        $e = new EventoAnulacaoRejeicao(
            chaveAcesso: self::CHAVE,
            cpfAgente: self::CPF,
            idEvManifRej: self::ID_REJ_PRE,
            xMotivo: 'Rejeição feita por engano',
        );

        self::assertSame('205208', $e->codigoTipoEvento());
        self::assertSame(self::CHAVE, $e->chaveAcesso());
        self::assertSame(self::CPF, $e->cpfAgente);
        // SDK normaliza PRE+56dig pra 59 dígitos puros (chave50+tipoEvento6+nSeq001)
        self::assertSame(self::ID_REJ_NUM, $e->idEvManifRej);
        self::assertSame('Rejeição feita por engano', $e->xMotivo);

        // Ordem dos campos importa pro XSD: CPFAgTrib → idEvManifRej → xMotivo
        $grupo = $e->camposGrupo();
        self::assertSame(['CPFAgTrib', 'idEvManifRej', 'xMotivo'], array_keys($grupo));
        self::assertSame(self::CPF, $grupo['CPFAgTrib']);
        self::assertSame(self::ID_REJ_NUM, $grupo['idEvManifRej']);
        self::assertSame('Rejeição feita por engano', $grupo['xMotivo']);
    }

    public function test_id_invalido_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new EventoAnulacaoRejeicao(
            chaveAcesso: self::CHAVE,
            cpfAgente: self::CPF,
            idEvManifRej: 'PRE123',  // formato errado
            xMotivo: 'Motivo válido com pelo menos 15 chars',
        );
    }

    public function test_xMotivo_curto_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new EventoAnulacaoRejeicao(
            chaveAcesso: self::CHAVE,
            cpfAgente: self::CPF,
            idEvManifRej: self::ID_REJ_PRE,
            xMotivo: 'curto',
        );
    }

    public function test_cpf_invalido_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new EventoAnulacaoRejeicao(
            chaveAcesso: self::CHAVE,
            cpfAgente: '123',
            idEvManifRej: self::ID_REJ_PRE,
            xMotivo: 'Motivo válido com pelo menos 15 chars',
        );
    }
}
