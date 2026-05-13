<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use PhpNfseNacional\DTO\MotivoRejeicao;
use PhpNfseNacional\Enums\AutorManifestacao;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Evento de Rejeição de NFS-e (manifestação negativa).
 *
 * Códigos por autor:
 *   - e202205 — Rejeição do Prestador
 *   - e203206 — Rejeição do Tomador
 *   - e204207 — Rejeição do Intermediário
 *
 * Grupo `<infRej>` (TCInfoEventoRejeicao):
 *   - cMotivo: 1, 2, 3, 4, 5 ou 9 (ver MotivoRejeicao)
 *   - xMotivo: descrição livre — obrigatória quando cMotivo=9 (Outros).
 *     ADN valida com cStat=1944/1949/1954 quando ausente
 *
 * Pode ser anulada via `EventoAnulacaoRejeicao` (código e205208) referenciando
 * o `Id` desta rejeição.
 */
final class EventoRejeicao implements EventoNfse
{
    public readonly string $chaveAcesso;
    public readonly string $xMotivo;

    public function __construct(
        string $chaveAcesso,
        public readonly AutorManifestacao $autor,
        public readonly MotivoRejeicao $motivo,
        string $xMotivo = '',
        public readonly int $sequencial = 1,
    ) {
        $errors = [];

        $clean = preg_replace('/\D/', '', $chaveAcesso) ?? '';
        if (strlen($clean) !== 50) {
            $errors[] = "Chave de acesso inválida: esperado 50 dígitos (recebeu '{$chaveAcesso}')";
        }
        $this->chaveAcesso = $clean;

        $just = trim($xMotivo);
        if ($motivo->exigeXMotivo() && mb_strlen($just) < 15) {
            $errors[] = "xMotivo obrigatório quando motivo=Outros (mínimo 15 chars; recebeu " . mb_strlen($just) . ')';
        }
        if ($just !== '' && mb_strlen($just) > 200) {
            $errors[] = 'xMotivo deve ter no máximo 200 caracteres (atual: ' . mb_strlen($just) . ')';
        }
        $this->xMotivo = $just;

        if ($sequencial < 1 || $sequencial > 99) {
            $errors[] = "Sequencial fora de [1..99]: {$sequencial}";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'EventoRejeicao inválido');
        }
    }

    public function chaveAcesso(): string
    {
        return $this->chaveAcesso;
    }

    public function codigoTipoEvento(): string
    {
        return $this->autor->codigoRejeicao();
    }

    public function nSequencial(): int
    {
        return $this->sequencial;
    }

    public function descricao(): string
    {
        // xDesc é enumeração restrita do leiaute (TS_xDesc) — texto exato
        // exigido pelo SEFIN. Outros valores resultam em E1235 ("Enumeration
        // constraint failed").
        return 'Manifestação de NFS-e - Rejeição do ' . $this->autor->label();
    }

    /**
     * @return array<string, string>
     */
    public function camposGrupo(): array
    {
        $campos = ['cMotivo' => (string) $this->motivo->value];
        if ($this->xMotivo !== '') {
            $campos['xMotivo'] = $this->xMotivo;
        }
        return $campos;
    }
}
