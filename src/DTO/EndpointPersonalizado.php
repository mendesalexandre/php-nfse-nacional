<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Override das URLs base do SEFIN Nacional pra municípios que seguem o
 * leiaute nacional, mas hospedam a própria infraestrutura (IP próprio,
 * subdomínio da prefeitura, etc.) em vez de `sefin.nfse.gov.br` /
 * `sefin.producaorestrita.nfse.gov.br`.
 *
 * Não é um catálogo de municípios conhecidos (esse SDK não mantém uma
 * lista curada disso) — quem sabe que o município do prestador precisa
 * de URL própria passa aqui explicitamente via `Config::$endpointPersonalizado`.
 *
 * Só afeta as chamadas SEFIN (emissão, consulta, eventos) — ADN
 * (download de DANFSe oficial, sincronização de DFe) continua nos
 * domínios nacionais (`adn.nfse.gov.br`), infraestrutura compartilhada
 * não replicada pelos municípios.
 */
final class EndpointPersonalizado
{
    public function __construct(
        public readonly string $producao,
        public readonly string $homologacao,
    ) {
        $errors = [];

        if (trim($producao) === '') {
            $errors[] = 'URL de produção não pode ser vazia';
        } elseif (!filter_var($producao, FILTER_VALIDATE_URL)) {
            $errors[] = "URL de produção inválida: '{$producao}'";
        }

        if (trim($homologacao) === '') {
            $errors[] = 'URL de homologação não pode ser vazia';
        } elseif (!filter_var($homologacao, FILTER_VALIDATE_URL)) {
            $errors[] = "URL de homologação inválida: '{$homologacao}'";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'EndpointPersonalizado inválido');
        }
    }
}
