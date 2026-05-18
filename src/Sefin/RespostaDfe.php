<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

/**
 * Resposta da sincronização DFe — lista de itens agregada de várias
 * páginas (o ADN devolve em lotes de até 50 por NSU).
 *
 * O caller deve persistir `ultimoNsu` pra usar na próxima chamada
 * (sincronização incremental — só pega DFes novos desde então).
 *
 * Métodos derivados (`chavesCanceladas`, `statusPorChave`, etc.) são
 * lazy-computed sobre o lote atual e fazem filtros case-insensitive
 * de `tipoEvento` por substring (`CANCELAMENTO`, `CONFIRMACAO`,
 * `REJEICAO`, `SUBSTITUICAO`) pra cobrir variações como
 * `CONFIRMACAO_PRESTADOR`, `CONFIRMACAO_TOMADOR`, etc.
 */
final class RespostaDfe
{
    /** Status agregado retornado por `statusPorChave()`. */
    public const STATUS_EMITIDA = 'EMITIDA';
    public const STATUS_CANCELADA = 'CANCELADA';
    public const STATUS_SUBSTITUIDA = 'SUBSTITUIDA';
    public const STATUS_CONFIRMADA = 'CONFIRMADA';
    public const STATUS_REJEITADA = 'REJEITADA';

    /**
     * @param array<int, ItemDfe> $itens
     */
    public function __construct(
        public readonly array $itens,
        /** Maior NSU consumido nesta chamada — usar na próxima sincronização. */
        public readonly int $ultimoNsu,
        /** Status devolvido pelo SEFIN (e.g. "ProcessamentoNormal", "NenhumDocumentoLocalizado"). */
        public readonly ?string $statusProcessamento,
        /** Indica se há mais DFes pendentes (paginação não esgotada). */
        public readonly bool $temMais,
    ) {}

    public function vazio(): bool
    {
        return $this->itens === [];
    }

    public function quantidade(): int
    {
        return count($this->itens);
    }

    // ──────── Filtros de itens ────────

    /**
     * Itens cujo `tipoDocumento === "NFSE"` (NFS-es originais, não eventos).
     *
     * @return array<int, ItemDfe>
     */
    public function itensNfse(): array
    {
        return array_values(array_filter(
            $this->itens,
            static fn (ItemDfe $i): bool => $i->tipoDocumento === 'NFSE',
        ));
    }

    /**
     * Itens cujo `tipoDocumento === "EVENTO"` (cancelamento, manifestações, substituição).
     *
     * @return array<int, ItemDfe>
     */
    public function itensEvento(): array
    {
        return array_values(array_filter(
            $this->itens,
            static fn (ItemDfe $i): bool => $i->tipoDocumento === 'EVENTO',
        ));
    }

    // ──────── Listas de chaves por status ────────

    /**
     * Chaves de NFS-es que receberam evento de **CANCELAMENTO** neste lote.
     *
     * @return array<int, string> Chaves únicas, mesma ordem da primeira aparição.
     */
    public function chavesCanceladas(): array
    {
        return $this->chavesComEvento('CANCELAMENTO');
    }

    /**
     * Chaves de NFS-es que receberam evento de **SUBSTITUICAO** (a original
     * fica invalidada — a substituidora tem outra chave).
     *
     * @return array<int, string>
     */
    public function chavesSubstituidas(): array
    {
        return $this->chavesComEvento('SUBSTITUICAO');
    }

    /**
     * Chaves de NFS-es com algum evento de **CONFIRMACAO** (de prestador,
     * tomador ou intermediário). Não invalida a NFS-e, só manifesta aceite.
     *
     * @return array<int, string>
     */
    public function chavesConfirmadas(): array
    {
        return $this->chavesComEvento('CONFIRMACAO');
    }

    /**
     * Chaves de NFS-es com algum evento de **REJEICAO** (de prestador,
     * tomador ou intermediário).
     *
     * @return array<int, string>
     */
    public function chavesRejeitadas(): array
    {
        return $this->chavesComEvento('REJEICAO');
    }

    // ──────── Lookup por chave ────────

