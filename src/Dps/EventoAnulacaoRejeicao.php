<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Evento de Anulação de Rejeição (e205208).
 *
 * Desfaz uma `EventoRejeicao` previamente registrada. Útil quando o autor
 * (Prestador/Tomador/Intermediário) rejeitou uma NFS-e por engano e quer
 * reverter pra estado neutro.
 *
 * Grupo `<infAnRej>` (TCInfoEventoAnulacaoRejeicao):
 *   - idEvManifRej: Id da Manifestação de Rejeição original (formato
 *     `PRE` + chaveAcesso(50) + tipoEvento(6) = 59 chars). É o mesmo Id
 *     que apareceu em `<infPedReg Id="...">` da Rejeição original.
 *   - xMotivo: descrição do motivo da anulação — obrigatória.
 *
 * Restrição leiaute (E1835): só é permitida UMA Anulação por Rejeição.
 *
 * Restrição leiaute (E1963): a Rejeição referenciada deve existir no ADN
 * (foi previamente registrada e processada).
 */
final class EventoAnulacaoRejeicao implements EventoNfse
{
    public readonly string $chaveAcesso;
    public readonly string $idEvManifRej;
    public readonly string $xMotivo;

    public function __construct(
        string $chaveAcesso,
        string $idEvManifRej,
        string $xMotivo,
        public readonly int $sequencial = 1,
    ) {
        $errors = [];

        $clean = preg_replace('/\D/', '', $chaveAcesso) ?? '';
        if (strlen($clean) !== 50) {
            $errors[] = "Chave de acesso inválida: esperado 50 dígitos (recebeu '{$chaveAcesso}')";
        }
        $this->chaveAcesso = $clean;

        $idLimpo = trim($idEvManifRej);
        // Id segue padrão `PRE` + 50 dígitos chave + 6 dígitos tipoEvento = 59 chars
        if (!preg_match('/^PRE\d{56}$/', $idLimpo)) {
            $errors[] = "idEvManifRej inválido: esperado formato 'PRE' + 50 dígitos chave + 6 dígitos tipoEvento (59 chars total). Recebido: '{$idEvManifRej}'";
        }
        $this->idEvManifRej = $idLimpo;

        $motivo = trim($xMotivo);
        if (mb_strlen($motivo) < 15 || mb_strlen($motivo) > 200) {
            $errors[] = "xMotivo deve ter entre 15 e 200 caracteres (atual: " . mb_strlen($motivo) . ')';
        }
        $this->xMotivo = $motivo;

        if ($sequencial < 1 || $sequencial > 99) {
            $errors[] = "Sequencial fora de [1..99]: {$sequencial}";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'EventoAnulacaoRejeicao inválido');
        }
    }

    public function chaveAcesso(): string
    {
        return $this->chaveAcesso;
    }

    public function codigoTipoEvento(): string
    {
        return '205208';
    }

    public function nSequencial(): int
    {
        return $this->sequencial;
    }

    public function descricao(): string
    {
        return 'Anulação da Rejeição';
    }

    /**
     * @return array<string, string>
     */
    public function camposGrupo(): array
    {
        return [
            'idEvManifRej' => $this->idEvManifRej,
            'xMotivo' => $this->xMotivo,
        ];
    }
}
