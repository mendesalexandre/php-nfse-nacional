<?php

declare(strict_types=1);

namespace PhpNfseNacional\Exceptions;

use RuntimeException;

/**
 * Exceção base do SDK NFS-e Nacional.
 * Demais exceções herdam dessa pra permitir catch genérico.
 */
class NfseException extends RuntimeException
{
}
