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
     * Namespace XML usado em todos os documentos do leiaute SEFIN Nacional
     * (DPS, NFSe, pedRegEvento, etc.). Definido pela SE/CGNFS-e e válido
     * pra toda a versão 1.x do leiaute.
     */
    public const NFSE_NAMESPACE = 'http://www.sped.fazenda.gov.br/nfse';

    /**
     * Versão atual do leiaute SefinNacional (atributo `versao` no root dos
     * XMLs). Atualizado conforme a SE/CGNFS-e publica novas versões.
     */
    public const LEIAUTE_VERSAO = '1.01';

    /**
     * Timezone usado pra gerar dhEmi/dhEvento no DPS.
     *
     * O servidor SEFIN registra dhProc em -03:00 (Brasília). Mesmo o
     * prestador estando em UF de timezone diferente, forçamos -03:00 nos
     * timestamps do DPS pra alinhar com o portal e evitar diferença de
     * 1h na DANFSE.
     */
    public const TIMEZONE_DPS = 'America/Sao_Paulo';

    public function __construct(
        public readonly Prestador $prestador,
        public readonly Ambiente $ambiente = Ambiente::Homologacao,
        public readonly int $timeoutSegundos = 30,
        public readonly int $maxTentativas = 3,
        public readonly string $versaoAplicacao = 'php-nfse-1.0',
        public readonly bool $debugLogPayload = false,
        /**
         * Se true, inclui o bloco <IBSCBS> no DPS de envio (Reforma Tributária).
         *
         * Default false: a SEFIN aceita DPS sem IBSCBS hoje (a Reforma está em
         * rampa de subida, alíquotas simbólicas em 2026). Quando virar
         * obrigatório (provável a partir de 2027/2028), basta passar true.
         *
         * Independente desse toggle, o DANFSe local SEMPRE renderiza IBS/CBS
         * se vier no XML autorizado pelo SEFIN — quem renderiza é o ADN/SEFIN,
         * não o emissor.
         */
        public readonly bool $incluirIbsCbs = false,
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
