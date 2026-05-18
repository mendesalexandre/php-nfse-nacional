<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;
use PhpNfseNacional\Sefin\SefinResposta;

/**
 * Download de NFS-e e DANFSE diretamente do Portal Nacional.
 *
 * Útil pra:
 *   - Pipeline legado (NFS-e emitida fora do SDK que precisa do PDF)
 *   - Re-download de DANFSE após cancelamento (vem com tarja)
 *   - Backup/auditoria
 */
final class DownloadService
{
    public function __construct(
        private readonly SefinClient $client,
        private readonly SefinEndpoints $endpoints,
    ) {}

    /**
     * Retorna o XML completo da NFS-e (com DPS + assinaturas + autorização).
     */
    public function xmlNfse(string $chaveAcesso): string
    {
        $resp = $this->consultarNfse($chaveAcesso);
        if ($resp->xmlRetorno === null) {
            throw new \RuntimeException("XML não disponível pra chave {$chaveAcesso}");
        }
        return $resp->xmlRetorno;
    }

    /**
     * Baixa a DANFSE (PDF gerado pelo Portal Nacional).
     *
     * Após cancelamento, esse PDF retorna com tarja "CANCELADA".
     * Retorna os bytes do PDF. Retenta automaticamente em 502/503/504
     * (endpoint conhecidamente instável); ver `SefinClient::baixarDanfse`.
     *
     * IMPORTANTE: o endpoint oficial está em descontinuação pelo SEFIN
     * (anunciado para 01/07/2026). Após essa data, use `danfseLocal()`
     * para gerar o PDF localmente conforme NT 008/2026.
     */
    public function pdfDanfse(string $chaveAcesso, int $tentativas = 3): string
    {
        $this->validarChave($chaveAcesso);
        return $this->client->baixarDanfse($chaveAcesso, $tentativas);
    }

    /**
     * Verifica se um DPS já foi enviado/processado no SEFIN sem baixar
     * o corpo da resposta (usa HEAD). Útil pra evitar dupla emissão
     * antes de tentar `emitir()`.
     *
     * Retorna true se o DPS existe (HTTP 200), false se não existe
     * (HTTP 404). Outros códigos lançam SefinException.
     */
    public function verificarDps(string $idDps): bool
    {
        $idLimpo = trim($idDps);
        if ($idLimpo === '') {
            throw new ValidationException(['idDps vazio'], 'Verificação DPS');
        }
        return $this->client->verificarDps($idLimpo);
    }

    /**
     * Lista todos os eventos vinculados a uma NFS-e (cancelamento,
     * substituição, manifestações). Útil pra auditoria.
     *
     * @return array<int, mixed> Array bruto dos eventos retornados pelo ADN
     */
    public function listarEventosNfse(string $chaveAcesso): array
    {
        $this->validarChave($chaveAcesso);
        return $this->client->listarEventosNfse($chaveAcesso);
    }

    /**
     * Consulta a NFS-e (helper).
     */
    public function consultarNfse(string $chaveAcesso): SefinResposta
    {
        $this->validarChave($chaveAcesso);
        return $this->client->get($this->endpoints->consultarNfsePorChave($chaveAcesso));
    }

    private function validarChave(string $chave): void
    {
        $clean = preg_replace('/\D/', '', $chave) ?? '';
        if (strlen($clean) !== 50) {
            throw new ValidationException(
                ["Chave de acesso inválida: esperado 50 dígitos (recebeu {$clean})"],
                'Download NFS-e',
            );
        }
    }
}
