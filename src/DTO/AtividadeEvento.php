<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use DateTimeImmutable;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Grupo `<atvEvento>` (Atividade de Evento) — opcional dentro de
 * `<serv>`, para serviços vinculados a eventos artísticos, culturais,
 * esportivos, etc. XSD V1.01 (TCAtvEvento, linhas 1486-1516).
 *
 * Estrutura:
 *   xNome → dtIni → dtFim → choice(idAtvEvt | end)
 *
 * O `idAtvEvt` é código fornecido pela Administração Tributária
 * Municipal. Se o município não fornece, use `endereco` como
 * alternativa (mesma estrutura do `Endereco` nacional).
 */
final class AtividadeEvento
{
    public function __construct(
        /** Descrição do evento (até 255 chars). */
        public readonly string $nome,
        public readonly DateTimeImmutable $dataInicio,
        public readonly DateTimeImmutable $dataFim,
        /** Choice 1: código identificador do evento na Administração Municipal. */
        public readonly ?string $idAtividadeEvento = null,
        /** Choice 2: endereço do local do evento. */
        public readonly ?Endereco $endereco = null,
    ) {
        $errors = [];

        if (trim($nome) === '') {
            $errors[] = 'nome do evento vazio';
        }
        if (mb_strlen($nome) > 255) {
            $errors[] = 'nome do evento maior que 255 chars';
        }
        if ($dataFim < $dataInicio) {
            $errors[] = 'dataFim antes de dataInicio';
        }

        $refs = array_filter([$idAtividadeEvento, $endereco]);
        if (count($refs) === 0) {
            $errors[] = 'Informe idAtividadeEvento OU endereco (choice obrigatório no schema)';
        }
        if (count($refs) > 1) {
            $errors[] = 'Informe apenas UM: idAtividadeEvento XOR endereco';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'AtividadeEvento inválida');
        }
    }
}
