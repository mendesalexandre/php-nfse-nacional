<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Config;
use PhpNfseNacional\Exceptions\SefinException;

/**
 * Cliente HTTP pro Portal Nacional SEFIN.
 *
 * Wrapper fino sobre o cliente Guzzle (ou outro PSR-18). Autentica com
 * certificado A1 (mutual TLS), trata gzip/base64 dos retornos e parseia
 * pra `SefinResposta` normalizada.
 *
 * Pode receber qualquer cliente PSR-18 — testes injetam um mock.
 */
final class SefinClient
{
    private ClientInterface $http;
    private LoggerInterface $logger;

    public function __construct(
        private readonly Config $config,
        private readonly Certificate $certificate,
        private readonly SefinEndpoints $endpoints,
        ?ClientInterface $httpClient = null,
        ?LoggerInterface $logger = null,
    ) {
        $this->logger = $logger ?? new NullLogger();
        $this->http = $httpClient ?? $this->buildDefaultGuzzle();
    }

    /**
     * Envia o XML do DPS (já assinado) ao SEFIN.
     * Retorna a resposta normalizada.
     */
    public function enviarDps(string $xmlAssinado): SefinResposta
    {
        $this->logger->info('[SefinClient] Enviando DPS', [
            'ambiente' => $this->config->ambiente->label(),
            'tamanho_xml' => strlen($xmlAssinado),
            'debug_payload' => $this->config->debugLogPayload,
        ]);
        if ($this->config->debugLogPayload) {
            $this->logger->debug('[SefinClient] XML enviado', ['xml' => $xmlAssinado]);
        }

        $response = $this->http->request('POST', $this->endpoints->enviarDps(), [
            'headers' => [
                'Content-Type' => 'application/xml',
                'Accept' => 'application/xml',
            ],
            'body' => $xmlAssinado,
            'timeout' => $this->config->timeoutSegundos,
            'http_errors' => false, // não joga exceção em 4xx/5xx — tratamos manualmente
        ]);

        $body = (string) $response->getBody();
        $statusHttp = $response->getStatusCode();

        $this->logger->info('[SefinClient] Resposta recebida', [
            'status_http' => $statusHttp,
            'tamanho_resp' => strlen($body),
        ]);

        if ($statusHttp >= 500) {
            throw new SefinException(
                cStat: null,
                xMotivo: null,
                message: "SEFIN retornou erro HTTP {$statusHttp} — tente novamente em alguns minutos",
                rawResponse: $body,
            );
        }

        return $this->parsearResposta($body);
    }

    /**
     * Faz GET genérico nos endpoints de consulta (NFS-e por chave, DPS, eventos).
     */
    public function get(string $url): SefinResposta
    {
        $response = $this->http->request('GET', $url, [
            'timeout' => $this->config->timeoutSegundos,
            'http_errors' => false,
        ]);
        return $this->parsearResposta((string) $response->getBody());
    }

