<?php

declare(strict_types=1);

/**
 * Smoke — emite NFS-e com tribISSQN=2 (Imunidade) em HOMOLOGAÇÃO.
 *
 * Exercita Valores::$tributacaoIssqn = Imunidade + $imunidade =
 * TemplosQualquerCulto. Saída esperada: cStat=100 (homologação aceita
 * qualquer cenário sintaticamente válido) OU rejeição com cStat
 * descritivo se SEFIN cruzar a imunidade com o perfil tributário
 * do prestador (emissor não é imune).
 *
 * Uso (mesmo env do emitir-homologacao.php):
 *   OPENSSL_CONF=... PFX_PATH=... PFX_SENHA=... PRESTADOR_* \
 *     php examples/emitir-imunidade-homologacao.php
 */

require __DIR__ . '/../vendor/autoload.php';

use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\DTO\Servico;
use PhpNfseNacional\DTO\Tomador;
use PhpNfseNacional\DTO\Valores;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Enums\RegimeEspecialTributacao;
use PhpNfseNacional\Enums\TipoImunidadeIssqn;
use PhpNfseNacional\Enums\TipoTributacaoIssqn;
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
echo "Cert CN: {$cert->subjectCN}\n";
echo "Validade: {$cert->validade->format('d/m/Y')}\n\n";

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
    nome: getenv('TOMADOR_NOME') ?: 'TOMADOR TESTE IMUNIDADE',
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
    discriminacao: 'TESTE IMUNIDADE — ' . date('Y-m-d H:i:s') . ' — SDK php-nfse-nacional',
    codigoMunicipioPrestacao: getEnvOrDie('PRESTADOR_CMUN'),
);

// tribISSQN=2 (Imunidade) + tpImunidade=2 (Templos de qualquer culto).
// Em imunidade não há ISSQN a apurar; alíquota declaratória zero.
//
// IMPORTANTE: `motivoDispensaIssqn` (que emite <indTotTrib>0</indTotTrib>)
// é EXCLUSIVO para optantes do Simples Nacional (MEI/ME/EPP). Para Não
// Optante (Não Optante), o SEFIN exige <pTotTrib> mesmo em cenário de
// imunidade — rejeita com cStat=713 caso contrário. Por isso aqui passa
// pTotTrib com pTotTribMun=0 (sem ISSQN apurado).
$valores = new Valores(
    valorServicos: 100.00,
    deducoesReducoes: 0.00,
    aliquotaIssqnPercentual: 0.00, // pTotTribMun=0
    tributacaoIssqn: TipoTributacaoIssqn::Imunidade,
    imunidade: TipoImunidadeIssqn::TemplosQualquerCulto,
);

$id = new Identificacao(numeroDps: (int) (getenv('NDPS') ?: time() % 1_000_000), serie: '1');
$timestamp = date('YmdHis');

echo "Cenário: IMUNIDADE (Templos de qualquer culto)\n";
echo "  → tribISSQN=2, tpImunidade=2\n";
echo "  → vServ=100.00, sem ISSQN\n\n";

try {
    $r = $nfse->emitir(identificacao: $id, tomador: $tomador, servico: $servico, valores: $valores);
    file_put_contents("/tmp/nfse-imun-{$timestamp}.xml", $r->xmlRetorno ?? $r->rawResponse);
    echo "✓ EMITIDA — Chave: {$r->chaveAcesso} | nNFSe: {$r->numeroNfse} | cStat: {$r->cStat}\n";
    echo "  → /tmp/nfse-imun-{$timestamp}.xml\n";
} catch (\PhpNfseNacional\Exceptions\SefinException $e) {
    file_put_contents("/tmp/nfse-imun-erro-{$timestamp}.xml", $e->rawResponse ?? '');
    echo "✗ SEFIN REJEITOU — cStat: {$e->cStat} | xMotivo: {$e->xMotivo}\n";
    echo "  → /tmp/nfse-imun-erro-{$timestamp}.xml\n";
    exit(2);
} catch (\Throwable $e) {
    echo "✗ ERRO: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(4);
}
