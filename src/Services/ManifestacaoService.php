<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Dps\EventoAnulacaoRejeicao;
use PhpNfseNacional\Dps\EventoBuilder;
use PhpNfseNacional\Dps\EventoConfirmacao;
use PhpNfseNacional\Dps\EventoRejeicao;
use PhpNfseNacional\DTO\MotivoRejeicao;
use PhpNfseNacional\Enums\AutorManifestacao;
use PhpNfseNacional\Enums\CStat;
use PhpNfseNacional\Exceptions\SefinException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Sefin\SefinResposta;

/**
 * Manifestação de NFS-e — eventos pelo Prestador, Tomador ou Intermediário
 * pra confirmar ou rejeitar uma NFS-e emitida.
 *
 * Códigos de evento gerados:
 *
 *   | Operação                | Prestador | Tomador  | Intermediário |
 *   |-------------------------|-----------|----------|---------------|
 *   | Confirmação             | 202201    | 203202   | 204203        |
 *   | Rejeição                | 202205    | 203206   | 204207        |
 *   | Anulação da Rejeição    | 205208    | 205208   | 205208        |
 *
 * Restrições do leiaute (E1833):
 *   - Cada autor pode emitir UMA Confirmação OU UMA Rejeição (não ambos).
 *
 * Mesma regra de aceitação de cStat dos demais eventos:
 *   - {100, 135, 155} → evento aceito pelo SEFIN/ADN
 *   - 840             → idempotente (evento já vinculado previamente)
 *   - demais          → SefinException
 */
final class ManifestacaoService
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

    /**
     * Confirma uma NFS-e (manifestação positiva).
     *
     * O `autor` define qual código de evento é gerado (202201/202/203 conforme
     * Prestador/Tomador/Intermediário).
     */
    public function confirmar(
        string $chaveAcesso,
        AutorManifestacao $autor,
    ): SefinResposta {
        $evento = new EventoConfirmacao(
            chaveAcesso: $chaveAcesso,
            autor: $autor,
        );

        return $this->dispararEvento($evento, [
            'operacao' => 'confirmar',
            'autor' => $autor->label(),
            'chave' => $evento->chaveAcesso,
        ]);
    }

    /**
     * Rejeita uma NFS-e (manifestação negativa).
     *
     * Quando `motivo = MotivoRejeicao::Outros`, o `xMotivo` é obrigatório
     * (15-200 chars) — caso contrário o ADN rejeita com cStat=1944/1949/1954.
     */
    public function rejeitar(
        string $chaveAcesso,
        AutorManifestacao $autor,
        MotivoRejeicao $motivo,
        string $xMotivo = '',
    ): SefinResposta {
        $evento = new EventoRejeicao(
            chaveAcesso: $chaveAcesso,
            autor: $autor,
            motivo: $motivo,
            xMotivo: $xMotivo,
        );

        return $this->dispararEvento($evento, [
            'operacao' => 'rejeitar',
            'autor' => $autor->label(),
            'motivo' => $motivo->label(),
            'chave' => $evento->chaveAcesso,
        ]);
    }

    /**
     * Anula uma rejeição registrada anteriormente.
     *
     * O `idEvManifRej` é o `Id` da Rejeição original — vem no atributo
     * `<infPedReg Id="...">` do XML de retorno daquele evento (formato
     * `PRE` + chaveAcesso(50) + tipoEvento(6) = 59 chars).
     *
     * `xMotivo` é obrigatório (15-200 chars).
     */
    public function anularRejeicao(
        string $chaveAcesso,
        string $idEvManifRej,
        string $xMotivo,
    ): SefinResposta {
        $evento = new EventoAnulacaoRejeicao(
            chaveAcesso: $chaveAcesso,
            idEvManifRej: $idEvManifRej,
            xMotivo: $xMotivo,
        );

        return $this->dispararEvento($evento, [
            'operacao' => 'anularRejeicao',
            'chave' => $evento->chaveAcesso,
            'id_rejeicao_original' => $idEvManifRej,
        ]);
    }

    /**
     * @param array<string, mixed> $logCtx
     */
    private function dispararEvento(
        \PhpNfseNacional\Dps\EventoNfse $evento,
        array $logCtx,
    ): SefinResposta {
        $this->logger->info('[ManifestacaoService] Iniciando ' . ($logCtx['operacao'] ?? 'evento'), $logCtx);

        $xmlCru = $this->builder->build($evento);
        $xmlAssinado = $this->signer->sign($xmlCru, 'infPedReg');

        $url = $this->endpoints->cancelarNfse($evento->chaveAcesso());
        $resposta = $this->client->postEvento($url, $xmlAssinado);

        $aceito = in_array($resposta->cStat, CStat::aceitosEvento(), true);

        if (!$aceito) {
            $this->logger->error('[ManifestacaoService] SEFIN/ADN rejeitou', $logCtx + [
                'cStat' => $resposta->cStat,
                'xMotivo' => $resposta->xMotivo,
            ]);
            throw new SefinException(
                cStat: $resposta->cStat,
                xMotivo: $resposta->xMotivo,
                rawResponse: $resposta->rawResponse,
            );
        }

        $this->logger->info('[ManifestacaoService] Evento confirmado', $logCtx + [
            'cStat' => $resposta->cStat,
            'idempotente' => $resposta->cStat === CStat::EventoVinculado->value,
        ]);
        return $resposta;
    }
}
