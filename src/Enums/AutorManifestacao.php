<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Autor de um evento de manifestação de NFS-e (Confirmação ou Rejeição).
 *
 * Determina o código de evento gerado:
 *
 *   | Autor           | Confirmação | Rejeição |
 *   |-----------------|-------------|----------|
 *   | Prestador       | 202201      | 202205   |
 *   | Tomador         | 203202      | 203206   |
 *   | Intermediario   | 204203      | 204207   |
 *
 * A Confirmação Tácita (205204) é gerada automaticamente pelo sistema
 * — não é passível de emissão via API.
 */
enum AutorManifestacao: int
{
    case Prestador     = 1;
    case Tomador       = 2;
    case Intermediario = 3;

    /**
     * Código do evento de Confirmação correspondente ao autor.
     */
    public function codigoConfirmacao(): string
    {
        return match ($this) {
            self::Prestador     => '202201',
            self::Tomador       => '203202',
            self::Intermediario => '204203',
        };
    }

    /**
     * Código do evento de Rejeição correspondente ao autor.
     */
    public function codigoRejeicao(): string
    {
        return match ($this) {
            self::Prestador     => '202205',
            self::Tomador       => '203206',
            self::Intermediario => '204207',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Prestador     => 'Prestador',
            self::Tomador       => 'Tomador',
            self::Intermediario => 'Intermediário',
        };
    }
}
