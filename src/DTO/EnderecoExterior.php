<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Endereço no exterior — alternativa ao `Endereco` (nacional) quando o
 * tomador, intermediário, prestador, obra ou evento está fora do Brasil.
 *
 * Mapeia para o grupo `<endExt>` dentro de `<end>` (XSD V1.01 TCEnderExt,
 * linhas 1191-1213). O `<end>` é único wrapper com choice
 * `<endNac>` ou `<endExt>` — o `DpsBuilder` detecta o tipo do DTO
 * (`Endereco` vs `EnderecoExterior`) e emite o nó correto.
 *
 * Campos comuns ao endereço (logradouro, numero, complemento, bairro)
 * ficam aqui também — mesmo modelo do `Endereco` nacional, com a
 * diferença de país/cidade/região substituindo município IBGE/UF/CEP
 * brasileiros.
 */
final class EnderecoExterior
{
    public function __construct(
        public readonly string $logradouro,
        public readonly string $numero,
        public readonly string $bairro,
        /** Código ISO do país (2 letras maiúsculas). XSD `TSCodPaisISO` = `[A-Z]{2}`. */
        public readonly string $codigoPaisIso,
        /** Código de endereçamento postal estrangeiro (1-11 chars alfanuméricos). */
        public readonly string $codigoEnderecamentoPostal,
        /** Nome da cidade. */
        public readonly string $cidade,
        /** Estado, província ou região. */
        public readonly string $estadoProvinciaRegiao,
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
        if (!preg_match('/^[A-Z]{2}$/', $codigoPaisIso)) {
            $errors[] = "codigoPaisIso inválido: '{$codigoPaisIso}' (esperado 2 letras maiúsculas — ex: 'US', 'PT', 'DE')";
        }
        $cep = trim($codigoEnderecamentoPostal);
        if ($cep === '' || mb_strlen($cep) > 11) {
            $errors[] = "codigoEnderecamentoPostal inválido (esperado 1-11 chars, recebeu '{$codigoEnderecamentoPostal}')";
        }
        if (trim($cidade) === '') {
            $errors[] = 'cidade vazia';
        }
        if (trim($estadoProvinciaRegiao) === '') {
            $errors[] = 'estadoProvinciaRegiao vazio';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'EnderecoExterior inválido');
        }
    }
}
