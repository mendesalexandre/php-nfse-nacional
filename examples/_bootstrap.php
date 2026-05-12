<?php

declare(strict_types=1);

/**
 * Bootstrap compartilhado dos examples.
 *
 * Carrega autoload, valida env vars do prestador, monta o NFSe facade
 * apontando pra HOMOLOGAÇÃO. Use:
 *
 *   $nfse = require __DIR__ . '/_bootstrap.php';
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\NFSe;

function envOrDie(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') {
        fwrite(STDERR, "ERRO: variável de ambiente {$name} não definida\n");
        exit(1);
    }
    return $v;
}

Signer::habilitarLegacyProviderRuntime();

$cert = Certificate::fromPfxFile(envOrDie('PFX_PATH'), envOrDie('PFX_SENHA'));
if ($cert->estaVencido()) {
    fwrite(STDERR, "ERRO: certificado vencido em {$cert->validade->format('d/m/Y')}\n");
    exit(1);
}

$prestador = new Prestador(
    cnpj: envOrDie('PRESTADOR_CNPJ'),
    inscricaoMunicipal: envOrDie('PRESTADOR_IM'),
    razaoSocial: envOrDie('PRESTADOR_RAZAO'),
    endereco: new Endereco(
        logradouro: envOrDie('PRESTADOR_LOGRADOURO'),
        numero: envOrDie('PRESTADOR_NUMERO'),
        bairro: envOrDie('PRESTADOR_BAIRRO'),
        cep: envOrDie('PRESTADOR_CEP'),
        codigoMunicipioIbge: envOrDie('PRESTADOR_CMUN'),
        uf: envOrDie('PRESTADOR_UF'),
    ),
);

$config = new Config(prestador: $prestador, ambiente: Ambiente::Homologacao);

return NFSe::create($config, $cert);
