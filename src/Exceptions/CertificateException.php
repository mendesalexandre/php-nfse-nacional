<?php

declare(strict_types=1);

namespace PhpNfseNacional\Exceptions;

/**
 * Erro relacionado ao certificado digital A1:
 * arquivo .pfx ausente/inválido, senha errada, certificado expirado,
 * falha de assinatura rsa-sha1 (geralmente OPENSSL_CONF desconfigurado).
 */
class CertificateException extends NfseException
{
}
