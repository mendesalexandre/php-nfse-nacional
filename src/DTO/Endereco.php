<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Endereço de prestador ou tomador.
 *
 * Imutável (readonly). Validações no construtor — falha cedo se CEP ou
 * código IBGE estão fora do formato.
 */
final class Endereco
{
    public function __construct(
        public readonly string $logradouro,
        public readonly string $numero,
        public readonly string $bairro,
        public readonly string $cep,
        public readonly string $codigoMunicipioIbge,
        public readonly string $uf,
        public readonly ?string $complemento = null,
    ) {
        $errors = [];

        if (trim($logradouro) === '') {
            $errors[] = 'logradouro vazio';
        }
        if (trim($numero) === '') {
            $errors[] = 'numero vazio';
        }
        if (trim($bairro) === '') {
            $errors[] = 'bairro vazio';
        }
        $cepDigitos = preg_replace('/\D/', '', $cep) ?? '';
        if (strlen($cepDigitos) !== 8) {
            $errors[] = "CEP inválido: {$cep} (esperado 8 dígitos)";
        }
        if (!preg_match('/^\d{7}$/', $codigoMunicipioIbge)) {
            $errors[] = "Código IBGE inválido: {$codigoMunicipioIbge} (esperado 7 dígitos)";
        }
        if (!preg_match('/^[A-Z]{2}$/', $uf)) {
            $errors[] = "UF inválida: {$uf} (esperado 2 letras maiúsculas)";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Endereço inválido');
        }
    }
}
