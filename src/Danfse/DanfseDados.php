<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

/**
 * Snapshot imutável dos dados extraídos do XML da NFS-e autorizada, prontos
 * pra renderização do DANFSE conforme NT 008/2026.
 *
 * Cada array segue a estrutura visual de um bloco do Anexo I da NT.
 * Campos ausentes no XML vêm como `null` — o gerador renderiza "-" no PDF.
 */
final class DanfseDados
{
    /**
     * @param array<string, string|null> $identificacao   Bloco "DADOS DA NFS-e" (chave, número, datas, situação, finalidade)
     * @param array<string, string|null> $prestador       Bloco "PRESTADOR / FORNECEDOR"
     * @param array<string, string|null> $tomador        Bloco "TOMADOR / ADQUIRENTE"
     * @param array<string, string|null> $destinatario   Bloco "DESTINATÁRIO DA OPERAÇÃO" (geralmente igual ao tomador)
     * @param array<string, string|null> $intermediario  Bloco "INTERMEDIÁRIO DA OPERAÇÃO" (vazio na maioria dos casos)
     * @param array<string, string|null> $servico        Bloco "SERVIÇO PRESTADO" (códigos + descrição)
     * @param array<string, scalar|null> $tributacaoMunicipal Bloco "TRIBUTAÇÃO MUNICIPAL (ISSQN)"
     * @param array<string, scalar|null> $tributacaoFederal   Bloco "TRIBUTAÇÃO FEDERAL (EXCETO CBS)"
     * @param array<string, scalar|null> $tributacaoIbsCbs    Bloco "TRIBUTAÇÃO IBS / CBS"
     * @param array<string, scalar|null> $valorTotal     Bloco "VALOR TOTAL DA NFS-E"
     * @param array<string, string|null> $informacoesComplementares Bloco "INFORMAÇÕES COMPLEMENTARES"
     * @param string|null                $qrCodeUrl     URL completa pro QR Code (consulta pública)
     * @param bool                       $cancelada     true se NFS-e cancelada (cStat 101/102) — exibe marca d'água
     * @param bool                       $substituida   true se NFS-e foi substituída — marca d'água "SUBSTITUÍDA"
     * @param bool                       $homologacao   true se ambGer=2 — exibe "NFS-e SEM VALIDADE JURÍDICA"
     */
    public function __construct(
        public readonly array $identificacao,
        public readonly array $prestador,
        public readonly array $tomador,
        public readonly array $destinatario,
        public readonly array $intermediario,
        public readonly array $servico,
        public readonly array $tributacaoMunicipal,
        public readonly array $tributacaoFederal,
        public readonly array $tributacaoIbsCbs,
        public readonly array $valorTotal,
        public readonly array $informacoesComplementares,
        public readonly ?string $qrCodeUrl,
        public readonly bool $cancelada,
        public readonly bool $substituida = false,
        public readonly bool $homologacao = false,
    ) {}

    public function chave(): ?string
    {
        return $this->identificacao['chave'] ?? null;
    }

    public function numero(): ?string
    {
        return $this->identificacao['numero'] ?? null;
    }

    /**
     * O destinatário é o próprio tomador? Determina se o bloco DESTINATÁRIO
     * deve ser suprimido com o texto "O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE"
     * (item 2.3.2 da NT 008).
     */
    public function destinatarioIgualTomador(): bool
    {
        $docTom = $this->tomador['documento'] ?? null;
        $docDest = $this->destinatario['documento'] ?? null;
        return $docTom !== null && $docDest !== null && $docTom === $docDest;
    }

    /**
     * O intermediário não foi identificado na NFS-e? Determina se o bloco
     * INTERMEDIÁRIO deve ser suprimido com o texto "INTERMEDIÁRIO DA OPERAÇÃO
     * NÃO IDENTIFICADO NA NFS-e" (item 2.3.1 da NT 008).
     */
    public function semIntermediario(): bool
    {
        return ($this->intermediario['documento'] ?? null) === null;
    }

    /**
     * Operação não sujeita ao ISSQN? Determina se o bloco TRIBUTAÇÃO
     * MUNICIPAL deve ser suprimido (item 2.3.1 da NT 008).
     */
    public function operacaoNaoSujeitaIssqn(): bool
    {
        return ($this->tributacaoMunicipal['tipo_tributacao_codigo'] ?? null) === null
            && ($this->valorTotal['issqn_apurado'] ?? null) === null;
    }
}
