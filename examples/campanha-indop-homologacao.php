<?php

declare(strict_types=1);

/**
 * Campanha em HOMOLOGAÇÃO SEFIN — combinações cTribNac × NBS × cIndOp ×
 * cClassTrib tiradas da tabela oficial (`Support\IndicadorOperacao`), com
 * IBS/CBS ligado, pra conferir (1) se o SEFIN aceita o que a tabela diz que é
 * válido, (2) se rejeita o que ela diz que não é, e (3) se os valores de
 * IBS/CBS devolvidos são coerentes entre si.
 *
 * SEMPRE homologação (Ambiente fixo no script). Cada caso emite 1 NFS-e.
 *
 * Uso (mesmas variáveis do emitir-homologacao.php):
 *   OPENSSL_CONF=... PFX_PATH=... PFX_SENHA=... PRESTADOR_CNPJ=... PRESTADOR_IM=... \
 *   PRESTADOR_RAZAO=... PRESTADOR_CMUN=... PRESTADOR_UF=... PRESTADOR_CEP=... \
 *   PRESTADOR_LOGRADOURO=... PRESTADOR_NUMERO=... PRESTADOR_BAIRRO=... \
 *   php examples/campanha-indop-homologacao.php [--n=12] [--pausa=2] [--dry-run]
 *
 *   --n        nº de casos de amostra (além do controle e dos negativos)
 *   --pausa    segundos entre emissões (default 2)
 *   --dry-run  só imprime o plano, não emite
 *
 * Saída: tabela no stdout + JSONL em $OUT_DIR (default: dir temporário do SO).
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
use PhpNfseNacional\Enums\ListaServicosNacional;
use PhpNfseNacional\Enums\RegimeEspecialTributacao;
use PhpNfseNacional\Enums\SituacaoSimplesNacional;
use PhpNfseNacional\Exceptions\SefinException;
use PhpNfseNacional\NFSe;
use PhpNfseNacional\Support\IndicadorOperacao;

function env(string $n): string
{
    $v = getenv($n);
    if ($v === false || $v === '') {
        fwrite(STDERR, "ERRO: variável {$n} não definida\n");
        exit(1);
    }
    return $v;
}

$opts = getopt('', ['n::', 'pausa::', 'dry-run', 'competencia::']);
$nAmostra = (int) ($opts['n'] ?? 12);
$pausa = (int) ($opts['pausa'] ?? 2);
$dry = array_key_exists('dry-run', $opts);
$competencia = new DateTimeImmutable((string) ($opts['competencia'] ?? 'now'), new DateTimeZone('America/Sao_Paulo'));
$dia = $competencia->format('Y-m-d');

/** Primeiro cTribNac de 6 dígitos da lista nacional que começa com o prefixo (item+subitem). */
function cTribNacReal(string $prefixo): string
{
    foreach (ListaServicosNacional::cases() as $c) {
        if (str_starts_with($c->value, $prefixo)) {
            return $c->value;
        }
    }
    return $prefixo . '01';
}

/* ---------------- plano ---------------- */

$json = json_decode((string) file_get_contents(__DIR__ . '/../resources/data/indop-nbs.json'), true);
$CTRIB_PADRAO = '210101';
$NBS_PADRAO = '113040000';

$plano = [];
$plano[] = ['controle', $CTRIB_PADRAO, $NBS_PADRAO, '100301', '000001'];

// Amostra: combinações válidas na data com cIndOp=100301, uma por cClassTrib
// distinto (varia a alíquota/redução), variando o cTribNac.
$vistos = [];
foreach ($json['nbs'] as $nbs => $linhas) {
    foreach ($linhas as [$prefixo, $ind, $cls, $ini, $fim]) {
        if ($ind !== '100301' || !IndicadorOperacao::classTribAplicavelNfse($cls, $dia) || $ini > $dia || ($fim !== null && $fim < $dia) || strlen($prefixo) !== 4) {
            continue;
        }
        if (!isset($vistos[$cls]) || count($vistos[$cls]) < 2) {
            $vistos[$cls][] = [cTribNacReal($prefixo), (string) $nbs, $ind, $cls];
        }
    }
}
ksort($vistos);
$candidatos = [];
foreach ($vistos as $lista) {
    foreach ($lista as $c) {
        $candidatos[] = $c;
    }
}
// espalha: pega a cada k pra cobrir cClassTrib e cTribNac diferentes
$passo = max(1, intdiv(count($candidatos), max(1, $nAmostra)));
for ($i = 0, $k = 0; $i < count($candidatos) && $k < $nAmostra; $i += $passo, $k++) {
    $plano[] = ['amostra', $candidatos[$i][0], $candidatos[$i][1], $candidatos[$i][2], $candidatos[$i][3]];
}

