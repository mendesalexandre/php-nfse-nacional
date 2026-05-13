<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use PhpNfseNacional\DTO\MotivoSubstituicao;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Evento e105102 — Cancelamento por Substituição.
 *
 * Cancela uma NFS-e e a vincula a outra NFS-e (substituidora) emitida em
 * separado. A substituidora deve ter sido emitida normalmente ANTES (rota
 * /nfse padrão) — esse evento apenas registra o vínculo + cancelamento.
 *
 * Grupo `<e105102>` (TE105102):
 *   - cMotivo (TSCodJustSubst) — DIFERENTE de cancelamento simples!
 *     01 = DesenquadramentoSimples, 02 = EnquadramentoSimples,
 *     03 = InclusaoImunidade, 04 = ExclusaoImunidade,
 *     05 = RejeicaoTomador, 99 = Outros (exige xMotivo)
 *   - xMotivo (opcional, exigido se motivo=Outros)
 *   - chSubstituta — chave da NFS-e nova
 *
 * xDesc é enumeração restrita pelo leiaute: exatamente
 * "Cancelamento de NFS-e por Substituição".
 *
 * Validações: ambas as chaves de 50 dígitos, justificativa 15-200 chars
 * (quando motivo=Outros), sequencial 1-99.
 */
final class EventoSubstituicao implements EventoNfse
{
    public readonly string $chaveAcesso;
    public readonly string $chaveSubstituta;
    public readonly string $justificativa;

    public function __construct(
        string $chaveAcesso,
        string $chaveSubstituta,
        public readonly MotivoSubstituicao $motivo,
        string $justificativa = '',
        public readonly int $sequencial = 1,
    ) {
        $errors = [];

        $orig = preg_replace('/\D/', '', $chaveAcesso) ?? '';
        if (strlen($orig) !== 50) {
            $errors[] = "Chave da NFS-e original inválida: esperado 50 dígitos (recebeu '{$chaveAcesso}')";
        }
        $this->chaveAcesso = $orig;

        $subst = preg_replace('/\D/', '', $chaveSubstituta) ?? '';
        if (strlen($subst) !== 50) {
            $errors[] = "Chave da NFS-e substituidora inválida: esperado 50 dígitos (recebeu '{$chaveSubstituta}')";
        }
        if ($orig === $subst && $orig !== '') {
            $errors[] = 'Chave substituidora não pode ser igual à chave original';
        }
        $this->chaveSubstituta = $subst;

        $just = trim($justificativa);
        if ($motivo->exigeXMotivo() && mb_strlen($just) < 15) {
            $errors[] = "xMotivo obrigatório quando motivo=Outros (mínimo 15 chars; recebeu " . mb_strlen($just) . ')';
        }
        if ($just !== '' && mb_strlen($just) > 200) {
            $errors[] = sprintf('Justificativa deve ter no máximo 200 caracteres (atual: %d)', mb_strlen($just));
        }
        $this->justificativa = $just;

        if ($sequencial < 1 || $sequencial > 99) {
            $errors[] = "Sequencial fora de [1..99]: {$sequencial}";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'EventoSubstituicao inválido');
        }
    }

    public function chaveAcesso(): string
    {
        return $this->chaveAcesso;
    }

    public function codigoTipoEvento(): string
    {
        return '105102';
    }

    public function nSequencial(): int
    {
        return $this->sequencial;
    }

    public function descricao(): string
    {
        // xDesc é enumeração restrita do leiaute (TS_xDesc) — texto exato
        // exigido pelo SEFIN, alinhado com Manual de Integração SefinNacional 1.6
        // página 56 (TE105102).
        return 'Cancelamento de NFS-e por Substituição';
    }

    /**
     * @return array<string, string>
     */
    public function camposGrupo(): array
    {
        $campos = ['cMotivo' => $this->motivo->codigoFormatado()];
        if ($this->justificativa !== '') {
            $campos['xMotivo'] = $this->justificativa;
        }
        $campos['chSubstituta'] = $this->chaveSubstituta;
        return $campos;
    }
}
