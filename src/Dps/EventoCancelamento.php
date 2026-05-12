<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Evento e101101 — Cancelamento de NFS-e.
 *
 * Imutável. Validações no construtor: chave de 50 dígitos, justificativa
 * entre 15 e 200 caracteres.
 */
final class EventoCancelamento implements EventoNfse
{
    public readonly string $chaveAcesso;

    public function __construct(
        string $chaveAcesso,
        public readonly MotivoCancelamento $motivo,
        public readonly string $justificativa,
        public readonly int $sequencial = 1,
    ) {
        $errors = [];

        $chaveLimpa = preg_replace('/\D/', '', $chaveAcesso) ?? '';
        if (strlen($chaveLimpa) !== 50) {
            $errors[] = "Chave de acesso inválida: esperado 50 dígitos (recebeu '{$chaveAcesso}')";
        }
        $this->chaveAcesso = $chaveLimpa;

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
            throw new ValidationException($errors, 'EventoCancelamento inválido');
        }
    }

    public function chaveAcesso(): string
    {
        return $this->chaveAcesso;
    }

    public function codigoTipoEvento(): string
    {
        return '101101';
    }

    public function nSequencial(): int
    {
        return $this->sequencial;
    }

    public function descricao(): string
    {
        return 'Cancelamento de NFS-e';
    }

    /**
     * @return array<string, string>
     */
    public function camposGrupo(): array
    {
        return [
            'cMotivo' => (string) $this->motivo->value,
            'xMotivo' => trim($this->justificativa),
        ];
    }
}
