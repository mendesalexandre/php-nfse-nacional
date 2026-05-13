<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Dps;

use PhpNfseNacional\Dps\EventoRejeicao;
use PhpNfseNacional\DTO\MotivoRejeicao;
use PhpNfseNacional\Enums\AutorManifestacao;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class EventoRejeicaoTest extends TestCase
{
    private const CHAVE = '51079092200179028000138000000000005826056662521939';

    public function test_codigos_por_autor(): void
    {
        $prest = new EventoRejeicao(self::CHAVE, AutorManifestacao::Prestador, MotivoRejeicao::Duplicidade);
        $tom = new EventoRejeicao(self::CHAVE, AutorManifestacao::Tomador, MotivoRejeicao::Duplicidade);
        $inter = new EventoRejeicao(self::CHAVE, AutorManifestacao::Intermediario, MotivoRejeicao::Duplicidade);

        self::assertSame('202205', $prest->codigoTipoEvento());
        self::assertSame('203206', $tom->codigoTipoEvento());
        self::assertSame('204207', $inter->codigoTipoEvento());
    }

    public function test_xMotivo_obrigatorio_quando_motivo_outros(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessageMatches('/xMotivo obrigat/');
        new EventoRejeicao(
            chaveAcesso: self::CHAVE,
            autor: AutorManifestacao::Tomador,
            motivo: MotivoRejeicao::Outros,
            xMotivo: '',
        );
    }

    public function test_xMotivo_opcional_para_outros_motivos(): void
    {
        // Motivo diferente de Outros não exige xMotivo
        $e = new EventoRejeicao(
            self::CHAVE,
            AutorManifestacao::Tomador,
            MotivoRejeicao::Duplicidade,
        );
        $grupo = $e->camposGrupo();
        self::assertSame('1', $grupo['cMotivo']);
        self::assertArrayNotHasKey('xMotivo', $grupo);
    }

    public function test_xMotivo_acima_de_200_chars_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new EventoRejeicao(
            self::CHAVE,
            AutorManifestacao::Tomador,
            MotivoRejeicao::Duplicidade,
            str_repeat('a', 201),
        );
    }

    public function test_grupo_inclui_cMotivo_e_xMotivo_quando_preenchido(): void
    {
        $e = new EventoRejeicao(
            self::CHAVE,
            AutorManifestacao::Tomador,
            MotivoRejeicao::Outros,
            'Cliente desistiu do serviço após emissão',
        );
        $grupo = $e->camposGrupo();
        self::assertSame('9', $grupo['cMotivo']);
        self::assertSame('Cliente desistiu do serviço após emissão', $grupo['xMotivo']);
    }
}
