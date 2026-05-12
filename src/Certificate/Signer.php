<?php

declare(strict_types=1);

namespace PhpNfseNacional\Certificate;

use DOMDocument;
use DOMElement;
use DOMNode;
use PhpNfseNacional\Exceptions\CertificateException;

/**
 * Assinatura XML do DPS usando rsa-sha1 (exigência do leiaute SEFIN Nacional).
 *
 * IMPORTANTE — OpenSSL 3.5+ (Fedora 43, RHEL 9) desabilita SHA1 por padrão.
 * Quem usa o SDK precisa garantir uma das opções:
 *
 *   1. Setar env var OPENSSL_CONF apontando pra arquivo openssl.cnf com:
 *        [provider_sect]
 *        default = default_sect
 *        legacy = legacy_sect
 *        [default_sect]
 *        activate = 1
 *        [legacy_sect]
 *        activate = 1
 *
 *   2. Ou chamar Signer::habilitarLegacyProviderRuntime() no bootstrap.
 *
 * Sem isso, openssl_sign retorna `error:03000098:digital envelope routines::invalid digest`.
 */
final class Signer
{
    public function __construct(
        private readonly Certificate $certificate,
    ) {}

    /**
     * Assina um nó XML pelo padrão xmldsig (enveloped signature + rsa-sha1).
     *
     * Anexa <Signature> ao final do nó pai e retorna o XML resultante.
     *
     * @param string $xml             XML completo com o elemento a assinar
     * @param string $elementName     Nome do elemento (ex: 'infDPS')
     * @param string $idAttribute     Nome do atributo Id (default 'Id')
     */
    public function sign(string $xml, string $elementName, string $idAttribute = 'Id'): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;
        if (!@$dom->loadXML($xml)) {
            throw new CertificateException('XML inválido pra assinatura');
        }

        $alvo = $dom->getElementsByTagName($elementName)->item(0);
        if (!$alvo instanceof DOMElement) {
            throw new CertificateException("Elemento <{$elementName}> não encontrado");
        }
        $id = $alvo->getAttribute($idAttribute);
        if ($id === '') {
            throw new CertificateException("<{$elementName}> sem atributo {$idAttribute}");
        }

        $c14n = $alvo->C14N(false, false);
        $digest = base64_encode(hash('sha1', $c14n, true));

        $sigInfo = $this->buildSignedInfo($dom, $id, $digest);
        $sigInfoC14n = $sigInfo->C14N(false, false);

        $assinatura = '';
        if (!openssl_sign(
            $sigInfoC14n,
            $assinatura,
            $this->certificate->privateKeyPem,
            OPENSSL_ALGO_SHA1,
        )) {
            $err = openssl_error_string() ?: 'erro desconhecido';
            throw new CertificateException(
                "openssl_sign falhou (provavelmente OPENSSL_CONF/legacy provider desabilitado): {$err}"
            );
        }

        $signatureNode = $this->buildSignatureNode(
            $dom,
            $sigInfo,
            base64_encode($assinatura),
            $this->certPemToBase64(),
        );

        // Append <Signature> como irmão de <infDPS> dentro do pai
        $alvo->parentNode?->appendChild($signatureNode);

        $output = $dom->saveXML();
        if ($output === false) {
            throw new CertificateException('Falha ao serializar XML assinado');
        }
        return $output;
    }

    private function buildSignedInfo(DOMDocument $dom, string $referenciaId, string $digest): DOMElement
    {
        $ns = 'http://www.w3.org/2000/09/xmldsig#';

        $signedInfo = $dom->createElementNS($ns, 'SignedInfo');

        $canon = $dom->createElementNS($ns, 'CanonicalizationMethod');
        $canon->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $signedInfo->appendChild($canon);

        $sigMethod = $dom->createElementNS($ns, 'SignatureMethod');
        $sigMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#rsa-sha1');
        $signedInfo->appendChild($sigMethod);

        $reference = $dom->createElementNS($ns, 'Reference');
        $reference->setAttribute('URI', '#' . $referenciaId);

        $transforms = $dom->createElementNS($ns, 'Transforms');
        $t1 = $dom->createElementNS($ns, 'Transform');
        $t1->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#enveloped-signature');
        $transforms->appendChild($t1);
        $t2 = $dom->createElementNS($ns, 'Transform');
        $t2->setAttribute('Algorithm', 'http://www.w3.org/TR/2001/REC-xml-c14n-20010315');
        $transforms->appendChild($t2);
        $reference->appendChild($transforms);

        $digMethod = $dom->createElementNS($ns, 'DigestMethod');
        $digMethod->setAttribute('Algorithm', 'http://www.w3.org/2000/09/xmldsig#sha1');
        $reference->appendChild($digMethod);

        $digValue = $dom->createElementNS($ns, 'DigestValue', $digest);
        $reference->appendChild($digValue);

        $signedInfo->appendChild($reference);
        return $signedInfo;
    }

    private function buildSignatureNode(
        DOMDocument $dom,
        DOMNode $signedInfo,
        string $signatureValue,
        string $certBase64,
    ): DOMElement {
        $ns = 'http://www.w3.org/2000/09/xmldsig#';

        $signature = $dom->createElementNS($ns, 'Signature');
        $signature->appendChild($signedInfo);

        $sigValueNode = $dom->createElementNS($ns, 'SignatureValue', $signatureValue);
        $signature->appendChild($sigValueNode);

        $keyInfo = $dom->createElementNS($ns, 'KeyInfo');
        $x509Data = $dom->createElementNS($ns, 'X509Data');
        $x509Cert = $dom->createElementNS($ns, 'X509Certificate', $certBase64);
        $x509Data->appendChild($x509Cert);
        $keyInfo->appendChild($x509Data);
        $signature->appendChild($keyInfo);

        return $signature;
    }

    private function certPemToBase64(): string
    {
        $clean = preg_replace(
            '/-----BEGIN CERTIFICATE-----|-----END CERTIFICATE-----|\s/',
            '',
            $this->certificate->certificatePem,
        );
        return $clean ?? '';
    }

    /**
     * Habilita o legacy provider do OpenSSL em runtime, permitindo rsa-sha1
     * em sistemas com OpenSSL 3.5+. Alternativa programática à variável de
     * ambiente OPENSSL_CONF — chame UMA vez no bootstrap da aplicação.
     *
     * Não tem efeito em OpenSSL < 3.0 (legacy é o default).
     */
    public static function habilitarLegacyProviderRuntime(): void
    {
        // Cria arquivo temp com config minimal habilitando legacy
        $conf = <<<INI
openssl_conf = openssl_init
[openssl_init]
providers = provider_sect
[provider_sect]
default = default_sect
legacy = legacy_sect
[default_sect]
activate = 1
[legacy_sect]
activate = 1
INI;
        $tmp = tempnam(sys_get_temp_dir(), 'nfse_openssl_');
        if ($tmp === false) {
            return;
        }
        file_put_contents($tmp, $conf);
        putenv("OPENSSL_CONF={$tmp}");
    }
}
