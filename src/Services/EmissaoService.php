<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Config;
use PhpNfseNacional\Dps\DpsBuilder;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\Servico;
use PhpNfseNacional\DTO\Tomador;
use PhpNfseNacional\DTO\Valores;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinResposta;
use PhpNfseNacional\Exceptions\SefinException;

/**
 * Orquestrador da emissão de NFS-e.
 *
 * Fluxo:
 *   1. DpsBuilder monta XML cru
 *   2. Signer assina (xmldsig + rsa-sha1)
 *   3. SefinClient envia + parseia resposta
 *   4. Retorna SefinResposta com chave/status/protocolo
 *
 * Exceções:
 *   - ValidationException (pré-envio: DTOs inválidos)
 *   - CertificateException (cert inválido, OPENSSL_CONF, assinatura falha)
 *   - SefinException (portal retornou erro ou cStat de rejeição)
 */
final class EmissaoService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly Config $config,
        private readonly DpsBuilder $builder,
        private readonly Signer $signer,
        private readonly SefinClient $client,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function emitir(
        Identificacao $identificacao,
        Tomador $tomador,
        Servico $servico,
        Valores $valores,
    ): SefinResposta {
        $this->logger->info('[EmissaoService] Iniciando emissão', [
            'ambiente' => $this->config->ambiente->label(),
            'nDPS' => $identificacao->numeroDps,
            'tomador' => $tomador->documento,
            'valor_servicos' => $valores->valorServicos,
        ]);

        // 1. Monta XML do DPS
        $xmlCru = $this->builder->build($identificacao, $tomador, $servico, $valores);

        // 2. Assina (xmldsig + rsa-sha1)
        $xmlAssinado = $this->signer->sign($xmlCru, 'infDPS');

        // 3. Envia ao Portal
        $resposta = $this->client->enviarDps($xmlAssinado);

        // 4. Valida resposta
        if ($resposta->erro()) {
            $this->logger->error('[EmissaoService] SEFIN rejeitou DPS', [
                'cStat' => $resposta->cStat,
                'xMotivo' => $resposta->xMotivo,
            ]);
            throw new SefinException(
                cStat: $resposta->cStat,
                xMotivo: $resposta->xMotivo,
                rawResponse: $resposta->rawResponse,
            );
        }

        $this->logger->info('[EmissaoService] NFS-e emitida', [
            'chave' => $resposta->chaveAcesso,
            'numero' => $resposta->numeroNfse,
            'cStat' => $resposta->cStat,
        ]);

        return $resposta;
    }
}
