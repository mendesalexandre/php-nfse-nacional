<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

/**
 * Constantes de layout pro DANFSE conforme NT 008/2026.
 *
 * Documento A4 retrato (210 × 297 mm) com margem 10mm.
 * Blocos posicionados em mm a partir do topo.
 *
 * Estrutura visual (ordem de cima pra baixo):
 *   1. Cabeçalho (município + brasão + identificação NFS-e + QR Code)
 *   2. Prestador
 *   3. Tomador
 *   4. Discriminação dos serviços
 *   5. Valores (vServ, vBC, alíquota, ISSQN, vLiq)
 *   6. Tributos federais/municipais (totTrib)
 *   7. Tarja "CANCELADA" (diagonal) se status=101/102
 *   8. Rodapé (verificação + chave + URL portal)
 */
final class DanfseLayout
{
    // Dimensões da página
    public const PAGE_WIDTH_MM = 210.0;
    public const PAGE_HEIGHT_MM = 297.0;
    public const MARGIN_MM = 10.0;
    public const CONTENT_WIDTH_MM = self::PAGE_WIDTH_MM - 2 * self::MARGIN_MM; // 190

    // Cores (RGB)
    public const COR_TEXTO = [0, 0, 0];
    public const COR_LABEL = [100, 100, 100];
    public const COR_BORDA = [50, 50, 50];
    public const COR_TARJA_CANCELADA = [200, 50, 50];

    // Fontes
    public const FONTE_NORMAL = 'helvetica';
    public const TAM_TITULO = 11;
    public const TAM_LABEL = 6;
    public const TAM_TEXTO = 8;
    public const TAM_DESTAQUE = 9;

    // Alturas dos blocos (referência — ajustar conforme conteúdo real)
    public const ALTURA_CABECALHO_MM = 30.0;
    public const ALTURA_LINHA_PADRAO_MM = 4.5;
    public const ALTURA_QR_CODE_MM = 25.0;

    /**
     * Labels traduzidos dos códigos de regime especial de tributação.
     * @return array<int, string>
     */
    public static function regimesEspeciais(): array
    {
        return [
            0 => 'Nenhum',
            1 => 'Microempresa Municipal',
            2 => 'Estimativa',
            3 => 'Sociedade de Profissionais',
            4 => 'Notário ou Registrador',
            5 => 'Cooperativa',
            6 => 'MEI',
            7 => 'ME EPP Simples',
        ];
    }

    /**
     * Formata um valor monetário pra exibição: 1234.56 → "1.234,56".
     * Aceita float, int ou string numérica vinda do parser XML.
     */
    public static function formatarMoeda(mixed $valor): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '—';
        }
        return number_format((float) $valor, 2, ',', '.');
    }

    /**
     * Formata percentual: 4.00 → "4,00 %"
     */
    public static function formatarPercentual(mixed $valor): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '—';
        }
        return number_format((float) $valor, 2, ',', '.') . ' %';
    }

    /**
     * Formata CPF/CNPJ.
     */
    public static function formatarDocumento(?string $valor): string
    {
        if ($valor === null) {
            return '—';
        }
        $digitos = preg_replace('/\D/', '', $valor) ?? '';
        return match (strlen($digitos)) {
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digitos) ?? $digitos,
            14 => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digitos) ?? $digitos,
            default => $digitos ?: '—',
        };
    }

    /**
     * Formata CEP: 01310100 → "01310-100"
     */
    public static function formatarCep(?string $valor): string
    {
        if ($valor === null) {
            return '—';
        }
        $d = preg_replace('/\D/', '', $valor) ?? '';
        if (strlen($d) !== 8) {
            return $d ?: '—';
        }
        return substr($d, 0, 5) . '-' . substr($d, 5);
    }

    /**
     * Formata datetime ISO 8601 → "12/05/2026 11:09:51"
     */
    public static function formatarDataHora(?string $iso): string
    {
        if ($iso === null) {
            return '—';
        }
        try {
            $dt = new \DateTimeImmutable($iso);
            return $dt->format('d/m/Y H:i:s');
        } catch (\Exception) {
            return $iso;
        }
    }
}
