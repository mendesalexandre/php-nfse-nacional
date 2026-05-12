<?php

declare(strict_types=1);

namespace PhpNfseNacional;

use PhpNfseNacional\DTO\Prestador;
use PhpNfseNacional\Enums\Ambiente;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Configuração global do SDK.
 *
 * Imutável. Crie uma vez no bootstrap da aplicação e injete onde precisar.
 *
 * O caminho do certificado e a senha NÃO ficam aqui — são responsabilidade
 * do CertificateLoader (que pode receber path absoluto, conteúdo binário
 * ou fluxo, dependendo do uso).
 */
final class Config
{
    /**
     * Timezone usado pra gerar dhEmi/dhEvento no DPS.
     *
     * O servidor SEFIN registra dhProc em -03:00 (Brasília). Mesmo o
     * cartório estando em UF -04:00 (MT), forçamos -03:00 nos timestamps
     * do DPS pra alinhar com o portal e evitar diferença de 1h na DANFSE.
     */
    public const TIMEZONE_DPS = 'America/Sao_Paulo';

    public function __construct(
        public readonly Prestador $prestador,
        public readonly Ambiente $ambiente = Ambiente::Homologacao,
        public readonly int $timeoutSegundos = 30,
        public readonly int $maxTentativas = 3,
        public readonly string $versaoAplicacao = 'sinop-nfse-1.0',
        public readonly bool $debugLogPayload = false,
    ) {
        $errors = [];
        if ($timeoutSegundos < 5 || $timeoutSegundos > 300) {
            $errors[] = "timeoutSegundos fora de [5..300]: {$timeoutSegundos}";
        }
        if ($maxTentativas < 1 || $maxTentativas > 10) {
            $errors[] = "maxTentativas fora de [1..10]: {$maxTentativas}";
        }
        if (mb_strlen($versaoAplicacao) > 20) {
            $errors[] = 'versaoAplicacao muito longa (máximo 20 caracteres)';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Config inválida');
        }
    }

    public function ehProducao(): bool
    {
        return $this->ambiente === Ambiente::Producao;
    }
}
