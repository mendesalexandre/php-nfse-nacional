<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use DateTimeImmutable;
use PhpNfseNacional\Enums\TipoDeducaoReducao;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Documento referenciado pra dedução/redução da base de cálculo do
 * ISSQN — corresponde a um `<docDedRed>` dentro de
 * `<vDedRed>/<documentos>` no DPS (leiaute V1.00.02 linhas 521-569).
 *
 * O leiaute oferece várias formas de identificar o documento (chave
 * NFS-e, chave NFe, NFS-e municipal antiga, NF/NFS legacy, número
 * livre). Esta versão cobre as 3 mais comuns: **chave de NFS-e**,
 * **chave de NFe**, e **número de documento livre** (`nDoc`). Os
 * formatos legacy (`NFSeMun`, `NFNFS`) ficam para iterações futuras.
 *
 * Use `nomeadamente`:
 *   - `comChaveNfse(...)` quando deduz de outra NFS-e nacional
 *   - `comChaveNfe(...)` quando deduz de uma NF-e (modelo 55, etc.)
 *   - `comNumeroDoc(...)` para documentos sem chave eletrônica
 *
 * O grupo `<fornec>` (fornecedor do documento) fica de fora desta
 * versão — preencher o documento basicamente já cumpre o caso comum.
 */
final class DocumentoDeducao
{
    /** Pelo menos UM dos três campos abaixo deve ser preenchido. */
    public function __construct(
        public readonly TipoDeducaoReducao $tipo,
        public readonly DateTimeImmutable $dataEmissaoDocumento,
        /** Valor total dedutível do documento (R$). */
        public readonly float $valorDedutivel,
        /** Valor que será efetivamente deduzido (R$). Deve ser ≤ `$valorDedutivel`. */
        public readonly float $valorDeducao,
        /** Chave de acesso de NFS-e (50 dígitos). XOR com `chNFe`/`nDoc`. */
        public readonly ?string $chaveNfse = null,
        /** Chave de acesso de NF-e (44 dígitos). XOR com `chNFse`/`nDoc`. */
        public readonly ?string $chaveNfe = null,
        /** Número/descrição do documento (até 255 chars). XOR com chaves. */
        public readonly ?string $numeroDocumento = null,
        /** Descrição obrigatória quando `$tipo = TipoDeducaoReducao::Outras` (até 150 chars). */
        public readonly ?string $descricaoOutraDeducao = null,
    ) {
        $errors = [];

        $refs = array_filter([$chaveNfse, $chaveNfe, $numeroDocumento]);
        if (count($refs) === 0) {
            $errors[] = 'Informe ao menos um identificador: chaveNfse, chaveNfe ou numeroDocumento';
        }
        if (count($refs) > 1) {
            $errors[] = 'Informe apenas UM identificador (chaveNfse XOR chaveNfe XOR numeroDocumento)';
        }
        if ($chaveNfse !== null && !preg_match('/^\d{50}$/', $chaveNfse)) {
            $errors[] = 'chaveNfse inválida (esperado 50 dígitos)';
        }
        if ($chaveNfe !== null && !preg_match('/^\d{44}$/', $chaveNfe)) {
            $errors[] = 'chaveNfe inválida (esperado 44 dígitos)';
        }
        if ($numeroDocumento !== null && mb_strlen($numeroDocumento) > 255) {
            $errors[] = 'numeroDocumento muito longo (máx 255 chars)';
        }
        if ($valorDedutivel <= 0) {
            $errors[] = 'valorDedutivel deve ser maior que zero';
        }
        if ($valorDeducao <= 0) {
            $errors[] = 'valorDeducao deve ser maior que zero';
        }
        if ($valorDeducao > $valorDedutivel) {
            $errors[] = sprintf(
                'valorDeducao (%.2f) maior que valorDedutivel (%.2f)',
                $valorDeducao,
                $valorDedutivel,
            );
        }
        if ($tipo === TipoDeducaoReducao::Outras) {
            if ($descricaoOutraDeducao === null || trim($descricaoOutraDeducao) === '') {
                $errors[] = 'descricaoOutraDeducao é obrigatória quando tipo=Outras (99)';
            }
        }
        if ($descricaoOutraDeducao !== null && mb_strlen($descricaoOutraDeducao) > 150) {
            $errors[] = 'descricaoOutraDeducao muito longa (máx 150 chars)';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'DocumentoDeducao inválido');
        }
    }
}
