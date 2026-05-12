<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\Exceptions;

/**
 * Erro retornado pelo Portal Nacional SEFIN durante envio/consulta/cancelamento.
 * Carrega o cStat (código de status) e a mensagem original do portal.
 */
class SefinException extends NfseException
{
    public function __construct(
        public readonly ?int $cStat,
        public readonly ?string $xMotivo,
        string $message = '',
        public readonly ?string $rawResponse = null,
    ) {
        $msg = $message ?: sprintf(
            'SEFIN retornou cStat=%s: %s',
            $cStat ?? 'null',
            $xMotivo ?? 'sem mensagem',
        );
        parent::__construct($msg);
    }
}
