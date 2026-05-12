<?php

declare(strict_types=1);

namespace PhpNfseNacional;

use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Dps\DpsBuilder;
use PhpNfseNacional\Dps\EventoBuilder;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Services\CancelamentoService;
use PhpNfseNacional\Services\ConsultaService;
use PhpNfseNacional\Services\DanfseService;
use PhpNfseNacional\Services\DownloadService;
use PhpNfseNacional\Services\EmissaoService;

/**
 * Facade unificado do SDK.
 *
 * Entry point amigável que monta a árvore de dependências internamente.
 * Use uma das duas formas:
 *
 *   // 1. Helper que monta tudo:
 *   $nfse = NFSe::create($config, $cert);
 *   $resposta = $nfse->emissao()->emitir(...);
 *
 *   // 2. Manualmente (pra DI containers Symfony/Laravel/etc.):
 *   $signer = new Signer($cert);
 *   $endpoints = new SefinEndpoints($config->ambiente);
 *   $client = new SefinClient($config, $cert, $endpoints, $http, $logger);
 *   // ... wire services individualmente
 */
final class NFSe
{
    private function __construct(
        public readonly EmissaoService $emissao,
        public readonly ConsultaService $consulta,
        public readonly CancelamentoService $cancelamento,
        public readonly DownloadService $download,
        public readonly DanfseService $danfse,
    ) {}

    /**
     * Cria o NFSe com todas as dependências internas resolvidas.
     *
     * @param ClientInterface|null $http   Cliente PSR-18 (default: Guzzle com mTLS)
     * @param LoggerInterface|null $logger Logger PSR-3 (default: NullLogger)
     */
    public static function create(
        Config $config,
        Certificate $certificate,
        ?ClientInterface $http = null,
        ?LoggerInterface $logger = null,
    ): self {
        $logger ??= new NullLogger();
        $endpoints = new SefinEndpoints($config->ambiente);
        $client = new SefinClient($config, $certificate, $endpoints, $http, $logger);
        $signer = new Signer($certificate);

        $dpsBuilder = new DpsBuilder($config);
        $eventoBuilder = new EventoBuilder($config);

        return new self(
            emissao: new EmissaoService($config, $dpsBuilder, $signer, $client, $logger),
            consulta: new ConsultaService($client, $endpoints),
            cancelamento: new CancelamentoService(
                $eventoBuilder,
                $signer,
                $client,
                $endpoints,
                $logger,
            ),
            download: new DownloadService($client, $endpoints),
            danfse: new DanfseService(),
        );
    }

    public function emissao(): EmissaoService
    {
        return $this->emissao;
    }

    public function consulta(): ConsultaService
    {
        return $this->consulta;
    }

    public function cancelamento(): CancelamentoService
    {
        return $this->cancelamento;
    }

    public function download(): DownloadService
    {
        return $this->download;
    }

    public function danfse(): DanfseService
    {
        return $this->danfse;
    }
}
