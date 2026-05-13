<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Certificate;

use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\Exceptions\CertificateException;
use PHPUnit\Framework\TestCase;

/**
 * Testes do `Certificate` carregando .pfx self-signed gerado em runtime.
 *
 * Não polui o repo com fixture binário e cobre os caminhos de:
 *   - Carga via fromPfxContent (bytes em memória)
 *   - Carga via fromPfxFile (arquivo no disco)
 *   - Senha errada → CertificateException
 *   - Arquivo inexistente → CertificateException
 *   - estaVencido() / diasParaVencer()
 */
final class CertificateTest extends TestCase
{
    private const SENHA = 'teste-sdk';

    /**
     * Gera PFX self-signed em memória pra testes.
     * @return array{pfx: string, cnpj: string, cn: string}
     */
    private function gerarPfxSelfSigned(string $cnpj = '00179028000138', string $diasValidade = '+1 year'): array
    {
        $cn = "EMPRESA TESTE SDK:{$cnpj}";

        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        if ($privateKey === false) {
            self::fail('openssl_pkey_new falhou: ' . (openssl_error_string() ?: 'unknown'));
        }

        $csr = openssl_csr_new(
            ['CN' => $cn, 'O' => 'TESTE SDK', 'C' => 'BR'],
            $privateKey,
            ['digest_alg' => 'sha256'],
        );
        if ($csr === false) {
            self::fail('openssl_csr_new falhou');
        }

        $diasInt = (int) ((new \DateTimeImmutable($diasValidade))->diff(new \DateTimeImmutable())->days);
        if ((new \DateTimeImmutable($diasValidade)) < new \DateTimeImmutable()) {
            $diasInt = -$diasInt;
        }

        $cert = openssl_csr_sign($csr, null, $privateKey, $diasInt, ['digest_alg' => 'sha256']);
        if ($cert === false) {
            self::fail('openssl_csr_sign falhou');
        }

        $pfxOut = '';
        $ok = openssl_pkcs12_export($cert, $pfxOut, $privateKey, self::SENHA);
        self::assertTrue($ok, 'openssl_pkcs12_export falhou');
        self::assertNotSame('', $pfxOut);

        return ['pfx' => $pfxOut, 'cnpj' => $cnpj, 'cn' => $cn];
    }

    public function test_fromPfxContent_carrega_cert_valido(): void
    {
        $gen = $this->gerarPfxSelfSigned();

        $cert = Certificate::fromPfxContent($gen['pfx'], self::SENHA);

        self::assertSame($gen['cnpj'], $cert->cnpj);
        self::assertStringContainsString('TESTE SDK', $cert->subjectCN);
        self::assertNotSame('', $cert->privateKeyPem);
        self::assertNotSame('', $cert->certificatePem);
        self::assertFalse($cert->estaVencido());
        self::assertGreaterThan(300, $cert->diasParaVencer());
    }

    public function test_fromPfxFile_le_arquivo_do_disco(): void
    {
        $gen = $this->gerarPfxSelfSigned();
        $tmp = tempnam(sys_get_temp_dir(), 'cert_') . '.pfx';
        file_put_contents($tmp, $gen['pfx']);

        try {
            $cert = Certificate::fromPfxFile($tmp, self::SENHA);
            self::assertSame($gen['cnpj'], $cert->cnpj);
        } finally {
            @unlink($tmp);
        }
    }

    public function test_senha_errada_lanca_CertificateException(): void
    {
        $gen = $this->gerarPfxSelfSigned();

        $this->expectException(CertificateException::class);
        $this->expectExceptionMessageMatches('/senha errada|cert corrompido/i');
        Certificate::fromPfxContent($gen['pfx'], 'senha-errada');
    }

    public function test_arquivo_inexistente_lanca_CertificateException(): void
    {
        $this->expectException(CertificateException::class);
        $this->expectExceptionMessageMatches('/não encontrado/');
        Certificate::fromPfxFile('/path/que/nao/existe.pfx', self::SENHA);
    }

    public function test_cnpj_extraido_do_subjectCN(): void
    {
        $gen = $this->gerarPfxSelfSigned(cnpj: '12345678000195');
        $cert = Certificate::fromPfxContent($gen['pfx'], self::SENHA);
        self::assertSame('12345678000195', $cert->cnpj);
    }

    public function test_construtor_direto_aceita_PEMs_pre_decodificados(): void
    {
        // Caminho avançado — quem já tem PEM decomposto (ex: armazenado em DB)
        $cert = new Certificate(
            privateKeyPem: '-----BEGIN PRIVATE KEY-----DUMMY-----END PRIVATE KEY-----',
            certificatePem: '-----BEGIN CERTIFICATE-----DUMMY-----END CERTIFICATE-----',
            validade: new \DateTimeImmutable('+30 days'),
            cnpj: '12345678000195',
            subjectCN: 'EMPRESA TESTE',
        );

        self::assertSame('12345678000195', $cert->cnpj);
        self::assertSame('EMPRESA TESTE', $cert->subjectCN);
        self::assertFalse($cert->estaVencido());
    }
}
