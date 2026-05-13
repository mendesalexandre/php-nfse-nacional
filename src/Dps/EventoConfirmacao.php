<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use PhpNfseNacional\Enums\AutorManifestacao;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Evento de Confirmação de NFS-e (manifestação positiva).
 *
 * Códigos por autor:
 *   - e202201 — Confirmação do Prestador
 *   - e203202 — Confirmação do Tomador
 *   - e204203 — Confirmação do Intermediário
 *
 * O grupo do evento (`<e{cod}>`) é mínimo — só `xDesc`. Não tem motivo nem
 * justificativa (Confirmação é positiva por natureza).
 *
 * Restrição leiaute (E1833): só é permitida UMA Confirmação OU UMA Rejeição
 * por não-emitente da NFS-e. Tentar confirmar duas vezes (ou confirmar após
 * rejeitar) resulta em erro do ADN.
 */
final class EventoConfirmacao implements EventoNfse
{
    public readonly string $chaveAcesso;

    public function __construct(
        string $chaveAcesso,
        public readonly AutorManifestacao $autor,
        public readonly int $sequencial = 1,
    ) {
        $errors = [];

        $clean = preg_replace('/\D/', '', $chaveAcesso) ?? '';
        if (strlen($clean) !== 50) {
            $errors[] = "Chave de acesso inválida: esperado 50 dígitos (recebeu '{$chaveAcesso}')";
        }
        $this->chaveAcesso = $clean;

        if ($sequencial < 1 || $sequencial > 99) {
            $errors[] = "Sequencial fora de [1..99]: {$sequencial}";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'EventoConfirmacao inválido');
        }
    }

    public function chaveAcesso(): string
    {
        return $this->chaveAcesso;
    }

    public function codigoTipoEvento(): string
    {
        return $this->autor->codigoConfirmacao();
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
        return 'Manifestação de NFS-e - Confirmação do ' . $this->autor->label();
    }

    /**
     * @return array<string, string>
     */
    public function camposGrupo(): array
    {
        // Confirmação não tem campos no grupo além de xDesc (já tratado pelo
        // EventoBuilder). Retorna array vazio.
        return [];
    }
}
