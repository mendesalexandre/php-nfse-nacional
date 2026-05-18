<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Sefin\ItemDfe;
use PhpNfseNacional\Sefin\RespostaDfe;
use PhpNfseNacional\Sefin\SefinClient;

/**
 * Sincronização de DFe (Distribuição de Documentos Fiscais Eletrônicos).
 *
 * O SEFIN mantém uma "caixa postal" por CNPJ onde guarda eventos
 * vinculados — NFS-es emitidas contra o CNPJ, cancelamentos, etc.
 *
 * O caller persiste o último NSU consumido e usa na próxima chamada
 * para fazer sincronização incremental.
 *
 * Wire format do ADN:
 *   GET /contribuintes/DFe/{NSU}?cnpjConsulta={CNPJ}&lote=true
 *
 * Resposta JSON tem `StatusProcessamento` + `LoteDFe[]`. O loop
 * incrementa o NSU pra cada item recebido até receber lote vazio.
 */
final class DfeService
{
    /** Status devolvidos pelo SEFIN no campo `StatusProcessamento`. */
    private const STATUS_FIM = [
        'NenhumDocumentoLocalizado',
        'Rejeicao',
    ];

    public function __construct(
        private readonly SefinClient $client,
        private readonly Certificate $certificate,
    ) {}

    /**
     * Sincroniza DFes a partir do último NSU conhecido.
     *
     * Itera até esgotar lotes (status final ou lote vazio) OU atingir
     * `$maxPaginas`. Default 20 páginas × 50 itens = até 1000 itens
     * por chamada — suficiente pra polling diário.
     */
    public function sincronizar(int $ultimoNsu = 0, int $maxPaginas = 20, bool $lote = true): RespostaDfe
    {
        if ($ultimoNsu < 0) {
            throw new ValidationException(['NSU não pode ser negativo'], 'DFe');
        }
        if ($maxPaginas < 1) {
            throw new ValidationException(['maxPaginas deve ser >= 1'], 'DFe');
        }

        $itens = [];
        $nsuAtual = $ultimoNsu;
        $status = null;
        $temMais = true;

        for ($pag = 0; $pag < $maxPaginas; $pag++) {
            $body = $this->client->sincronizarDfe($nsuAtual, $this->certificate->cnpj, $lote);
            $json = @json_decode($body, true);

            if (!is_array($json)) {
                // Body vazio ou inválido — assume fim
                $temMais = false;
                break;
            }

            $status = (string) ($json['StatusProcessamento'] ?? $json['statusProcessamento'] ?? '');
            $loteRaw = $json['LoteDFe'] ?? $json['loteDFe'] ?? null;

            if (!is_array($loteRaw) || $loteRaw === []) {
                $temMais = false;
                break;
            }

            foreach ($loteRaw as $itemRaw) {
                if (!is_array($itemRaw)) {
                    continue;
                }
                $item = $this->parsearItem($itemRaw);
                $itens[] = $item;
                if ($item->nsu > $nsuAtual) {
                    $nsuAtual = $item->nsu;
                }
            }

            if (in_array($status, self::STATUS_FIM, true)) {
                $temMais = false;
                break;
            }
        }

        return new RespostaDfe(
            itens: $itens,
            ultimoNsu: $nsuAtual,
            statusProcessamento: $status !== '' ? $status : null,
            temMais: $temMais && $pag >= $maxPaginas,
        );
    }

    /**
     * @param array<string, mixed> $raw
     */
    private function parsearItem(array $raw): ItemDfe
    {
        $nsuRaw = $raw['NSU'] ?? $raw['nsu'] ?? null;
        $nsu = is_numeric($nsuRaw) ? (int) $nsuRaw : 0;

        $tipoDoc = $raw['TipoDocumento'] ?? $raw['tipoDocumento'] ?? null;
        $chave = $raw['ChaveAcesso'] ?? $raw['chaveAcesso'] ?? $raw['chave'] ?? null;
        $tipoEv = $raw['TipoEvento'] ?? $raw['tipoEvento'] ?? null;
        $seqEv = $raw['SequencialEvento'] ?? $raw['sequencialEvento'] ?? $raw['nSeqEvento'] ?? null;
        $dhProc = $raw['DataHoraRegistro'] ?? $raw['dataHoraRegistro'] ?? $raw['dhProc'] ?? null;

        return new ItemDfe(
            nsu: $nsu,
            tipoDocumento: is_string($tipoDoc) ? $tipoDoc : null,
            chaveAcesso: is_string($chave) ? $chave : null,
            tipoEvento: is_string($tipoEv) ? $tipoEv : ($tipoEv !== null ? (string) $tipoEv : null),
            sequencialEvento: is_numeric($seqEv) ? (int) $seqEv : null,
            dataHora: is_string($dhProc) ? $dhProc : null,
            bruto: $raw,
        );
    }
}