    /**
     * True se a chave dada tem evento de CANCELAMENTO ou SUBSTITUICAO neste lote.
     */
    public function foiCancelada(string $chave): bool
    {
        foreach ($this->eventosDaChave($chave) as $ev) {
            if ($this->eventoContem($ev->tipoEvento, 'CANCELAMENTO')
                || $this->eventoContem($ev->tipoEvento, 'SUBSTITUICAO')
            ) {
                return true;
            }
        }
        return false;
    }

    /**
     * Todos os itens `tipoDocumento=EVENTO` cuja `chaveAcesso` bate com a
     * chave fornecida — útil pra reconstruir a timeline de uma NFS-e.
     *
     * @return array<int, ItemDfe>
     */
    public function eventosDaChave(string $chave): array
    {
        return array_values(array_filter(
            $this->itens,
            static fn (ItemDfe $i): bool => $i->tipoDocumento === 'EVENTO'
                && $i->chaveAcesso === $chave,
        ));
    }

    /**
     * Status agregado da chave, considerando todos os eventos do lote.
     * Hierarquia (mais "forte" primeiro):
     *
     *   SUBSTITUIDA → CANCELADA → REJEITADA → CONFIRMADA → EMITIDA
     *
     * Retorna null se a chave não aparece em nenhum item deste lote.
     */
    public function statusPorChave(string $chave): ?string
    {
        $apareceu = false;
        $tipos = [];
        foreach ($this->itens as $i) {
            if ($i->chaveAcesso !== $chave) {
                continue;
            }
            $apareceu = true;
            if ($i->tipoDocumento === 'EVENTO' && $i->tipoEvento !== null) {
                $tipos[] = $i->tipoEvento;
            }
        }
        if (!$apareceu) {
            return null;
        }
        foreach ($tipos as $t) {
            if ($this->eventoContem($t, 'SUBSTITUICAO')) {
                return self::STATUS_SUBSTITUIDA;
            }
        }
        foreach ($tipos as $t) {
            if ($this->eventoContem($t, 'CANCELAMENTO')) {
                return self::STATUS_CANCELADA;
            }
        }
        foreach ($tipos as $t) {
            if ($this->eventoContem($t, 'REJEICAO')) {
                return self::STATUS_REJEITADA;
            }
        }
        foreach ($tipos as $t) {
            if ($this->eventoContem($t, 'CONFIRMACAO')) {
                return self::STATUS_CONFIRMADA;
            }
        }
        return self::STATUS_EMITIDA;
    }

    // ──────── Agregação ────────

    /**
     * Map `chaveAcesso => array<int, tipoEvento>` com todos os eventos
     * vinculados a cada NFS-e neste lote. Chaves sem evento (só NFSE)
     * aparecem com array vazio.
     *
     * @return array<string, array<int, string>>
     */
    public function agruparPorChave(): array
    {
        $grupos = [];
        foreach ($this->itens as $i) {
            if ($i->chaveAcesso === null) {
                continue;
            }
            if (!array_key_exists($i->chaveAcesso, $grupos)) {
                $grupos[$i->chaveAcesso] = [];
            }
            if ($i->tipoDocumento === 'EVENTO' && $i->tipoEvento !== null) {
                $grupos[$i->chaveAcesso][] = $i->tipoEvento;
            }
        }
        return $grupos;
    }

    /**
     * @return array<int, string>
     */
    private function chavesComEvento(string $sufixo): array
    {
        $chaves = [];
        foreach ($this->itens as $i) {
            if ($i->tipoDocumento !== 'EVENTO' || $i->chaveAcesso === null) {
                continue;
            }
            if ($this->eventoContem($i->tipoEvento, $sufixo)) {
                if (!in_array($i->chaveAcesso, $chaves, true)) {
                    $chaves[] = $i->chaveAcesso;
                }
            }
        }
        return $chaves;
    }

    private function eventoContem(?string $tipoEvento, string $needle): bool
    {
        if ($tipoEvento === null) {
            return false;
        }
        return str_contains(strtoupper($tipoEvento), strtoupper($needle));
    }
}
