<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

use PhpNfseNacional\Enums\Ambiente;

/**
 * Endpoints REST do Portal Nacional SEFIN.
 *
 * URLs oficiais publicadas pela SE/CGNFS-e. Caso o portal mude as URLs,
 * basta atualizar essas constantes (não precisa mexer em SefinClient).
 */
final class SefinEndpoints
{
    private const URL_PRODUCAO = 'https://sefin.nfse.gov.br';
    private const URL_HOMOLOGACAO = 'https://sefin.producaorestrita.nfse.gov.br';

    public function __construct(
        public readonly Ambiente $ambiente,
    ) {}

    public function baseUrl(): string
    {
        return match ($this->ambiente) {
            Ambiente::Producao => self::URL_PRODUCAO,
            Ambiente::Homologacao => self::URL_HOMOLOGACAO,
        };
    }

    public function enviarDps(): string
    {
        return $this->baseUrl() . '/SefinNacional/dps';
    }

    public function consultarNfsePorChave(string $chave): string
    {
        return $this->baseUrl() . '/SefinNacional/nfse/' . $chave;
    }

    public function consultarDpsPorChave(string $chave): string
    {
        return $this->baseUrl() . '/SefinNacional/dps/' . $chave;
    }

    public function consultarEventos(string $chave, ?string $tipoEvento = null, ?int $nSequencial = null): string
    {
        $url = $this->baseUrl() . '/SefinNacional/nfse/' . $chave . '/eventos';
        if ($tipoEvento !== null) {
            $url .= '/' . $tipoEvento;
            if ($nSequencial !== null) {
                $url .= '/' . $nSequencial;
            }
        }
        return $url;
    }

    public function cancelarNfse(string $chave): string
    {
        return $this->baseUrl() . '/SefinNacional/nfse/' . $chave . '/eventos/cancelamento';
    }

    public function downloadDanfse(string $chave): string
    {
        return $this->baseUrl() . '/SefinNacional/nfse/' . $chave . '/danfse';
    }
}
