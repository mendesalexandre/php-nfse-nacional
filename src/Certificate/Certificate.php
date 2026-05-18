<?php

declare(strict_types=1);

namespace PhpNfseNacional\Certificate;

use DateTimeImmutable;
use PhpNfseNacional\Exceptions\CertificateException;

/**
 * Certificado digital A1 (.pfx) carregado em memória.
 *
 * Imutável após carga. Expõe a chave privada e o cert público
 * já extraídos como PEM, pra que o Signer assine sem ler o .pfx
 * de novo a cada operação.
 */
final class Certificate
{
    public function __construct(
        public readonly string $privateKeyPem,
        public readonly string $certificatePem,
        public readonly DateTimeImmutable $validade,
        public readonly string $cnpj,
        public readonly string $subjectCN,
    ) {}

    /**
     * Carrega .pfx do disco. Senha não fica em memória após retornar.
     */
    public static function fromPfxFile(string $caminhoPfx, string $senha): self
    {
        if (!is_file($caminhoPfx)) {
            throw new CertificateException("Arquivo .pfx não encontrado: {$caminhoPfx}");
        }
        $conteudo = file_get_contents($caminhoPfx);
        if ($conteudo === false) {
            throw new CertificateException("Falha ao ler .pfx: {$caminhoPfx}");
        }
        return self::fromPfxContent($conteudo, $senha);
    }

    /**
     * Carrega .pfx a partir de bytes em memória — útil pra cenários onde
     * o cert vem de banco, S3 ou ENV var (base64 decoded antes).
     */
    public static function fromPfxContent(string $pfxBinary, string $senha): self
    {
        $certs = [];
        if (!openssl_pkcs12_read($pfxBinary, $certs, $senha)) {
            $err = openssl_error_string();
            throw new CertificateException("Falha ao abrir .pfx (senha errada ou cert corrompido): {$err}");
        }
        if (empty($certs['cert']) || empty($certs['pkey'])) {
            throw new CertificateException('PFX sem cert ou chave privada');
        }

        $info = openssl_x509_parse($certs['cert']);
        if ($info === false) {
            throw new CertificateException('Falha ao parsear x509');
        }

        $validade = new DateTimeImmutable('@' . $info['validTo_time_t']);
        $cn = $info['subject']['CN'] ?? 'desconhecido';

        // CNPJ aparece no subject ou em extensions (otherName SAN). Tenta
        // primeiro extrair do CN (formato típico: "RAZÃO SOCIAL:CNPJ");
        // se não achar, faz fallback na extensão SAN OID 2.16.76.1.3.3
        // (padrão ICP-Brasil pra cert de PJ — empresa pode ter CNPJ
        // só na SAN sem aparecer no CN, especialmente em certs antigos
        // ou de modelos exóticos).
        $cnpj = '';
        if (preg_match('/(\d{14})/', $cn, $m)) {
            $cnpj = $m[1];
        }
        if ($cnpj === '') {
            $cnpj = self::extrairCnpjDaSan($certs['cert']) ?? '';
        }

        return new self(
            privateKeyPem: $certs['pkey'],
            certificatePem: $certs['cert'],
            validade: $validade,
            cnpj: $cnpj,
            subjectCN: $cn,
        );
    }

    /**
     * Extrai CNPJ da extensão SAN (Subject Alternative Name) do cert ICP-Brasil.
     *
     * Padrão DOC-ICP-04: PJ tem o CNPJ codificado num otherName com OID
     * 2.16.76.1.3.3, formato BCD: 14 dígitos numéricos. O OpenSSL devolve
     * essa extensão como string ASN.1 dump — fazemos um regex sobre o
     * texto/binário do cert PEM porque `openssl_x509_parse` não decodifica
     * otherNames de forma estruturada.
     *
     * Retorna 14 dígitos ou null se não achar.
     */
    private static function extrairCnpjDaSan(string $certPem): ?string
    {
        // Lê o cert como DER pra varrer ASN.1
        $derBase64 = preg_replace('/-----[^-]+-----|\s+/', '', $certPem);
        if (!is_string($derBase64) || $derBase64 === '') {
            return null;
        }
        $der = base64_decode($derBase64, true);
        if ($der === false) {
            return null;
        }

        // OID 2.16.76.1.3.3 em DER = 06 08 60 4C 01 03 03
        // (06=OID tag, 08=length, 60 4C 01 03 03 = encoding do OID)
        $oidDer = "\x06\x08\x60\x4C\x01\x03\x03";
        $pos = strpos($der, $oidDer);
        if ($pos === false) {
            return null;
        }

        // Depois do OID vem o conteúdo do otherName. Procura nos próximos
        // 64 bytes uma sequência de 14 dígitos seguidos no início (formato
        // ICP-Brasil: NNNNNNNNNNNNNNNJJJJJJJJJJJJJJJJJ — primeiros 14 são
        // CNPJ, depois vem nome empresarial / dados do titular).
        $segmento = substr($der, $pos + strlen($oidDer), 256);
        if (preg_match('/(\d{14})/', $segmento, $m)) {
            return $m[1];
        }
        return null;
    }

    public function estaVencido(): bool
    {
        return $this->validade < new DateTimeImmutable();
    }

    public function diasParaVencer(): int
    {
        $diff = $this->validade->diff(new DateTimeImmutable());
        return $diff->invert ? (int) $diff->days : -((int) $diff->days);
    }
}
