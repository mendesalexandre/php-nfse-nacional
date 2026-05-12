<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

use PhpNfseNacional\Enums\Ambiente;

/**
 * Endpoints REST do Portal Nacional SEFIN.
 *
 * URLs oficiais publicadas pela SE/CGNFS-e. Caso o portal mude as URLs,
 * basta atualizar essas constantes (não precisa mexer em SefinClient).
 *
 * Atenção: o path do ambiente difere (`/SefinNacional` em homologação,
 * `/sefinnacional` minúsculo em produção).
 */
final class SefinEndpoints
{
    private const URL_PRODUCAO = 'https://sefin.nfse.gov.br/sefinnacional';
    private const URL_HOMOLOGACAO = 'https://sefin.producaorestrita.nfse.gov.br/SefinNacional';

    // ADN (Ambiente Digital Nacional) — usado pra download de DANFSE.
    private const URL_ADN_PRODUCAO = 'https://adn.nfse.gov.br';
    private const URL_ADN_HOMOLOGACAO = 'https://adn.producaorestrita.nfse.gov.br';

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

    public function adnBaseUrl(): string
    {
        return match ($this->ambiente) {
            Ambiente::Producao => self::URL_ADN_PRODUCAO,
            Ambiente::Homologacao => self::URL_ADN_HOMOLOGACAO,
        };
    }

    public function enviarDps(): string
    {
        return $this->baseUrl() . '/nfse';
    }

    public function consultarNfsePorChave(string $chave): string
    {
        return $this->baseUrl() . '/nfse/' . $chave;
    }

    public function consultarDpsPorChave(string $chave): string
    {
        return $this->baseUrl() . '/dps/' . $chave;
    }

    public function consultarEventos(string $chave, ?string $tipoEvento = null, ?int $nSequencial = null): string
    {
        $url = $this->baseUrl() . '/nfse/' . $chave . '/eventos';
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
        return $this->baseUrl() . '/nfse/' . $chave . '/eventos';
    }

    public function downloadDanfse(string $chave): string
    {
        return $this->adnBaseUrl() . '/danfse/' . $chave;
    }
}
