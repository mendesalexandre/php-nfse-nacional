<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Dps;

use PhpNfseNacional\Dps\EventoConfirmacao;
use PhpNfseNacional\Enums\AutorManifestacao;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class EventoConfirmacaoTest extends TestCase
{
    private const CHAVE = '51079092200179028000138000000000005826056662521939';

    public function test_codigos_por_autor(): void
    {
        $prest = new EventoConfirmacao(self::CHAVE, AutorManifestacao::Prestador);
        $tom = new EventoConfirmacao(self::CHAVE, AutorManifestacao::Tomador);
        $inter = new EventoConfirmacao(self::CHAVE, AutorManifestacao::Intermediario);

        self::assertSame('202201', $prest->codigoTipoEvento());
        self::assertSame('203202', $tom->codigoTipoEvento());
        self::assertSame('204203', $inter->codigoTipoEvento());
    }

    public function test_descricao_inclui_autor(): void
    {
        $tom = new EventoConfirmacao(self::CHAVE, AutorManifestacao::Tomador);
        self::assertStringContainsString('Tomador', $tom->descricao());
    }

    public function test_grupo_vazio(): void
    {
        $e = new EventoConfirmacao(self::CHAVE, AutorManifestacao::Prestador);
        self::assertSame([], $e->camposGrupo());
    }

    public function test_chave_invalida_rejeitada(): void
    {
        $this->expectException(ValidationException::class);
        new EventoConfirmacao('123', AutorManifestacao::Tomador);
    }
}
