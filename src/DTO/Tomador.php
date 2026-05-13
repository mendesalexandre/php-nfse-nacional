<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Support\Documento;

/**
 * Tomador do serviço (cliente que recebe a NFS-e).
 *
 * Aceita CPF (11 dígitos) ou CNPJ (14 dígitos). Email e telefone são
 * opcionais — emissão sai sem se omitido.
 */
final class Tomador
{
    public readonly string $documento;

    public function __construct(
        string $documento,
        public readonly string $nome,
        public readonly Endereco $endereco,
        public readonly ?string $email = null,
        public readonly ?string $telefone = null,
        /**
         * Inscrição Municipal do tomador (opcional).
         *
         * Aceita pelo leiaute SefinNacional 1.6 no nó <toma><IM>. Útil
         * principalmente quando o tomador é PJ no mesmo município do
         * prestador (ex: tomador advocacia/consultoria) — permite cruzamento
         * de dados pela prefeitura e tratamento de imunidade tributária por
         * IM. Em cartório de RI, tomador é majoritariamente PF, então fica
         * normalmente null.
         */
        public readonly ?string $inscricaoMunicipal = null,
    ) {
        $this->documento = Documento::limpar($documento);

        $errors = [];
        if (!Documento::ehCpf($this->documento) && !Documento::ehCnpj($this->documento)) {
            $errors[] = "Documento do tomador inválido: {$documento} (esperado 11 ou 14 dígitos)";
        }
        if (trim($nome) === '') {
            $errors[] = 'Nome do tomador vazio';
        }
        if ($email !== null && $email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $errors[] = "Email do tomador inválido: {$email}";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Tomador inválido');
        }
    }

    public function ehPessoaFisica(): bool
    {
        return strlen($this->documento) === 11;
    }
}
