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

        // CNPJ aparece no subject ou em extensions (otherName). Pra simplicidade
        // tentamos extrair do CN (formato típico: "RAZÃO SOCIAL:CNPJ").
        $cnpj = '';
        if (preg_match('/(\d{14})/', $cn, $m)) {
            $cnpj = $m[1];
        }

        return new self(
            privateKeyPem: $certs['pkey'],
            certificatePem: $certs['cert'],
            validade: $validade,
            cnpj: $cnpj,
            subjectCN: $cn,
        );
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
