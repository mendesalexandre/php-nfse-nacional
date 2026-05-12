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
     *
     * Wire format do Portal Nacional:
     *   - Content-Type: application/json
     *   - Body: { "dpsXmlGZipB64": "<XML gzipped + base64>" }
     *
     * O SEFIN responde com JSON contendo `nfseXmlGZipB64` (sucesso) ou
     * `mensagens` (erro) — descompressão é feita em parsearResposta().
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

        return $this->postJsonGzipB64(
            $this->endpoints->enviarDps(),
            'dpsXmlGZipB64',
            $xmlAssinado,
        );
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
     * Faz POST de evento (cancelamento, etc.) ao endpoint de eventos.
     * Wire format igual ao DPS mas com campo `pedidoRegistroEventoXmlGZipB64`.
     */
    public function postEvento(string $url, string $xmlAssinado): SefinResposta
    {
        return $this->postJsonGzipB64(
            $url,
            'pedidoRegistroEventoXmlGZipB64',
            $xmlAssinado,
        );
    }

    /**
     * Helper interno — POST com payload JSON contendo um único campo cujo
     * valor é gzip+base64 do XML assinado. Padrão do SEFIN Nacional pra
     * envio de DPS e eventos.
     */
    private function postJsonGzipB64(string $url, string $fieldName, string $xmlAssinado): SefinResposta
    {
        // O DOMDocument::saveXML já inclui o prolog XML. Não duplicar.
        $gz = gzencode($xmlAssinado);
        if ($gz === false) {
            throw new SefinException(null, null, 'Falha ao comprimir XML pra envio');
        }
        $payload = json_encode([$fieldName => base64_encode($gz)]);
        if ($payload === false) {
            throw new SefinException(null, null, 'Falha ao serializar payload JSON');
        }

        $response = $this->http->request('POST', $url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
            ],
            'body' => $payload,
            'timeout' => $this->config->timeoutSegundos,
            'http_errors' => false,
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
        // Caso 1: erro estruturado em JSON (formato SEFIN). O SEFIN devolve com
        // várias variações de chave/case: `erros`/`erro` (a emissão e o evento de
        // cancelamento usam o singular `erro`). Os subcampos também alternam entre
        // `Codigo/Descricao/Complemento` (maiúsculas) e `codigo/descricao/complemento`.
        $json = @json_decode($body, true);
        if (is_array($json)) {
            $listaErros = null;
            foreach (['erros', 'erro'] as $chave) {
                if (isset($json[$chave]) && is_array($json[$chave]) && !empty($json[$chave])) {
                    $listaErros = $json[$chave];
                    break;
                }
            }
            if ($listaErros !== null) {
                $primeiro = $listaErros[0];
                $codigo = $primeiro['Codigo'] ?? $primeiro['codigo'] ?? null;
                $descricao = $primeiro['Descricao'] ?? $primeiro['descricao'] ?? null;
                $complemento = $primeiro['Complemento'] ?? $primeiro['complemento'] ?? null;
                $cStat = is_string($codigo) && preg_match('/(\d+)/', $codigo, $m) ? (int) $m[1] : null;
                $xMotivo = trim(($descricao ?? '') . ($complemento !== null ? ' — ' . mb_substr($complemento, 0, 200) : ''));
                return new SefinResposta(
                    chaveAcesso: null,
                    cStat: $cStat,
                    xMotivo: $xMotivo !== '' ? $xMotivo : null,
                    protocolo: null,
                    numeroNfse: null,
                    codigoVerificacao: null,
                    dataProcessamento: $json['dataHoraProcessamento'] ?? null,
                    xmlRetorno: null,
                    rawResponse: $body,
                );
            }
        }

        // Caso 2: sucesso em JSON com XML gzip+base64 (emissão, consulta, eventos).
        // Estrutura: { chaveAcesso, dataHoraProcessamento, <algoXmlGZipB64> }
        // Cobre `nfseXmlGZipB64`, `dpsXmlGZipB64`, `eventoNfseXmlGZipB64`, etc.
        $temCampoGZipB64 = is_array($json) && (function (array $j): bool {
            foreach ($j as $k => $_) {
                if (is_string($k) && str_ends_with($k, 'XmlGZipB64')) {
                    return true;
                }
            }
            return false;
        })($json);
        if ($temCampoGZipB64) {
            $xmlRetorno = $this->extrairXmlDoEnvelope($body);
            // cStat 100 implícito no caso de sucesso (não vem no payload de consulta)
            $chaveJson = is_string($json['chaveAcesso'] ?? null) ? $json['chaveAcesso'] : null;
            $chaveFinal = $chaveJson ?? $this->extrairTag($xmlRetorno, 'chNFSe');
            return new SefinResposta(
                chaveAcesso: $chaveFinal,
                cStat: 100,
                xMotivo: null,
                protocolo: $this->extrairTag($xmlRetorno, 'nDFSe') ?? $this->extrairTag($xmlRetorno, 'nProt'),
                numeroNfse: $this->extrairTag($xmlRetorno, 'nNFSe'),
                codigoVerificacao: $this->extrairTag($xmlRetorno, 'cVerif'),
                dataProcessamento: $json['dataHoraProcessamento'] ?? $this->extrairTag($xmlRetorno, 'dhProc'),
                xmlRetorno: $xmlRetorno,
                rawResponse: $body,
            );
        }

        // Caso 3: resposta XML direta (raro, mas mantido por retrocompat)
        $xmlRetorno = $this->extrairXmlDoEnvelope($body);

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
     * Extrai o XML cru do envelope JSON usado pelo SEFIN Nacional.
     *
     * O portal devolve respostas no formato:
     *   { "<nome>XmlGZipB64": "<base64(gzip(xml))>", ... }
     *
     * Onde `<nome>` varia conforme a operação (`nfse`, `dps`, `eventoNfse`, ...).
     * Faz base64_decode + gzdecode do primeiro campo `*XmlGZipB64` encontrado.
     * Se o body não for um envelope JSON com esse campo, retorna o body original
     * (assume que já é XML).
     */
    private function extrairXmlDoEnvelope(string $body): string
    {
        if (!str_contains($body, 'GZipB64')) {
            return $body;
        }
        $json = @json_decode($body, true);
        if (!is_array($json)) {
            return $body;
        }
        foreach ($json as $chave => $valor) {
            if (!is_string($chave) || !is_string($valor) || !str_ends_with($chave, 'GZipB64')) {
                continue;
            }
            $decoded = base64_decode($valor, true);
            if ($decoded === false) {
                continue;
            }
            $gz = @gzdecode($decoded);
            if ($gz !== false) {
                return $gz;
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
