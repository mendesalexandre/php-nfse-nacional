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
     * Retorna os bytes do PDF.
     */
    public function pdfDanfse(string $chaveAcesso): string
    {
        $this->validarChave($chaveAcesso);
        return $this->client->baixarDanfse($chaveAcesso);
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