// Negativos: a tabela diz que NÃO existe — vemos se o SEFIN também recusa.
$plano[] = ['neg:cIndOp', $CTRIB_PADRAO, $NBS_PADRAO, '050101', '000001'];
$plano[] = ['neg:cClassTrib', $CTRIB_PADRAO, $NBS_PADRAO, '100301', '200003'];
// Combinação que a tabela de NBS lista mas que NÃO vale pra NFS-e (SEFIN: cStat 958).
$plano[] = ['neg:naoNfse', '042301', '109101000', '100301', '011002'];

echo "Competência: {$dia} — " . count($plano) . " casos\n";
foreach ($plano as $i => [$tipo, $ct, $nbs, $ind, $cls]) {
    $ok = IndicadorOperacao::valida($nbs, $ind, $cls, $ct, $dia) ? 'tabela:OK ' : 'tabela:NÃO';
    printf("  %2d %-15s cTribNac=%s NBS=%s cIndOp=%s cClassTrib=%s  %s\n", $i + 1, $tipo, $ct, $nbs, $ind, $cls, $ok);
}
if ($dry) {
    exit(0);
}

/* ---------------- emissão (HOMOLOGAÇÃO) ---------------- */

Signer::habilitarLegacyProviderRuntime();
$cert = Certificate::fromPfxFile(env('PFX_PATH'), env('PFX_SENHA'));
$end = new Endereco(
    logradouro: env('PRESTADOR_LOGRADOURO'),
    numero: env('PRESTADOR_NUMERO'),
    bairro: env('PRESTADOR_BAIRRO'),
    cep: env('PRESTADOR_CEP'),
    codigoMunicipioIbge: env('PRESTADOR_CMUN'),
    uf: env('PRESTADOR_UF'),
);
$config = new Config(
    prestador: new Prestador(
        cnpj: env('PRESTADOR_CNPJ'),
        inscricaoMunicipal: env('PRESTADOR_IM'),
        razaoSocial: env('PRESTADOR_RAZAO'),
        endereco: $end,
        regimeEspecial: RegimeEspecialTributacao::Nenhum,
        simplesNacional: SituacaoSimplesNacional::NaoOptante,
    ),
    ambiente: Ambiente::Homologacao,
    incluirIbsCbs: true,
);
$nfse = NFSe::create($config, $cert);
$tomador = new Tomador(documento: '12345678909', nome: 'TOMADOR DE TESTE HOMOLOGACAO', endereco: $end);

$out = (getenv('OUT_DIR') ?: sys_get_temp_dir()) . '/campanha-indop-' . date('YmdHis') . '.jsonl';
$ndps = (int) (time() % 900000) + 1000;

function num(?DOMXPath $x, string $p): ?float
{
    $n = $x?->query($p)->item(0);
    return $n === null ? null : (float) $n->textContent;
}

