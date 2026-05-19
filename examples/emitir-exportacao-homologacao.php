<?php

declare(strict_types=1);

/**
 * Smoke — emite NFS-e com tribISSQN=3 (Exportação de Serviço) +
 * cPaisResult em HOMOLOGAÇÃO.
 *
 * STATUS ATUAL: SEFIN rejeita com **cStat=330** ("É obrigatório prestar
 * informações de comércio exterior para as situações de exportação de
 * serviços."). Exportação real exige o grupo `<comExt>` no `<serv>`
 * (cobertura prevista na **Onda 5** do mapa). Este script fica como
 * placeholder pra ser destravado quando `<comExt>` for implementado.
 *
 * Exercita o que já temos: Valores::$tributacaoIssqn = ExportacaoServico
 * + $codigoPaisResultado = 'US'. O `<cPaisResult>` é emitido
 * corretamente; o que falta é a contraparte `<serv/comExt>`.
 *
 * Uso (mesmo env do emitir-homologacao.php):
 *   OPENSSL_CONF=... PFX_PATH=... PFX_SENHA=... PRESTADOR_* \
 *     php examples/emitir-exportacao-homologacao.php
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
use PhpNfseNacional\DTO\ComercioExterior;
use PhpNfseNacional\Enums\EnvioMdic;
use PhpNfseNacional\Enums\MecanismoFomentoPrestador;
use PhpNfseNacional\Enums\MecanismoFomentoTomador;
use PhpNfseNacional\Enums\ModoPrestacao;
use PhpNfseNacional\Enums\MotivoDispensaIssqn;
use PhpNfseNacional\Enums\MovimentacaoTemporariaBens;
use PhpNfseNacional\Enums\TipoTributacaoIssqn;
use PhpNfseNacional\Enums\VinculoEntrePartes;
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

// Tomador estrangeiro: como ainda não temos endExt no SDK, usamos um
// CPF nacional como placeholder. A imunidade real de exportação depende
// do tomador ser no exterior — em produção precisará de endExt (Onda 4).
$tomador = new Tomador(
    documento: getenv('TOMADOR_DOC') ?: '12345678909',
    nome: getenv('TOMADOR_NOME') ?: 'TOMADOR ESTRANGEIRO DE TESTE',
    endereco: new Endereco(
        logradouro: 'Rua Teste',
        numero: '100',
        bairro: 'Centro',
        cep: getEnvOrDie('PRESTADOR_CEP'),
        codigoMunicipioIbge: getEnvOrDie('PRESTADOR_CMUN'),
        uf: getEnvOrDie('PRESTADOR_UF'),
    ),
);

// Onda 5 v0.15.0: grupo <comExt> obrigatório quando tribISSQN=3
// (caso contrário SEFIN devolve cStat=330).
$comExt = new ComercioExterior(
    modoPrestacao: ModoPrestacao::ConsumoNoExterior,
    vinculoEntrePartes: VinculoEntrePartes::SemVinculo,
    codigoMoeda: '220', // BACEN: USD=220, EUR=978, BRL=790
    valorServicoMoeda: 25.00, // 100 BRL ~ 25 USD
    mecanismoFomentoPrestador: MecanismoFomentoPrestador::Nenhum,
    mecanismoFomentoTomador: MecanismoFomentoTomador::Nenhum,
    movimentacaoTemporariaBens: MovimentacaoTemporariaBens::Nao,
    envioMdic: EnvioMdic::NaoEnviar,
);

$servico = new Servico(
    discriminacao: 'TESTE EXPORTACAO — ' . date('Y-m-d H:i:s') . ' — SDK php-nfse-nacional',
    codigoMunicipioPrestacao: getEnvOrDie('PRESTADOR_CMUN'),
    comExt: $comExt,
);

$valores = new Valores(
    valorServicos: 100.00,
    deducoesReducoes: 0.00,
    aliquotaIssqnPercentual: 0.00,
    tributacaoIssqn: TipoTributacaoIssqn::ExportacaoServico,
    codigoPaisResultado: 'US',
    // motivoDispensaIssqn omitido — emissor é Não Optante, SEFIN
    // exige <pTotTrib> mesmo em cenário de exportação (cStat=713).
);

$id = new Identificacao(numeroDps: (int) (getenv('NDPS') ?: time() % 1_000_000), serie: '1');
$timestamp = date('YmdHis');

echo "Cenário: EXPORTAÇÃO DE SERVIÇO\n";
echo "  → tribISSQN=3, cPaisResult=US\n\n";

try {
    $r = $nfse->emitir(identificacao: $id, tomador: $tomador, servico: $servico, valores: $valores);
    file_put_contents("/tmp/nfse-export-{$timestamp}.xml", $r->xmlRetorno ?? $r->rawResponse);
    echo "✓ EMITIDA — Chave: {$r->chaveAcesso} | nNFSe: {$r->numeroNfse} | cStat: {$r->cStat}\n";
    echo "  → /tmp/nfse-export-{$timestamp}.xml\n";
} catch (\PhpNfseNacional\Exceptions\SefinException $e) {
    file_put_contents("/tmp/nfse-export-erro-{$timestamp}.xml", $e->rawResponse ?? '');
    echo "✗ SEFIN REJEITOU — cStat: {$e->cStat} | xMotivo: {$e->xMotivo}\n";
    echo "  → /tmp/nfse-export-erro-{$timestamp}.xml\n";
    exit(2);
} catch (\Throwable $e) {
    echo "✗ ERRO: " . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(4);
}
