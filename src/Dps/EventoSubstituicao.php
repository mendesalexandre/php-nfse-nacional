<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Evento e105102 — Cancelamento por substituição.
 *
 * Cancela uma NFS-e e a vincula a outra NFS-e (substituidora) emitida
 * em separado. A substituidora deve ter sido emitida normalmente ANTES
 * (rota /nfse padrão) — esse evento apenas registra o vínculo +
 * cancelamento.
 *
 * Validações: ambas as chaves de 50 dígitos, justificativa 15-200 chars,
 * sequencial 1-99.
 */
final class EventoSubstituicao implements EventoNfse
{
    public readonly string $chaveAcesso;
    public readonly string $chaveSubstituta;

    public function __construct(
        string $chaveAcesso,
        string $chaveSubstituta,
        public readonly MotivoCancelamento $motivo,
        public readonly string $justificativa,
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
        if (mb_strlen($just) < 15 || mb_strlen($just) > 200) {
            $errors[] = sprintf(
                'Justificativa deve ter entre 15 e 200 caracteres (atual: %d)',
                mb_strlen($just),
            );
        }
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
        return 'Cancelamento por substituição';
    }

    /**
     * @return array<string, string>
     */
    public function camposGrupo(): array
    {
        return [
            'cMotivo' => (string) $this->motivo->value,
            'xMotivo' => trim($this->justificativa),
            'chSubstituta' => $this->chaveSubstituta,
        ];
    }
}
