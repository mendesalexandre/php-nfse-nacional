<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use DateTimeImmutable;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Identificação do DPS — número sequencial, série, data de competência.
 *
 * O nDPS é gerado por quem usa o SDK (geralmente AANNNNNN = ano + 6 dígitos
 * sequenciais, alinhado com o legado Delphi). Não geramos automaticamente
 * porque cada operação tem semântica própria sobre como alocar o próximo.
 */
final class Identificacao
{
    public function __construct(
        public readonly int $numeroDps,
        public readonly string $serie = '1',
        public readonly ?DateTimeImmutable $dataCompetencia = null,
        public readonly TipoEmissaoDps $tipoEmissao = TipoEmissaoDps::Normal,
    ) {
        $errors = [];

        if ($numeroDps < 1 || $numeroDps > 99_999_999) {
            $errors[] = "numeroDps fora de [1..99999999]: {$numeroDps}";
        }
        if (mb_strlen($serie) < 1 || mb_strlen($serie) > 5) {
            $errors[] = "serie inválida: {$serie}";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Identificação do DPS inválida');
        }
    }

    public function dataCompetenciaResolvida(): DateTimeImmutable
    {
        return $this->dataCompetencia ?? new DateTimeImmutable();
    }
}
