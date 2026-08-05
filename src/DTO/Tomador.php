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
        /**
         * Endereço nacional (`Endereco`) ou estrangeiro (`EnderecoExterior`).
         * O `DpsBuilder` detecta o tipo e emite `<endNac>` ou `<endExt>`
         * dentro de `<end>` conforme TCEndereco (XSD V1.01).
         *
         * Opcional pelo leiaute oficial (`toma/end` é `0-1`, Anexo IV V1.00.02
         * linha 274). Útil pra emissões avulsas onde o tomador não foi
         * cadastrado (cartório de balcão, serviço a transeunte, etc.).
         */
        public readonly Endereco|EnderecoExterior|null $endereco = null,
        public readonly ?string $email = null,
        public readonly ?string $telefone = null,
        /**
         * Inscrição Municipal do tomador (opcional).
         *
         * Aceita pelo leiaute SefinNacional 1.6 no nó <toma><IM>. Útil
         * principalmente quando o tomador é PJ no mesmo município do
         * prestador (ex: tomador advocacia/consultoria) — permite cruzamento
         * de dados pela prefeitura e tratamento de imunidade tributária por
         * IM. Em emissor PJ, tomador é majoritariamente PF, então fica
         * normalmente null.
         */
        public readonly ?string $inscricaoMunicipal = null,
    ) {
        $this->documento = Documento::limpar($documento);

        $errors = [];
        if (!Documento::isCPF($this->documento) && !Documento::isCNPJ($this->documento)) {
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

    public function isPessoaFisica(): bool
    {
        return strlen($this->documento) === 11;
    }

    /** @deprecated Renomeado para {@see isPessoaFisica()} */
    public function ehPessoaFisica(): bool
    {
        return $this->isPessoaFisica();
    }
}
