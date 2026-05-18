<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

/**
 * Resposta da sincronização DFe — lista de itens agregada de várias
 * páginas (o ADN devolve em lotes de até 50 por NSU).
 *
 * O caller deve persistir `ultimoNsu` pra usar na próxima chamada
 * (sincronização incremental — só pega DFes novos desde então).
 */
final class RespostaDfe
{
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
}
