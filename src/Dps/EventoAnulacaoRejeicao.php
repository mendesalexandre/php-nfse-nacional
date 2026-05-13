<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Support\Documento;

/**
 * Evento de Anulação de Rejeição (e205208).
 *
 * Desfaz uma `EventoRejeicao` previamente registrada. Útil quando o autor
 * (Prestador/Tomador/Intermediário) rejeitou uma NFS-e por engano e quer
 * reverter pra estado neutro.
 *
 * Grupo `<infAnRej>` (TCInfoEventoAnulacaoRejeicao) — ORDEM IMPORTA no XSD:
 *   1. CPFAgTrib: CPF da pessoa física que efetua a anulação (11 dígitos)
 *   2. idEvManifRej: Id da Rejeição original (formato `PRE` + chaveAcesso(50)
 *      + tipoEvento(6) = 59 chars). Vem do `<infPedReg Id="...">` da
 *      Rejeição original.
 *   3. xMotivo: descrição do motivo da anulação (15-200 chars).
 *
 * **Atenção pro CPFAgTrib:** apesar do nome "AgTrib" sugerir agente
 * tributário, no leiaute SefinNacional 1.6 esse campo é o CPF do
 * RESPONSÁVEL pela anulação. Em PJ (CNPJ no certificado), use o CPF do
 * sócio/responsável legal. Em PF, o próprio CPF do certificado.
 *
 * Restrição leiaute (E1835): só é permitida UMA Anulação por Rejeição.
 *
 * Restrição leiaute (E1963): a Rejeição referenciada deve existir no ADN
 * (foi previamente registrada e processada).
 */
final class EventoAnulacaoRejeicao implements EventoNfse
{
    public readonly string $chaveAcesso;
    public readonly string $cpfAgente;
    public readonly string $idEvManifRej;
    public readonly string $xMotivo;

    public function __construct(
        string $chaveAcesso,
        string $cpfAgente,
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

        $cpfLimpo = Documento::limpar($cpfAgente);
        if (strlen($cpfLimpo) !== 11) {
            $errors[] = "CPFAgTrib inválido: esperado 11 dígitos (recebeu '{$cpfAgente}')";
        }
        $this->cpfAgente = $cpfLimpo;

        // idEvManifRej é tipo TSIdNumEvento (N, 59 chars) — numérico puro:
        //   chave(50) + tipoEvento(6) + nSeqEvento(3, zero-padded)
        // Diferente do atributo Id do <infPedReg> da Rejeição original
        // (que vem como `PRE` + 50 dígitos chave + 6 dígitos tipoEvento).
        // O SDK aceita ambos os formatos no input e converte internamente
        // pro formato esperado pelo XSD.
        $idLimpo = trim($idEvManifRej);
        if (preg_match('/^PRE(\d{56})$/', $idLimpo, $m)) {
            // Veio no formato `PRE` + 56 dígitos — adiciona nSeqEvento=001
            $idLimpo = $m[1] . '001';
        }
        if (!preg_match('/^\d{59}$/', $idLimpo)) {
            $errors[] = "idEvManifRej inválido: esperado 59 dígitos puros (chave50+tipoEvento6+nSeq3) ou prefixo PRE+56 dígitos. Recebido: '{$idEvManifRej}'";
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
        // xDesc é enumeração restrita do leiaute (TS_xDesc) — texto exato
        // exigido pelo SEFIN. Outros valores resultam em E1235.
        return 'Manifestação de NFS-e - Anulação da Rejeição';
    }

    /**
     * Ordem dos campos é exigida pelo XSD: CPFAgTrib → idEvManifRej → xMotivo.
     *
     * @return array<string, string>
     */
    public function camposGrupo(): array
    {
        return [
            'CPFAgTrib' => $this->cpfAgente,
            'idEvManifRej' => $this->idEvManifRej,
            'xMotivo' => $this->xMotivo,
        ];
    }
}