$resultados = [];
foreach ($plano as $i => [$tipo, $ct, $nbs, $ind, $cls]) {
    $linha = ['caso' => $i + 1, 'tipo' => $tipo, 'cTribNac' => $ct, 'nbs' => $nbs, 'cIndOp' => $ind,
        'cClassTrib' => $cls, 'tabela' => IndicadorOperacao::valida($nbs, $ind, $cls, $ct, $dia)];
    try {
        $r = $nfse->emitir(
            identificacao: new Identificacao(numeroDps: $ndps + $i, serie: '1', dataCompetencia: $competencia),
            tomador: $tomador,
            servico: new Servico(
                discriminacao: "CAMPANHA CINDOP {$tipo} " . date('c'),
                codigoMunicipioPrestacao: env('PRESTADOR_CMUN'),
                cTribNac: $ct,
                cNBS: $nbs,
                cIndOp: $ind,
                tpOper: IndicadorOperacao::exigeTpOper($ct) ? '5' : null,
            ),
            valores: new Valores(
                valorServicos: 1000.00,
                deducoesReducoes: 0.0,
                aliquotaIssqnPercentual: 4.00,
                cstIbsCbs: substr($cls, 0, 3),
                cClassTrib: $cls,
            ),
        );
        $linha += ['cStat' => $r->cStat, 'xMotivo' => $r->xMotivo, 'chave' => $r->chaveAcesso];
        if ($r->xmlRetorno !== null) {
            $d = new DOMDocument();
            @$d->loadXML($r->xmlRetorno);
            $x = new DOMXPath($d);
            $x->registerNamespace('n', 'http://www.sped.fazenda.gov.br/nfse');
            $t = '//n:infNFSe/n:IBSCBS/n:totCIBS';
            $v = [
                'vLiq' => num($x, '//n:infNFSe/n:valores/n:vLiq'),
                'vBC' => num($x, '//n:infNFSe/n:IBSCBS/n:valores/n:vBC'),
                'pAliqEfetCBS' => num($x, '//n:infNFSe/n:IBSCBS/n:valores/n:fed/n:pAliqEfetCBS'),
                'vCBS' => num($x, "$t/n:gCBS/n:vCBS"),
                'vIBSTot' => num($x, "$t/n:gIBS/n:vIBSTot"),
                'vIBSUF' => num($x, "$t/n:gIBS/n:gIBSUFTot/n:vIBSUF"),
                'vIBSMun' => num($x, "$t/n:gIBS/n:gIBSMunTot/n:vIBSMun"),
                'vTotNF' => num($x, "$t/n:vTotNF"),
            ];
            $falhas = [];
            if ($v['vBC'] !== null && $v['pAliqEfetCBS'] !== null && abs($v['vCBS'] - $v['vBC'] * $v['pAliqEfetCBS'] / 100) > 0.011) {
                $falhas[] = 'vCBS!=vBC*pAliqEfetCBS';
            }
            if ($v['vIBSTot'] !== null && abs($v['vIBSTot'] - (($v['vIBSUF'] ?? 0) + ($v['vIBSMun'] ?? 0))) > 0.011) {
                $falhas[] = 'vIBSTot!=UF+Mun';
            }
            if ($v['vTotNF'] !== null && $v['vLiq'] !== null && $dia < '2027-01-01' && abs($v['vTotNF'] - $v['vLiq']) > 0.001) {
                $falhas[] = 'vTotNF!=vLiq(2026)';
            }
            $linha += ['valores' => $v, 'invariantes' => $falhas === [] ? 'ok' : $falhas];
        }
    } catch (SefinException $e) {
        $linha += ['cStat' => $e->cStat, 'xMotivo' => $e->xMotivo, 'rejeitada' => true];
    } catch (Throwable $e) {
        $linha += ['erro' => get_class($e) . ': ' . $e->getMessage()];
    }
    file_put_contents($out, json_encode($linha, JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);
    $resultados[] = $linha;
    printf(
        "%2d %-15s %s/%s/%s/%s  tabela=%s  → cStat=%s %s%s\n",
        $i + 1, $tipo, $ct, $nbs, $ind, $cls, $linha['tabela'] ? 'OK ' : 'NÃO',
        $linha['cStat'] ?? '-', mb_substr((string) ($linha['xMotivo'] ?? $linha['erro'] ?? ''), 0, 70),
        isset($linha['invariantes']) && $linha['invariantes'] !== 'ok' ? ' [INVARIANTE: ' . implode(',', $linha['invariantes']) . ']' : '',
    );
    sleep($pausa);
}
echo "\nResultados: {$out}\n";
