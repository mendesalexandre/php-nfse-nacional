<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Customizações opcionais aplicadas ao DANFSe local (NT 008/2026).
 *
 * Imutável. Ambos os campos são opcionais — quando ausentes, o DANFSe é
 * gerado igual ao default (logo institucional NFSe + observações vindas
 * apenas do `<xOutInf>` do XML autorizado).
 *
 * Limitações do leiaute NT 008/2026:
 *
 * - **Logo institucional NFSe NÃO pode ser substituído** — é obrigatório
 *   no cabeçalho conforme item 2.2.4 da NT. O logo do prestador customizado
 *   é renderizado no bloco PRESTADOR, ao lado do nome.
 *
 * - **Observações adicionais** são CONCATENADAS às informações
 *   complementares vindas do XML (`<xOutInf>`), separadas por uma linha
 *   em branco. Não substituem.
 */
final class DanfseCustomizacao
{
    public function __construct(
        /**
         * Path absoluto pra arquivo de imagem (PNG/JPG) do logo do prestador.
         * Renderizado no bloco PRESTADOR / FORNECEDOR. Recomendado:
         * proporção 2:1 ou 3:1 (largura:altura), fundo transparente.
         */
        public readonly ?string $logoPrestadorPath = null,

        /**
         * Texto livre adicionado ao bloco INFORMAÇÕES COMPLEMENTARES,
         * concatenado ao `<xOutInf>` do XML. Útil pra observações fixas
         * do prestador (ex: "Esta nota representa serviço cartorial.
         * Para autenticidade consulte..."). Max 2000 chars.
         */
        public readonly ?string $observacoesAdicionais = null,
    ) {
        $errors = [];

        if ($logoPrestadorPath !== null) {
            if (!is_file($logoPrestadorPath)) {
                $errors[] = "Logo do prestador não encontrado: {$logoPrestadorPath}";
            } elseif (!is_readable($logoPrestadorPath)) {
                $errors[] = "Logo do prestador sem permissão de leitura: {$logoPrestadorPath}";
            }
        }

        if ($observacoesAdicionais !== null && mb_strlen($observacoesAdicionais) > 2000) {
            $errors[] = 'observacoesAdicionais deve ter no máximo 2000 caracteres (atual: '
                . mb_strlen($observacoesAdicionais) . ')';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'DanfseCustomizacao inválida');
        }
    }

    public function temLogoPrestador(): bool
    {
        return $this->logoPrestadorPath !== null;
    }

    public function temObservacoesAdicionais(): bool
    {
        return $this->observacoesAdicionais !== null && trim($this->observacoesAdicionais) !== '';
    }
}
