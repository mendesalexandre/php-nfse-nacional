<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PhpNfseNacional\Certificate\Signer;
use PhpNfseNacional\Dps\EventoBuilder;
use PhpNfseNacional\Dps\EventoSubstituicao;
use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\Enums\CStat;
use PhpNfseNacional\Exceptions\SefinException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Sefin\SefinResposta;

/**
 * Cancelamento por substituição de NFS-e (evento e105102).
 *
 * Diferença pro CancelamentoService: além de cancelar a NFS-e original,
 * vincula a uma NFS-e substituidora (já emitida normalmente em separado).
 *
 * Fluxo típico:
 *   1. Emita a substituidora normalmente (`$nfse->emissao()->emitir(...)`)
 *      → guarde a chave de acesso retornada
 *   2. Chame `$nfse->substituicao()->substituir($chaveOriginal,
 *      $chaveSubstituta, ...)` pra cancelar a antiga e registrar o vínculo
 *
 * Aceitação de cStat segue o mesmo padrão do cancelamento:
 *   - {100, 135, 155} → evento aceito pelo SEFIN
 *   - 840             → cancelamento já registrado previamente (idempotente)
 *   - demais          → SefinException
 */
final class SubstituicaoService
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

    public function substituir(
        string $chaveOriginal,
        string $chaveSubstituta,
        MotivoCancelamento $motivo,
        string $justificativa,
    ): SefinResposta {
        $evento = new EventoSubstituicao(
            chaveAcesso: $chaveOriginal,
            chaveSubstituta: $chaveSubstituta,
            motivo: $motivo,
            justificativa: $justificativa,
        );

        $this->logger->info('[SubstituicaoService] Iniciando substituição', [
            'chave_original' => $evento->chaveAcesso,
            'chave_substituta' => $evento->chaveSubstituta,
            'motivo' => $motivo->label(),
        ]);

        $xmlCru = $this->builder->build($evento);
        $xmlAssinado = $this->signer->sign($xmlCru, 'infPedReg');

        $url = $this->endpoints->cancelarNfse($evento->chaveAcesso);
        $resposta = $this->client->postEvento($url, $xmlAssinado);

        $aceito = in_array($resposta->cStat, CStat::aceitosEvento(), true);

        if (!$aceito) {
            $this->logger->error('[SubstituicaoService] SEFIN rejeitou substituição', [
                'chave_original' => $evento->chaveAcesso,
                'cStat' => $resposta->cStat,
                'xMotivo' => $resposta->xMotivo,
            ]);
            throw new SefinException(
                cStat: $resposta->cStat,
                xMotivo: $resposta->xMotivo,
                rawResponse: $resposta->rawResponse,
            );
        }

        $this->logger->info('[SubstituicaoService] Substituição confirmada', [
            'chave_original' => $evento->chaveAcesso,
            'chave_substituta' => $evento->chaveSubstituta,
            'cStat' => $resposta->cStat,
            'ja_existia' => $resposta->cStat === CStat::EventoVinculado->value,
        ]);
        return $resposta;
    }
}
