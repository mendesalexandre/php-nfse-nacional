<?php

declare(strict_types=1);

/**
 * Smoke test ponta-a-ponta — emite uma NFS-e em HOMOLOGAÇÃO SEFIN.
 *
 * Uso:
 *   PFX_PATH=/path/cert.pfx \
 *   PFX_SENHA='senha' \
 *   PRESTADOR_CNPJ=00179028000138 \
 *   PRESTADOR_IM=11408 \
 *   PRESTADOR_RAZAO='EMPRESA XYZ' \
 *   PRESTADOR_CMUN=5107909 \
 *   PRESTADOR_UF=MT \
 *   PRESTADOR_CEP=78550200 \
 *   PRESTADOR_LOGRADOURO='R DAS NOGUEIRAS' \
 *   PRESTADOR_NUMERO=1108 \
 *   PRESTADOR_BAIRRO='SETOR COMERCIAL' \
 *   php examples/emitir-homologacao.php
 *
 * Saídas:
 *   - XML do DPS enviado em /tmp/dps-{timestamp}.xml
 *   - Resposta SEFIN crua em /tmp/sefin-{timestamp}.xml
 *   - Status final no stdout (chave, número NFS-e, cStat, xMotivo)
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
use PhpNfseNacional\NFSe;

/* ----------------------------------------------------------------------- */
/* 1. Validar inputs                                                         */
/* ----------------------------------------------------------------------- */

function getEnvOrDie(string $name): string
{
    $v = getenv($name);
    if ($v === false || $v === '') {
        fwrite(STDERR, "ERRO: variável de ambiente {$name} não definida\n");
        exit(1);
    }
    return $v;
}

$pfxPath = getEnvOrDie('PFX_PATH');
$pfxSenha = getEnvOrDie('PFX_SENHA');

/* ----------------------------------------------------------------------- */
/* 2. Habilitar legacy provider OpenSSL (rsa-sha1)                          */
/* ----------------------------------------------------------------------- */

// Em OpenSSL 3.5+ (Fedora 43, RHEL 9) SHA1 é desabilitado por padrão.
// A DPS exige rsa-sha1 — habilita legacy provider runtime.
Signer::habilitarLegacyProviderRuntime();

/* ----------------------------------------------------------------------- */
/* 3. Carrega cert + monta Config                                            */
/* ----------------------------------------------------------------------- */

echo "Carregando certificado de {$pfxPath}...\n";
$cert = Certificate::fromPfxFile($pfxPath, $pfxSenha);
echo "  → CN: {$cert->subjectCN}\n";
echo "  → Validade: {$cert->validade->format('d/m/Y')}";
if ($cert->estaVencido()) {
    echo " [VENCIDO]\n";
    exit(1);
}
echo "\n";

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

$config = new Config(
    prestador: $prestador,
    ambiente: Ambiente::Homologacao,
    debugLogPayload: true,
    incluirIbsCbs: (bool) (getenv('INCLUIR_IBSCBS') ?: false),
);

$nfse = NFSe::create($config, $cert);

/* ----------------------------------------------------------------------- */
/* 4. Monta a NFS-e de teste                                                 */
/* ----------------------------------------------------------------------- */

$tomador = new Tomador(
    documento: getenv('TOMADOR_DOC') ?: '44208855134',
    nome: getenv('TOMADOR_NOME') ?: 'TOMADOR DE TESTE HOMOLOGACAO',
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
    discriminacao: 'TESTE DE HOMOLOGACAO — ' . date('Y-m-d H:i:s') . ' — NFS-e emitida pelo SDK php-nfse-nacional',
    codigoMunicipioPrestacao: getEnvOrDie('PRESTADOR_CMUN'),
);

$valores = new Valores(
    valorServicos: 100.00,
    deducoesReducoes: 20.00,
    aliquotaIssqnPercentual: 4.00,
);

$identificacao = new Identificacao(
    numeroDps: (int) (getenv('NDPS') ?: time() % 1_000_000),
    serie: '1',
);

/* ----------------------------------------------------------------------- */
/* 5. Emite                                                                  */
/* ----------------------------------------------------------------------- */

$timestamp = date('YmdHis');

echo "\nEmitindo NFS-e:\n";
echo "  → Ambiente: HOMOLOGAÇÃO\n";
echo "  → nDPS: {$identificacao->numeroDps}\n";
echo "  → Tomador: {$tomador->nome} ({$tomador->documento})\n";
echo "  → vServ: " . number_format($valores->valorServicos, 2) . "\n";
echo "  → vDR:   " . number_format($valores->deducoesReducoes, 2) . "\n";
echo "  → vBC:   " . number_format($valores->baseCalculo(), 2) . "\n";
echo "  → ISSQN: " . number_format($valores->valorIssqn(), 2) . "\n";
echo "\n";

try {
    $resposta = $nfse->emissao()->emitir(
        identificacao: $identificacao,
        tomador: $tomador,
        servico: $servico,
        valores: $valores,
    );

    file_put_contents("/tmp/sefin-{$timestamp}.xml", $resposta->rawResponse);
    if ($resposta->xmlRetorno !== null) {
        file_put_contents("/tmp/nfse-{$timestamp}.xml", $resposta->xmlRetorno);
    }

    echo "✓ EMITIDA COM SUCESSO\n";
    echo "  → Chave: {$resposta->chaveAcesso}\n";
    echo "  → Número NFS-e: {$resposta->numeroNfse}\n";
    echo "  → cStat: {$resposta->cStat}\n";
    echo "  → xMotivo: {$resposta->xMotivo}\n";
    echo "  → Resposta crua salva em /tmp/sefin-{$timestamp}.xml\n";
    echo "  → XML NFS-e salvo em /tmp/nfse-{$timestamp}.xml\n";

} catch (\PhpNfseNacional\Exceptions\SefinException $e) {
    echo "✗ SEFIN REJEITOU\n";
    echo "  → cStat: {$e->cStat}\n";
    echo "  → xMotivo: {$e->xMotivo}\n";
    echo "  → Mensagem: {$e->getMessage()}\n";
    if ($e->rawResponse !== null) {
        $logFile = "/tmp/sefin-erro-{$timestamp}.xml";
        file_put_contents($logFile, $e->rawResponse);
        echo "  → Resposta crua em {$logFile}\n";
    }
    exit(2);

} catch (\PhpNfseNacional\Exceptions\CertificateException $e) {
    echo "✗ ERRO DE CERTIFICADO/ASSINATURA\n";
    echo "  → {$e->getMessage()}\n";
    echo "  → Dica: verificar OPENSSL_CONF ou setar variável de ambiente\n";
    exit(3);

} catch (\Throwable $e) {
    echo "✗ ERRO INESPERADO\n";
    echo "  → " . get_class($e) . ': ' . $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(4);
}
