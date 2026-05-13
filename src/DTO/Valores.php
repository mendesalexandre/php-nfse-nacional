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
 *
 * Precisão:
 *   - **Valores monetários** (vServ, vDR, vBC, vISSQN, vLiq) — 2 casas
 *     decimais. Convenção do leiaute, igual NF-e.
 *   - **Alíquotas** (`pTotTribMun`) — **2 casas decimais fixas no DPS**.
 *     O leiaute SefinNacional 1.6 restringe ao tipo `TSDec3V2`. Diferente
 *     da NF-e (NT 03.14, que ampliou pra 4 casas) — confirmado em
 *     homologação SEFIN: enviar `4.0000` causa E1235. O SDK arredonda
 *     automaticamente via `number_format(..., 2)` ao montar o DPS.
 *   - Pra alíquotas reduzidas (ex: 3.5125%), o SDK arredonda HALF_UP
 *     pra 2 casas (→ 3.51). Se precisar preservar a alíquota fracionada
 *     no leiaute futuro (NT que ampliar pra 4 casas), basta mudar a
 *     formatação no `DpsBuilder::appendValores`.
 *
 * Arredondamento:
 *   - Padrão: PHP `round()` modo `HALF_UP` (5 arredonda pra cima):
 *     - 0.125 → 0.13   |   0.124 → 0.12   |   0.005 → 0.01
 *   - **Caveat float-point:** valores que terminam em 5 na 3ª casa
 *     decimal podem não bater com a aritmética intuitiva por causa da
 *     representação binária. Ex: `round(0.115, 2)` retorna `0.11` (não
 *     `0.12`), porque `0.115` é armazenado como `0.114999…`. Pega gente
 *     em valores como ISSQN apurado `0.115`, `0.225`, `0.335`. Pra
 *     precisão crítica, calcule em centavos (int) e divida por 100, ou
 *     use BCMath/strings.
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
