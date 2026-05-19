<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Grupo `<obra>` (Informação de Obra) — opcional dentro de `<serv>`,
 * para serviços vinculados a obras de construção civil. XSD V1.01
 * (TCInfoObra, linhas 1517-1549).
 *
 * Estrutura:
 *   inscImobFisc? → choice(cObra | cCIB | end)
 *
 * Choice obrigatório de 1 dos 3:
 * - **cObra**: número CNO (Cadastro Nacional de Obras) ou CEI legacy
 * - **cCIB**: Código do Cadastro Imobiliário Brasileiro
 * - **endereco**: quando obra não tem cadastro CNO/CIB
 */
final class InformacaoObra
{
    public function __construct(
        /** Inscrição imobiliária fiscal (IPTU) da obra (opcional). */
        public readonly ?string $inscricaoImobiliariaFiscal = null,
        /** Choice 1: CNO ou CEI legacy. */
        public readonly ?string $codigoObra = null,
        /** Choice 2: CIB (Cadastro Imobiliário Brasileiro). */
        public readonly ?string $codigoCib = null,
        /** Choice 3: endereço da obra. */
        public readonly ?Endereco $endereco = null,
    ) {
        $errors = [];

        $refs = array_filter([$codigoObra, $codigoCib, $endereco]);
        if (count($refs) === 0) {
            $errors[] = 'Informe codigoObra, codigoCib OU endereco (choice obrigatório no schema)';
        }
        if (count($refs) > 1) {
            $errors[] = 'Informe apenas UM: codigoObra XOR codigoCib XOR endereco';
        }

        if ($inscricaoImobiliariaFiscal !== null && mb_strlen($inscricaoImobiliariaFiscal) > 30) {
            $errors[] = 'inscricaoImobiliariaFiscal muito longa (máx 30 chars)';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'InformacaoObra inválida');
        }
    }
}
