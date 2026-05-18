<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Grupo `<infoCompl>` (Informações Complementares) — opcional dentro
 * de `<serv>` no DPS (leiaute SefinNacional V1.00.02, linhas 505-508).
 * Posicionado como ÚLTIMO filho de `<serv>`.
 *
 * Todos os campos são opcionais (todos `minOccurs="0"`). Use pra
 * adicionar contexto livre à NFS-e — referência a documento técnico,
 * número de pedido, ordem de serviço, contrato, observações etc.
 *
 * Pra texto livre simples, basta `xInfComp` (até 2000 chars). Os
 * outros dois campos são usados em cenários específicos do leiaute
 * (referência a docs técnicos da Receita).
 */
final class InformacoesComplementares
{
    public function __construct(
        /**
         * Texto livre de observações da NFS-e. Limite 2000 chars
         * (XSD `TSDescInfCompl`). Suporta UTF-8 mas o
         * `TextoSanitizador` aplicado pelo `DpsBuilder` normaliza
         * caracteres tipográficos (en-dash, aspas curvas, etc.) pra
         * ASCII pra evitar E1235.
         */
        public readonly ?string $xInfComp = null,
        /**
         * Identificador de documento técnico da Receita Federal
         * (`TSDRT`, 1-40 chars). Uso restrito — só preencher quando
         * houver doc técnico federal vinculado.
         */
        public readonly ?string $idDocTec = null,
        /**
         * Referência a documento externo (NF-e, NFS-e, contrato, etc.)
         * em formato livre. Limite 255 chars (XSD `TSDesc255`).
         */
        public readonly ?string $docRef = null,
    ) {
        $errors = [];

        if ($xInfComp !== null) {
            $len = mb_strlen($xInfComp);
            if ($len < 1) {
                $errors[] = 'xInfComp vazio (use null se não quer informar)';
            } elseif ($len > 2000) {
                $errors[] = "xInfComp muito longo: {$len} chars (máx 2000)";
            }
        }
        if ($idDocTec !== null) {
            $len = mb_strlen($idDocTec);
            if ($len < 1) {
                $errors[] = 'idDocTec vazio (use null se não quer informar)';
            } elseif ($len > 40) {
                $errors[] = "idDocTec muito longo: {$len} chars (máx 40)";
            }
        }
        if ($docRef !== null) {
            $len = mb_strlen($docRef);
            if ($len < 1) {
                $errors[] = 'docRef vazio (use null se não quer informar)';
            } elseif ($len > 255) {
                $errors[] = "docRef muito longo: {$len} chars (máx 255)";
            }
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'InformacoesComplementares inválidas');
        }
    }

    /**
     * True se pelo menos um campo está preenchido — útil pra
     * pular emissão do grupo `<infoCompl>` quando tudo é null.
     */
    public function temConteudo(): bool
    {
        return $this->xInfComp !== null
            || $this->idDocTec !== null
            || $this->docRef !== null;
    }
}
