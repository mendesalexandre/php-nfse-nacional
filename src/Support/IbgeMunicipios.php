<?php

declare(strict_types=1);

namespace PhpNfseNacional\Support;

/**
 * Lookup de municípios brasileiros pelo código IBGE (7 dígitos).
 *
 * Dados oficiais carregados de `resources/data/ibge-municipios.json`,
 * gerados a partir da API pública do IBGE
 * (https://servicodados.ibge.gov.br/api/v1/localidades/municipios).
 * 5.571 municípios — atualização periódica conforme o IBGE.
 *
 * Útil pra DANFSe — o XML autorizado da NFS-e nem sempre traz o nome
 * do município (só o código), e a NT 008 exige exibir "Município / Sigla UF"
 * em vários blocos (Tomador, Destinatário, Intermediário, etc.).
 *
 * Uso:
 *   $info = IbgeMunicipios::buscar('3550308');
 *   // ['nome' => 'São Paulo', 'uf' => 'SP']
 */
final class IbgeMunicipios
{
    /** @var array<string, array{nome: string, uf: string}>|null */
    private static ?array $cache = null;

    /**
     * @return array{nome: string, uf: string}|null
     */
    public static function buscar(?string $codigoIbge): ?array
    {
        if ($codigoIbge === null || $codigoIbge === '') {
            return null;
        }
        $codigo = preg_replace('/\D/', '', $codigoIbge) ?? '';
        if (strlen($codigo) !== 7) {
            return null;
        }
        $mapa = self::carregar();
        return $mapa[$codigo] ?? null;
    }

    /**
     * Helper conveniente — retorna "Nome - UF" ou null se não encontrar.
     */
    public static function formatar(?string $codigoIbge): ?string
    {
        $info = self::buscar($codigoIbge);
        return $info !== null ? "{$info['nome']} - {$info['uf']}" : null;
    }

    /**
     * @return array<string, array{nome: string, uf: string}>
     */
    private static function carregar(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $path = __DIR__ . '/../../resources/data/ibge-municipios.json';
        if (!file_exists($path)) {
            self::$cache = [];
            return [];
        }
        $conteudo = file_get_contents($path);
        if ($conteudo === false) {
            self::$cache = [];
            return [];
        }
        $data = json_decode($conteudo, true);
        if (!is_array($data)) {
            self::$cache = [];
            return [];
        }
        /** @var array<string, array{nome: string, uf: string}> $data */
        self::$cache = $data;
        return $data;
    }
}
