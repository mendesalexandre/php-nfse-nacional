<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\DTO;

/**
 * Códigos de motivo aceitos pelo SEFIN no evento e101101 (cancelamento).
 *
 * Lista oficial:
 *   1 — Erro na Emissão
 *   2 — Serviço não Prestado
 *   9 — Outros
 */
enum MotivoCancelamento: int
{
    case ErroEmissao = 1;
    case ServicoNaoPrestado = 2;
    case Outros = 9;

    public function label(): string
    {
        return match ($this) {
            self::ErroEmissao => 'Erro na Emissão',
            self::ServicoNaoPrestado => 'Serviço não Prestado',
            self::Outros => 'Outros',
        };
    }
}
