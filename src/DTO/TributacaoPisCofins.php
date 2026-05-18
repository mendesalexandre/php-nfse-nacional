<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Enums\CstPisCofins;
use PhpNfseNacional\Enums\TipoRetencaoPisCofins;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Grupo `<piscofins>` dentro de `<tribFed>` — informações de PIS/COFINS
 * (leiaute SefinNacional V1.00.02, linhas 269-276).
 *
 * Todos os campos exceto `cst` são opcionais. Quando o serviço não tem
 * incidência de PIS/COFINS, basta passar `cst = OperacaoSemIncidenciaContribuicao`
 * e omitir os demais.
 *
 * Quando há retenção do PIS/COFINS, preencher pelo menos:
 *   - `cst` (situação tributária)
 *   - `vBC` (base de cálculo)
 *   - `pAliqPis` + `pAliqCofins` (alíquotas)
 *   - `vPis` + `vCofins` (valores apurados)
 *   - `tipoRetencao = Retido` (informa que houve retenção pelo tomador)
 */
final class TributacaoPisCofins
{
    public function __construct(
        public readonly CstPisCofins $cst,
        public readonly ?float $valorBaseCalculo = null,
        public readonly ?float $aliquotaPis = null,
        public readonly ?float $aliquotaCofins = null,
        public readonly ?float $valorPis = null,
        public readonly ?float $valorCofins = null,
        public readonly ?TipoRetencaoPisCofins $tipoRetencao = null,
    ) {
        $errors = [];

        $faixaPercentual = fn (string $campo, ?float $v) => $v !== null && ($v < 0 || $v > 100)
            ? "{$campo} fora da faixa: {$v}% (esperado 0-100)"
            : null;
        $faixaMonetario = fn (string $campo, ?float $v) => $v !== null && $v < 0
            ? "{$campo} negativo: {$v}"
            : null;

        foreach ([
            $faixaMonetario('valorBaseCalculo', $valorBaseCalculo),
            $faixaPercentual('aliquotaPis', $aliquotaPis),
            $faixaPercentual('aliquotaCofins', $aliquotaCofins),
            $faixaMonetario('valorPis', $valorPis),
            $faixaMonetario('valorCofins', $valorCofins),
        ] as $e) {
            if ($e !== null) {
                $errors[] = $e;
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'TributacaoPisCofins inválida');
        }
    }

    public function temConteudoAlemDoCst(): bool
    {
        return $this->valorBaseCalculo !== null
            || $this->aliquotaPis !== null
            || $this->aliquotaCofins !== null
            || $this->valorPis !== null
            || $this->valorCofins !== null
            || $this->tipoRetencao !== null;
    }
}
