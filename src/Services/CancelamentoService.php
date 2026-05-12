<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Dps\EventoBuilder;
use PhpNfseNacional\Dps\EventoCancelamento;
use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\Exceptions\SefinException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Sefin\SefinResposta;

/**
 * Cancelamento de NFS-e via evento e101101.
 *
 * Atalho prático sobre `EventoBuilder` — internamente constrói um
 * `EventoCancelamento` (que implementa `EventoNfse`) e dispara o evento.
 * Pra outros tipos de evento, use o `EventoBuilder` diretamente.
 *
 * Códigos de retorno esperados:
 *   - cStat=135 → Evento registrado e vinculado a NFS-e (OK)
 *   - cStat=155 → Cancelamento homologado por prefeitura
 *   - cStat=101 → NFS-e cancelada (consulta posterior reporta isso)
 *   - Outros   → SefinException
 */
final class CancelamentoService
{
    private LoggerInterface $logger;

    public function __construct(
        private readonly EventoBuilder $builder,
        private readonly Signer $signer,
        private readonly SefinClient $client,
        private readonly SefinEndpoints $endpoints,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function cancelar(
        string $chaveAcesso,
        MotivoCancelamento $motivo,
        string $justificativa,
    ): SefinResposta {
        $evento = new EventoCancelamento(
            chaveAcesso: $chaveAcesso,
            motivo: $motivo,
            justificativa: $justificativa,
        );

        $this->logger->info('[CancelamentoService] Iniciando cancelamento', [
            'chave' => $evento->chaveAcesso,
            'motivo' => $motivo->label(),
        ]);

        // 1. Monta XML do evento genérico
        $xmlCru = $this->builder->build($evento);

        // 2. Assina (rsa-sha1 sobre <infPedReg>)
        $xmlAssinado = $this->signer->sign($xmlCru, 'infPedReg');

        // 3. POST no endpoint de eventos
        $url = $this->endpoints->cancelarNfse($evento->chaveAcesso);
        $resposta = $this->client->postXml($url, $xmlAssinado);

        if (!$resposta->cancelada()) {
            $this->logger->error('[CancelamentoService] SEFIN rejeitou cancelamento', [
                'chave' => $evento->chaveAcesso,
                'cStat' => $resposta->cStat,
                'xMotivo' => $resposta->xMotivo,
            ]);
            throw new SefinException(
                cStat: $resposta->cStat,
                xMotivo: $resposta->xMotivo,
                rawResponse: $resposta->rawResponse,
            );
        }

        $this->logger->info('[CancelamentoService] Cancelamento confirmado', [
            'chave' => $evento->chaveAcesso,
            'cStat' => $resposta->cStat,
        ]);
        return $resposta;
    }
}
