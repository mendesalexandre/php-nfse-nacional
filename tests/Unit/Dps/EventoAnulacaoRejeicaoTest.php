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
    private const ID_REJ = 'PRE51079092200179028000138000000000005826056662521939203206';

    public function test_evento_valido(): void
    {
        $e = new EventoAnulacaoRejeicao(
            chaveAcesso: self::CHAVE,
            idEvManifRej: self::ID_REJ,
            xMotivo: 'Rejeição feita por engano',
        );

        self::assertSame('205208', $e->codigoTipoEvento());
        self::assertSame(self::CHAVE, $e->chaveAcesso());
        self::assertSame(self::ID_REJ, $e->idEvManifRej);
        self::assertSame('Rejeição feita por engano', $e->xMotivo);

        $grupo = $e->camposGrupo();
        self::assertSame(self::ID_REJ, $grupo['idEvManifRej']);
        self::assertSame('Rejeição feita por engano', $grupo['xMotivo']);
    }

    public function test_id_invalido_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new EventoAnulacaoRejeicao(
            chaveAcesso: self::CHAVE,
            idEvManifRej: 'PRE123',  // formato errado
            xMotivo: 'Motivo válido com pelo menos 15 chars',
        );
    }

    public function test_xMotivo_curto_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new EventoAnulacaoRejeicao(
            chaveAcesso: self::CHAVE,
            idEvManifRej: self::ID_REJ,
            xMotivo: 'curto',
        );
    }
}
