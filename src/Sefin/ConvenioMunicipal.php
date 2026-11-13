<?php

declare(strict_types=1);

namespace PhpNfseNacional\Sefin;

/**
 * Convênio de um município com o Sistema Nacional da NFS-e (API de
 * Parâmetros Municipais do ADN).
 */
final class ConvenioMunicipal
{
    public function __construct(
        public readonly bool $aderenteAmbienteNacional,
        public readonly bool $aderenteEmissorNacional,
        public readonly ?int $situacaoEmissaoPadraoContribuintesRfb,
        public readonly bool $aderenteMan,
        public readonly bool $permiteAproveitamentoCreditos,
        public readonly ?string $mensagem = null,
    ) {}

    /** @param array<string, mixed> $json Corpo da resposta do ADN. */
    public static function fromArray(array $json): self
    {
        $p = $json['parametrosConvenio'] ?? $json['ParametrosConvenio'] ?? $json;
        $p = is_array($p) ? $p : [];
        $situacao = $p['situacaoEmissaoPadraoContribuintesRFB'] ?? null;

        return new self(
            aderenteAmbienteNacional: self::paraBool($p['aderenteAmbienteNacional'] ?? null),
            aderenteEmissorNacional: self::paraBool($p['aderenteEmissorNacional'] ?? null),
            situacaoEmissaoPadraoContribuintesRfb: is_numeric($situacao) ? (int) $situacao : null,
            aderenteMan: self::paraBool($p['aderenteMAN'] ?? null),
            permiteAproveitamentoCreditos: self::paraBool($p['permiteAproveitametoDeCreditos'] ?? $p['permiteAproveitamentoDeCreditos'] ?? null),
            mensagem: isset($json['mensagem']) && is_string($json['mensagem']) ? $json['mensagem'] : null,
        );
    }

    /** O ADN devolve booleanos como `true`, `1`, `"1"`, `"true"` ou `"SIM"`. */
    public static function paraBool(mixed $valor): bool
    {
        return $valor === true
            || $valor === 1
            || (is_string($valor) && in_array(strtolower($valor), ['1', 'true', 'sim', 's'], true));
    }
}
