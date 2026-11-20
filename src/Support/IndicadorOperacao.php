<?php

declare(strict_types=1);

namespace PhpNfseNacional\Support;

use DateTimeInterface;

/**
 * Tabela oficial NBS → cIndOp (indicador de operação do IBS/CBS) × cClassTrib.
 *
 * Dados de `resources/data/indop-nbs.json`, gerados por
 * `tools/gerar-indop-nbs.py` a partir das migrações Flyway do repositório
 * `ReceitaFederal/calculadora-rtc-regime-regular` (MIT) — a mesma tabela
 * (`CLASSIF_NBS_INDOP_LC`) que a calculadora oficial usa pra validar a
 * combinação **cTribNac × NBS × cIndOp × cClassTrib**, com vigência.
 *
 * A tabela MUDA com a data: as combinações de 2026 valem até 2026-11-30 e
 * uma tabela nova vale a partir de 2026-12-01. Por isso toda consulta recebe
 * a data do fato gerador (use a competência da DPS).
 *
 * Princípio da lib (escopo = sintaxe, não regra fiscal): o `DpsBuilder` NÃO
 * consulta esta tabela. Ela existe pra quem monta o DPS escolher/validar
 * `cIndOp` e `cClassTrib` antes de enviar; a decisão final é do SEFIN.
 *
 * Uso:
 *   $d = new DateTimeImmutable('2026-12-15');
 *   IndicadorOperacao::indicadores('113040000', $d, '210101'); // ['100301']
 *   IndicadorOperacao::sugerido('113040000', $d, '210101');    // '100301'
 *   IndicadorOperacao::valida('113040000', '100301', '000001', '210101', $d); // true
 */
final class IndicadorOperacao
{
    /** @var array{versaoBase: string, geradoEm: string, indop: array<string, array{d: string, l: string, i: string, f: string|null}>, nbs: array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: int, 6: int}>>}|null */
    private static ?array $cache = null;

    /**
     * Combinações válidas na data pra um NBS (opcionalmente restritas ao
     * serviço `cTribNac`).
     *
     * @return list<array{cIndOp: string, cClassTrib: string, psOnerosa: bool, adqExterior: bool}>
     */
    public static function combinacoes(string $nbs, DateTimeInterface|string $data, ?string $cTribNac = null): array
    {
        $linhas = self::carregar()['nbs'][self::soDigitos($nbs)] ?? [];
        $dia = self::dia($data);
        $trib = $cTribNac !== null ? self::soDigitos($cTribNac) : null;

        $saida = [];
        foreach ($linhas as [$prefixo, $cIndOp, $cClassTrib, $inicio, $fim, $ps, $adq]) {
            if ($inicio > $dia || ($fim !== null && $fim < $dia)) {
                continue;
            }
            if ($trib !== null && !str_starts_with($trib, $prefixo)) {
                continue;
            }
            $saida[] = [
                'cIndOp' => $cIndOp,
                'cClassTrib' => $cClassTrib,
                'psOnerosa' => $ps === 1,
                'adqExterior' => $adq === 1,
            ];
        }
        return $saida;
    }

    /**
     * cIndOp distintos aplicáveis ao NBS na data (ordenados).
     *
     * @return list<string>
     */
    public static function indicadores(string $nbs, DateTimeInterface|string $data, ?string $cTribNac = null): array
    {
        $codigos = array_values(array_unique(array_column(self::combinacoes($nbs, $data, $cTribNac), 'cIndOp')));
        sort($codigos);
        return $codigos;
    }

    /**
     * O cIndOp, quando a tabela admite exatamente um pro NBS na data;
     * `null` se não há nenhum ou se é ambíguo (aí o emissor decide pelo caso
     * concreto — ver {@see indicadores()} e {@see descricao()}).
     */
    public static function sugerido(string $nbs, DateTimeInterface|string $data, ?string $cTribNac = null): ?string
    {
        $codigos = self::indicadores($nbs, $data, $cTribNac);
        return count($codigos) === 1 ? $codigos[0] : null;
    }

    /**
     * A combinação NBS × cIndOp × cClassTrib (× cTribNac, se informado) existe
     * e está vigente na data? Equivale a `validarCombinacaoExiste` da
     * calculadora oficial.
     */
    public static function valida(
        string $nbs,
        string $cIndOp,
        string $cClassTrib,
        ?string $cTribNac,
        DateTimeInterface|string $data,
    ): bool {
        foreach (self::combinacoes($nbs, $data, $cTribNac) as $c) {
            if ($c['cIndOp'] === $cIndOp && $c['cClassTrib'] === $cClassTrib) {
                return true;
            }
        }
        return false;
    }

    /**
     * Descrição e local de incidência de um cIndOp (Anexo VII).
     *
     * @return array{descricao: string, local: string}|null
     */
    public static function descricao(string $cIndOp): ?array
    {
        $i = self::carregar()['indop'][$cIndOp] ?? null;
        return $i === null ? null : ['descricao' => $i['d'], 'local' => $i['l']];
    }

    /**
     * Procedência dos dados embarcados.
     *
     * @return array{versaoBase: string, geradoEm: string}
     */
    public static function baseDados(): array
    {
        $c = self::carregar();
        return ['versaoBase' => $c['versaoBase'], 'geradoEm' => $c['geradoEm']];
    }

    private static function dia(DateTimeInterface|string $data): string
    {
        return $data instanceof DateTimeInterface ? $data->format('Y-m-d') : substr($data, 0, 10);
    }

    private static function soDigitos(string $v): string
    {
        return preg_replace('/\D/', '', $v) ?? '';
    }

    /**
     * @return array{versaoBase: string, geradoEm: string, indop: array<string, array{d: string, l: string, i: string, f: string|null}>, nbs: array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: int, 6: int}>>}
     */
    private static function carregar(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $vazio = ['versaoBase' => '', 'geradoEm' => '', 'indop' => [], 'nbs' => []];
        $conteudo = @file_get_contents(__DIR__ . '/../../resources/data/indop-nbs.json');
        $data = $conteudo === false ? null : json_decode($conteudo, true);
        if (!is_array($data) || !isset($data['nbs'], $data['indop'])) {
            return self::$cache = $vazio;
        }
        /** @var array{versaoBase: string, geradoEm: string, indop: array<string, array{d: string, l: string, i: string, f: string|null}>, nbs: array<string, list<array{0: string, 1: string, 2: string, 3: string, 4: string|null, 5: int, 6: int}>>} $data */
        return self::$cache = $data;
    }
}
