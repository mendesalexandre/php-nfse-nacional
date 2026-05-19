<?php

declare(strict_types=1);

/**
 * Smoke — emite NFS-e com exigibilidade do ISSQN SUSPENSA por processo
 * judicial em HOMOLOGAÇÃO.
 *
 * Exercita Valores::$exigibilidadeSuspensa = ExigibilidadeSuspensa(
 *   tipo: ProcessoJudicial,
 *   numeroProcesso: '5001234-56.2026.8.11.0037',
 * ).
 *
 * Uso (mesmo env do emitir-homologacao.php):
 *   OPENSSL_CONF=... PFX_PATH=... PFX_SENHA=... PRESTADOR_* \
 *     php examples/emitir-exigsusp-homologacao.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\ExigibilidadeSuspensa;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\DTO\Servico;
use PhpNfseNacional\DTO\Tomador;
use PhpNfseNacional\DTO\Valores;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Enums\RegimeEspecialTributacao;
use PhpNfseNacional\Enums\TipoExigibilidadeSuspensa;
use PhpNfseNacional\NFSe;

function getEnvOrDie(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') {
        fwrite(STDERR, "ERRO: variável de ambiente {$name} não definida\n");
        exit(1);
    }
    return $v;
}

Signer::habilitarLegacyProviderRuntime();

$cert = Certificate::fromPfxFile(getEnvOrDie('PFX_PATH'), getEnvOrDie('PFX_SENHA'));
echo "Cert CN: {$cert->subjectCN}\n\n";

$prestador = new Prestador(
    cnpj: getEnvOrDie('PRESTADOR_CNPJ'),
    inscricaoMunicipal: getEnvOrDie('PRESTADOR_IM'),
    razaoSocial: getEnvOrDie('PRESTADOR_RAZAO'),
    endereco: new Endereco(
        logradouro: getEnvOrDie('PRESTADOR_LOGRADOURO'),
        numero: getEnvOrDie('PRESTADOR_NUMERO'),
        bairro: getEnvOrDie('PRESTADOR_BAIRRO'),
        cep: getEnvOrDie('PRESTADOR_CEP'),
        codigoMunicipioIbge: getEnvOrDie('PRESTADOR_CMUN'),
        uf: getEnvOrDie('PRESTADOR_UF'),
    ),
    regimeEspecial: RegimeEspecialTributacao::Nenhum,
    simplesNacional: \PhpNfseNacional\Enums\SituacaoSimplesNacional::NaoOptante,
);

$config = new Config(prestador: $prestador, ambiente: Ambiente::Homologacao);
$nfse = NFSe::create($config, $cert);

$tomador = new Tomador(
    documento: getenv('TOMADOR_DOC') ?: '12345678909',
    nome: getenv('TOMADOR_NOME') ?: 'TOMADOR TESTE EXIGSUSP',
    endereco: new Endereco(
        logradouro: 'Rua Teste',
        numero: '100',
        bairro: 'Centro',
        cep: getEnvOrDie('PRESTADOR_CEP'),
        codigoMunicipioIbge: getEnvOrDie('PRESTADOR_CMUN'),
        uf: getEnvOrDie('PRESTADOR_UF'),
    ),
);

$servico = new Servico(
    discriminacao: 'TESTE EXIGSUSP — ' . date('Y-m-d H:i:s') . ' — SDK php-nfse-nacional',
    codigoMunicipioPrestacao: getEnvOrDie('PRESTADOR_CMUN'),
);

// Pattern XSD oficial confirmado em docs/schemas/1.01/tiposSimples_v1.01.xsd:
//   TSNumProcExigSuspensa = [0-9]{30}  (exatamente 30 dígitos)
//
// O leiaute não documenta a semântica dos 30 dígitos. Convenção comum
// é CNJ (20 dígitos) + 10 zeros de padding. Aqui usamos um placeholder
// fictício; em produção use o número real fornecido pelo município.
$exigSusp = new ExigibilidadeSuspensa(
    tipo: TipoExigibilidadeSuspensa::ProcessoJudicial,
    numeroProcesso: getenv('NPROCESSO') ?: '500123456202681100370000000000',
);

$valores = new Valores(
    valorServicos: 100.00,
    deducoesReducoes: 20.00,
    aliquotaIssqnPercentual: 4.00,
    exigibilidadeSuspensa: $exigSusp,
);

$id = new Identificacao(numeroDps: (int) (getenv('NDPS') ?: time() % 1_000_000), serie: '1');
$timestamp = date('YmdHis');

echo "Cenário: EXIGIBILIDADE SUSPENSA (Processo Judicial)\n";
echo "  → tpSusp=1, nProcesso=5001234-56.2026.8.11.0037\n";
echo "  → vServ=100.00, vDR=20.00, alíquota declaratória 4%\n\n";

try {
    $r = $nfse->emitir(identificacao: $id, tomador: $tomador, servico: $servico, valores: $valores);
    file_put_contents("/tmp/nfse-exigsusp-{$timestamp}.xml", $r->xmlRetorno ?? $r->rawResponse);
    echo "✓ EMITIDA — Chave: {$r->chaveAcesso} | nNFSe: {$r->numeroNfse} | cStat: {$r->cStat}\n";
    echo "  → /tmp/nfse-exigsusp-{$timestamp}.xml\n";
} catch (\PhpNfseNacional\Exceptions\SefinException $e) {
    file_put_contents("/tmp/nfse-exigsusp-erro-{$timestamp}.xml", $e->rawResponse ?? '');
    echo "✗ SEFIN REJEITOU — cStat: {$e->cStat} | xMotivo: {$e->xMotivo}\n";
    echo "  → /tmp/nfse-exigsusp-erro-{$timestamp}.xml\n";
    exit(2);
} catch (\Throwable $e) {
    echo "✗ ERRO: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(4);
}