    /**
     * Faz POST de XML em endpoint arbitrário (usado pra eventos como cancelamento).
     */
    public function postXml(string $url, string $xmlBody): SefinResposta
    {
        $response = $this->http->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/xml',
                'Accept' => 'application/xml',
            ],
            'body' => $xmlBody,
            'timeout' => $this->config->timeoutSegundos,
            'http_errors' => false,
        ]);

        $body = (string) $response->getBody();
        $statusHttp = $response->getStatusCode();

        if ($statusHttp >= 500) {
            throw new SefinException(
                cStat: null,
                xMotivo: null,
                message: "SEFIN retornou erro HTTP {$statusHttp}",
                rawResponse: $body,
            );
        }
        return $this->parsearResposta($body);
    }

    /**
     * Baixa o DANFSE (PDF) bruto. Retorna bytes do PDF.
     */
    public function baixarDanfse(string $chave): string
    {
        $response = $this->http->request('GET', $this->endpoints->downloadDanfse($chave), [
            'timeout' => $this->config->timeoutSegundos,
            'http_errors' => false,
            'headers' => ['Accept' => 'application/pdf'],
        ]);

        $body = (string) $response->getBody();
        $status = $response->getStatusCode();

        if ($status !== 200) {
            throw new SefinException(
                cStat: null,
                xMotivo: null,
                message: "Falha ao baixar DANFSE chave={$chave}: HTTP {$status}",
                rawResponse: $body,
            );
        }
        if (substr($body, 0, 4) !== '%PDF') {
            throw new SefinException(
                cStat: null,
                xMotivo: null,
                message: "Resposta não é PDF válido (chave={$chave})",
                rawResponse: substr($body, 0, 200),
            );
        }
        return $body;
    }

    /**
     * Parseia o XML retornado pelo SEFIN (possivelmente com gzip+base64
     * em campos `*GZipB64`) e normaliza pra `SefinResposta`.
     */
    private function parsearResposta(string $body): SefinResposta
    {
        $xmlRetorno = $this->descomprimirSeNecessario($body);

        $chave = $this->extrairTag($xmlRetorno, 'chNFSe')
            ?? $this->extrairAtributo($xmlRetorno, 'Id', 'NFS')
            ?? null;
        $cStat = $this->extrairTagInt($xmlRetorno, 'cStat');
        $xMotivo = $this->extrairTag($xmlRetorno, 'xMotivo');
        $protocolo = $this->extrairTag($xmlRetorno, 'nProt')
            ?? $this->extrairTag($xmlRetorno, 'protocolo');
        $nNFSe = $this->extrairTag($xmlRetorno, 'nNFSe');
        $cVerif = $this->extrairTag($xmlRetorno, 'cVerif');
        $dhProc = $this->extrairTag($xmlRetorno, 'dhProc');

        return new SefinResposta(
            chaveAcesso: $chave,
            cStat: $cStat,
            xMotivo: $xMotivo,
            protocolo: $protocolo,
            numeroNfse: $nNFSe,
            codigoVerificacao: $cVerif,
            dataProcessamento: $dhProc,
            xmlRetorno: $xmlRetorno,
            rawResponse: $body,
        );
    }

    /**
     * Algumas respostas do SEFIN vêm wrapped em JSON com campos *GZipB64.
     * Descomprime quando detectado, senão retorna o body como está.
     */
    private function descomprimirSeNecessario(string $body): string
    {
        if (str_contains($body, 'GZipB64')) {
            // Tenta extrair o campo *GZipB64 do JSON
            if (preg_match('/"([a-zA-Z]+GZipB64)":"([^"]+)"/', $body, $m)) {
                $base64 = $m[2];
                $decoded = base64_decode($base64, true);
                if ($decoded !== false) {
                    $gz = @gzdecode($decoded);
                    if ($gz !== false) {
                        return $gz;
                    }
                }
            }
        }
        return $body;
    }

    private function extrairTag(string $xml, string $tagName): ?string
    {
        if (preg_match('/<' . preg_quote($tagName, '/') . '[^>]*>([^<]*)<\/' . preg_quote($tagName, '/') . '>/u', $xml, $m)) {
            return trim($m[1]) ?: null;
        }
        return null;
    }

    private function extrairTagInt(string $xml, string $tagName): ?int
    {
        $v = $this->extrairTag($xml, $tagName);
        return $v !== null && ctype_digit($v) ? (int) $v : null;
    }

    private function extrairAtributo(string $xml, string $attr, string $prefix): ?string
    {
        if (preg_match('/Id="' . preg_quote($prefix, '/') . '([0-9]+)"/', $xml, $m)) {
            return $m[1];
        }
        return null;
    }

    private function buildDefaultGuzzle(): ClientInterface
    {
        // Mutual TLS: passamos o cert+key extraídos como arquivos temporários
        // do certificado. Guzzle exige path (não suporta conteúdo direto).
        $tmpCert = tempnam(sys_get_temp_dir(), 'nfse_cert_');
        $tmpKey = tempnam(sys_get_temp_dir(), 'nfse_key_');
        if ($tmpCert === false || $tmpKey === false) {
            throw new SefinException(null, null, 'Falha ao criar arquivos temp pro certificado');
        }
        file_put_contents($tmpCert, $this->certificate->certificatePem);
        file_put_contents($tmpKey, $this->certificate->privateKeyPem);

        return new Client([
            'cert' => $tmpCert,
            'ssl_key' => $tmpKey,
            'verify' => true,
            'connect_timeout' => 10,
        ]);
    }
}
