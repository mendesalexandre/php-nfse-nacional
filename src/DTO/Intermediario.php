<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Support\Documento;

/**
 * Intermediário da operação (marketplace, plataforma de delivery, agência
 * de turismo, etc.) — grupo `<interm>` opcional do DPS, posicionado entre
 * `<toma>` e `<serv>` (leiaute SefinNacional V1.00.02, linhas 295-325).
 *
 * Estrutura espelha o `Tomador` por simplicidade — mesma escolha de
 * documento (CPF/CNPJ; NIF e cNaoNIF reservados para Onda 3 quando
 * houver suporte a tomador/intermediário estrangeiro), mesmas regras
 * de validação. Endereço só nacional por enquanto (`endNac`); `endExt`
 * fica para Onda 4.
 */
final class Intermediario
{
    public readonly string $documento;

    public function __construct(
        string $documento,
        public readonly string $nome,
        public readonly ?Endereco $endereco = null,
        public readonly ?string $email = null,
        public readonly ?string $telefone = null,
        /** Inscrição Municipal do intermediário (opcional). */
        public readonly ?string $inscricaoMunicipal = null,
    ) {
        $this->documento = Documento::limpar($documento);

        $errors = [];
        if (!Documento::ehCpf($this->documento) && !Documento::ehCnpj($this->documento)) {
            $errors[] = "Documento do intermediário inválido: {$documento} (esperado 11 ou 14 dígitos)";
        }
        $nomeTrim = trim($nome);
        if ($nomeTrim === '') {
            $errors[] = 'Nome do intermediário vazio';
        }
        if (mb_strlen($nomeTrim) > 150) {
            $errors[] = 'Nome do intermediário maior que 150 chars (limite TSinfPessoa)';
        }
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email do intermediário inválido: {$email}";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Intermediário inválido');
        }
    }

    public function ehPessoaFisica(): bool
    {
        return strlen($this->documento) === 11;
    }
}
