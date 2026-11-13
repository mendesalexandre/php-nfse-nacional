<?php

declare(strict_types=1);

namespace PhpNfseNacional\Services;

use PhpNfseNacional\Exceptions\SefinException;
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Sefin\AliquotaMunicipal;
use PhpNfseNacional\Sefin\ConvenioMunicipal;
use PhpNfseNacional\Sefin\SefinClient;
use PhpNfseNacional\Sefin\SefinEndpoints;

/**
 * Consultas à API de Parâmetros Municipais do ADN: convênio do município e
 * alíquota do ISS por serviço. Úteis pra conferir, antes de emitir, se a
 * alíquota configurada ainda é a vigente e se o município está no padrão
 * nacional.
 */
final class ParametrosMunicipaisService
{
    public function __construct(
        private readonly SefinClient $client,
        private readonly SefinEndpoints $endpoints,
    ) {}

    /**
     * @param string $codigoMunicipio IBGE de 7 dígitos (aceita máscara)
     */
    public function convenio(string $codigoMunicipio): ConvenioMunicipal
    {
        $municipio = $this->normalizarMunicipio($codigoMunicipio);
        $json = $this->client->getParametros($this->endpoints->parametrosConvenio($municipio));

        return ConvenioMunicipal::fromArray($json);
    }

    /**
     * Alíquota vigente do serviço na competência (default: hoje).
     *
     * @param string $codigoServico Código nacional com 6 (`NN.NN.NN`) ou 9
     *                              dígitos (`NN.NN.NN.NNN`); aceita máscara
     * @throws SefinException quando o município não tem alíquota publicada pro serviço
     */
    public function aliquota(
        string $codigoMunicipio,
        string $codigoServico,
        ?\DateTimeInterface $competencia = null,
    ): AliquotaMunicipal {
        $municipio = $this->normalizarMunicipio($codigoMunicipio);
        $servico = $this->normalizarServico($codigoServico);
        $competencia ??= new \DateTimeImmutable('today');

        $json = $this->client->getParametros(
            $this->endpoints->parametrosAliquota($municipio, $servico, $competencia->format('m-d-Y'))
        );

        $porServico = $json['aliquotas'] ?? $json['Aliquotas'] ?? [];
        $lista = is_array($porServico) ? ($porServico[$servico] ?? null) : null;
        if (!is_array($lista) || $lista === []) {
            throw new SefinException(
                cStat: null,
                xMotivo: null,
                message: "Município {$municipio} sem alíquota publicada para o serviço {$servico}",
                rawResponse: (string) json_encode($json),
            );
        }

        $escolhida = $this->vigenteEm($lista, $competencia);

        return new AliquotaMunicipal(
            codigoServico: $servico,
            incidencia: ConvenioMunicipal::paraBool($escolhida['Incidencia'] ?? $escolhida['incidencia'] ?? null),
            aliquotaPercentual: (float) ($escolhida['Aliq'] ?? $escolhida['aliq'] ?? 0),
            vigenciaInicio: $this->texto($escolhida['DtIni'] ?? $escolhida['dtIni'] ?? null),
            vigenciaFim: $this->texto($escolhida['DtFim'] ?? $escolhida['dtFim'] ?? null),
        );
    }

    /**
     * Entre as vigências devolvidas, a que cobre a competência; se nenhuma
     * cobrir, a mais recente.
     *
     * @param array<int, mixed> $lista
     * @return array<string, mixed>
     */
    private function vigenteEm(array $lista, \DateTimeInterface $competencia): array
    {
        $dia = $competencia->format('Y-m-d');
        $ultima = [];
        foreach ($lista as $item) {
            if (!is_array($item)) {
                continue;
            }
            $ultima = $item;
            $ini = substr((string) ($item['DtIni'] ?? $item['dtIni'] ?? ''), 0, 10);
            $fim = substr((string) ($item['DtFim'] ?? $item['dtFim'] ?? ''), 0, 10);
            if (($ini === '' || $ini <= $dia) && ($fim === '' || $fim >= $dia)) {
                return $item;
            }
        }

        return $ultima;
    }

    private function texto(mixed $valor): ?string
    {
        return is_string($valor) && $valor !== '' ? $valor : null;
    }

    private function normalizarMunicipio(string $codigo): string
    {
        $digitos = preg_replace('/\D/', '', $codigo) ?? '';
        if (!preg_match('/^\d{7}$/', $digitos)) {
            throw new ValidationException(['Código do município deve ter 7 dígitos (IBGE)'], 'ParametrosMunicipais');
        }

        return $digitos;
    }

    private function normalizarServico(string $codigo): string
    {
        $digitos = preg_replace('/\D/', '', $codigo) ?? '';
        if (!preg_match('/^(\d{6}|\d{9})$/', $digitos)) {
            throw new ValidationException(['Código do serviço deve ter 6 ou 9 dígitos'], 'ParametrosMunicipais');
        }
        if (strlen($digitos) === 6) {
            $digitos .= '000';
        }

        return sprintf('%s.%s.%s.%s', substr($digitos, 0, 2), substr($digitos, 2, 2), substr($digitos, 4, 2), substr($digitos, 6, 3));
    }
}
