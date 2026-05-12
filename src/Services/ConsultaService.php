<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Sefin\SefinResposta;

/**
 * Consultas ao Portal Nacional:
 *   - status de uma NFS-e por chave de acesso (50 dígitos)
 *   - dados da DPS por chave
 *   - eventos vinculados a uma NFS-e (cancelamento, substituição)
 *
 * Útil pra sincronização periódica e detecção de canceladas por fora.
 */
final class ConsultaService
{
    public function __construct(
        private readonly SefinClient $client,
        private readonly SefinEndpoints $endpoints,
    ) {}

    /**
     * Status atual de uma NFS-e no SEFIN.
     */
    public function consultarNfse(string $chaveAcesso): SefinResposta
    {
        $this->validarChave($chaveAcesso);
        return $this->client->get($this->endpoints->consultarNfsePorChave($chaveAcesso));
    }

    /**
     * Status da DPS por chave (usado quando emissão saiu em transit
     * e queremos confirmar se foi processada).
     */
    public function consultarDps(string $chaveAcesso): SefinResposta
    {
        $this->validarChave($chaveAcesso);
        return $this->client->get($this->endpoints->consultarDpsPorChave($chaveAcesso));
    }

    /**
     * Eventos vinculados (cancelamento, substituição, carta de correção etc.).
     *
     * @param string $chaveAcesso Chave da NFS-e
     * @param string|null $tipoEvento Filtro: '101101' (cancelamento), null pra todos
     * @param int|null $nSequencial Filtro: sequencial específico, null pra mais recente
     */
    public function consultarEventos(
        string $chaveAcesso,
        ?string $tipoEvento = null,
        ?int $nSequencial = null,
    ): SefinResposta {
        $this->validarChave($chaveAcesso);
        return $this->client->get(
            $this->endpoints->consultarEventos($chaveAcesso, $tipoEvento, $nSequencial),
        );
    }

    private function validarChave(string $chave): void
    {
        $clean = preg_replace('/\D/', '', $chave) ?? '';
        if (strlen($clean) !== 50) {
            throw new ValidationException(
                ["Chave de acesso inválida: esperado 50 dígitos (recebeu {$clean})"],
                'Consulta NFS-e',
            );
        }
    }
}
