<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Compartilhar informações da NFS-e com a Secretaria de Comércio
 * Exterior (MDIC) — campo `<mdic>` dentro de `<comExt>`
 * (XSD V1.01 linha 1474).
 */
enum EnvioMdic: int
{
    case NaoEnviar = 0;
    case Enviar = 1;
}
