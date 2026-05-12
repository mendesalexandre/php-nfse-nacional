<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Valores monetários da NFS-e.
 *
 * Convenção SEFIN ("ISSQN por dentro"):
 *   - valorServicos = valor cobrado do tomador (inclui ISSQN dentro)
 *   - deducoesReducoes = soma das deduções não-tributáveis + o próprio ISSQN
 *   - SEFIN computa: vBC = vServ − vDR  →  ISSQN = vBC × alíquota
 *
 * Pra que a BC computada pelo SEFIN bata com a base real (valor cobrado
 * menos deduções), o ISSQN PRECISA estar incluído em deducoesReducoes.
 * Equivalente: vDR = vServ − BC. Não é intuitivo mas é a regra do
 * leiaute SEFIN Nacional 1.6.
 */
final class Valores
{
    public function __construct(
        public readonly float $valorServicos,
        public readonly float $deducoesReducoes,
        public readonly float $aliquotaIssqnPercentual,
        public readonly bool $issqnRetido = false,
        public readonly float $descontoIncondicionado = 0.0,
    ) {
        $errors = [];

        if ($valorServicos <= 0) {
            $errors[] = 'valorServicos deve ser maior que zero';
        }
        if ($deducoesReducoes < 0) {
            $errors[] = 'deducoesReducoes não pode ser negativo';
        }
        if ($deducoesReducoes > $valorServicos) {
            $errors[] = "deducoesReducoes ({$deducoesReducoes}) maior que valorServicos ({$valorServicos})";
        }
        if ($aliquotaIssqnPercentual < 0 || $aliquotaIssqnPercentual > 10) {
            $errors[] = "Alíquota inválida: {$aliquotaIssqnPercentual}% (esperado entre 0 e 10)";
        }
        if ($descontoIncondicionado < 0) {
            $errors[] = 'descontoIncondicionado não pode ser negativo';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Valores inválidos');
        }
    }

    /**
     * Base de cálculo do ISSQN conforme o SEFIN computa:
     *   vBC = vServ − descIncond − vDR
     */
    public function baseCalculo(): float
    {
        return round(
            $this->valorServicos - $this->descontoIncondicionado - $this->deducoesReducoes,
            2,
        );
    }

    /**
     * ISSQN apurado (= vBC × alíquota), arredondado pra 2 casas.
     */
    public function valorIssqn(): float
    {
        return round($this->baseCalculo() * $this->aliquotaIssqnPercentual / 100, 2);
    }
}
