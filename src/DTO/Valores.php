<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Enums\TipoImunidadeIssqn;
use PhpNfseNacional\Enums\TipoTributacaoIssqn;
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
 *     pra 2 casas (→ 3.51).
 *
 * IMPORTANTE — `aliquotaIssqnPercentual` é DECLARATÓRIA, não tributária:
 *   O valor que você passa aqui vai pro `<pTotTribMun>` do DPS, que é a
 *   "alíquota aproximada total dos tributos municipais" (Lei 12.741/2012,
 *   Transparência Fiscal). NÃO define a alíquota efetiva do ISSQN.
 *
 *   A alíquota real é determinada pelo cadastro tributário do município
 *   no SEFIN (cruzando `cTribNac` × `cClassTrib`) e vem na resposta como
 *   `<pAliqAplic>`. Validado empiricamente: enviando `pTotTribMun=3.56`
 *   pro cartório de Sinop, SEFIN devolveu `pAliqAplic=4.00` (alíquota
 *   oficial cadastrada) e calculou o ISSQN sobre 4%.
 *
 *   Em outras palavras: passe uma estimativa razoável da carga total.
 *   Em cartório de RI de Sinop fica `4.00`. Pra outros municípios/segmentos,
 *   consulte a alíquota cadastrada pela prefeitura.
 *
 * Arredondamento:
 *   - Padrão: PHP `round()` modo `HALF_UP` (5 arredonda pra cima):
 *     - 0.125 → 0.13   |   0.124 → 0.12   |   0.005 → 0.01
 *   - PHP 8+ resolveu o caveat float-point clássico — `round(0.115, 2)`
 *     retorna `0.12` corretamente em PHP 8.1+. Em PHP 7 retornava `0.11`
 *     porque `0.115` em binário é `0.114999…`. Como o SDK requer PHP 8.1+,
 *     esse pega não atinge mais.
 */
final class Valores
{
    public function __construct(
        public readonly float $valorServicos,
        public readonly float $deducoesReducoes,
        public readonly float $aliquotaIssqnPercentual,
        public readonly bool $issqnRetido = false,
        public readonly float $descontoIncondicionado = 0.0,
        /**
         * Prestador é dispensado do ISSQN apurado (MEI, isento, imune).
         *
         * Quando true, o grupo `<totTrib>` é emitido como
         * `<indTotTrib>0</indTotTrib>` (valor total dos tributos NÃO
         * informado) em vez de `<pTotTrib>` — mesmo padrão que o emissor
         * web do SEFIN utiliza para CNPJ MEI. Ambos são opções válidas do
         * *choice* TSTotTrib no leiaute SefinNacional 1.6.
         *
         * Os demais campos (`aliquotaIssqnPercentual`, etc.) continuam
         * exigidos para os cálculos internos da convenção "ISSQN por
         * dentro", mas não vão ao XML quando esta flag está ativa.
         */
        public readonly bool $dispensadoIssqn = false,
        /**
         * Tipo de tributação do ISSQN — campo `<tribISSQN>`. Default
         * null → emite `1` (Operação Tributável). Para imunidade, exportação
         * ou não-incidência, passe o case correspondente.
         *
         * Quando `Imunidade`, considere também preencher `$imunidade`
         * (`<tpImunidade>` opcional dentro de `<tribMun>`).
         * Quando `ExportacaoServico`, preencha `$codigoPaisResultado`
         * (`<cPaisResult>` com código ISO 2 chars).
         */
        public readonly ?TipoTributacaoIssqn $tributacaoIssqn = null,
        /**
         * Código ISO (2 chars) do país onde se verificou o resultado da
         * prestação. Aplicável quando `$tributacaoIssqn = ExportacaoServico`.
         * Vai em `<cPaisResult>` dentro de `<tribMun>`.
         */
        public readonly ?string $codigoPaisResultado = null,
        /**
         * Grupo `<BM>` (Benefício Municipal) dentro de `<tribMun>`.
         * Quando informado, o DPS referencia o `nBM` cadastrado pelo
         * município no Sistema Nacional. Default null = sem benefício.
         */
        public readonly ?BeneficioMunicipal $beneficioMunicipal = null,
        /**
         * Grupo `<exigSusp>` (Exigibilidade Suspensa) dentro de `<tribMun>`.
         * Quando informado, o ISSQN tem cobrança suspensa por processo
         * judicial ou administrativo.
         */
        public readonly ?ExigibilidadeSuspensa $exigibilidadeSuspensa = null,
        /**
         * Tipo de imunidade do ISSQN — `<tpImunidade>` dentro de `<tribMun>`.
         * Aplicável quando `$tributacaoIssqn = Imunidade`.
         */
        public readonly ?TipoImunidadeIssqn $imunidade = null,
        /**
         * Alíquota efetiva do ISSQN no município de incidência —
         * `<pAliq>` dentro de `<tribMun>` (linha 266 do leiaute).
         *
         * Necessária apenas quando o município **não pertence** ao Sistema
         * Nacional NFS-e (sem cadastro tributário no portal). Para
         * municípios conveniados, o SEFIN aplica a alíquota cadastrada
         * (`<pAliqAplic>`) ignorando o `pAliq` informado.
         *
         * NÃO confundir com `$aliquotaIssqnPercentual`, que vai pra
         * `<pTotTribMun>` (declaratória, Lei 12.741/2012).
         */
        public readonly ?float $aliquotaMunicipal = null,
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
        if ($codigoPaisResultado !== null && !preg_match('/^[A-Z]{2}$/', $codigoPaisResultado)) {
            $errors[] = "codigoPaisResultado inválido: '{$codigoPaisResultado}' (esperado 2 letras maiúsculas, código ISO)";
        }
        if ($aliquotaMunicipal !== null && ($aliquotaMunicipal < 0 || $aliquotaMunicipal > 10)) {
            $errors[] = "aliquotaMunicipal inválida: {$aliquotaMunicipal}% (esperado entre 0 e 10)";
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
