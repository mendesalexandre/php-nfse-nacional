<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

/**
 * Constantes de layout pro DANFSe conforme NT 008/2026 (SE/CGNFS-e v1.0).
 *
 * Documento A4 retrato (210 × 297 mm). Todas as coordenadas em centímetros
 * batem com a tabela 2.4.5 da NT 008. Layout é absoluto (não fluido) —
 * blocos têm posição fixa pra garantir conformidade visual com o Anexo I.
 *
 * Ordem dos blocos (Anexo I):
 *   1. Cabeçalho  (logo NFSe + título + município + ambiente + QR Code)
 *   2. DADOS DA NFS-e (chave, número, datas, situação)
 *   3. PRESTADOR / FORNECEDOR
 *   4. TOMADOR / ADQUIRENTE
 *   5. DESTINATÁRIO DA OPERAÇÃO
 *   6. INTERMEDIÁRIO DA OPERAÇÃO
 *   7. SERVIÇO PRESTADO
 *   8. TRIBUTAÇÃO MUNICIPAL (ISSQN)
 *   9. TRIBUTAÇÃO FEDERAL (EXCETO CBS)
 *  10. TRIBUTAÇÃO IBS / CBS
 *  11. VALOR TOTAL DA NFS-E
 *  12. INFORMAÇÕES COMPLEMENTARES
 *  13. CANHOTO (opcional)
 *
 * Marca d'água "CANCELADA"/"SUBSTITUÍDA" — diagonal, 50pt mínimo, cinza K35
 * (item 2.5.1/2.5.2 da NT 008).
 */
final class DanfseLayout
{
    // ============ Dimensões da página (A4 retrato) ============
    public const PAGE_WIDTH_MM = 210.0;
    public const PAGE_HEIGHT_MM = 297.0;

    // Margem 0,30cm conforme tabela 2.4.5 (coluna "Esq." mínima = 0.30)
    public const MARGIN_X_CM = 0.30;
    public const MARGIN_Y_CM = 0.30;
    public const CONTENT_WIDTH_CM = 20.40;  // largura útil (tabela 2.4.5)

    // ============ Cores ============
    /** Texto preto sólido K100 (NT 008 item 2.4) */
    public const COR_TEXTO = [0, 0, 0];

    /** Borda preta (linhas divisórias 0,5pt — item 2.2.3) */
    public const COR_BORDA = [0, 0, 0];

    /** Sombreamento cinza claro 5% (cabeçalho, títulos de blocos, Emitente) */
    public const COR_SOMBREAMENTO = [242, 242, 242];

    /** Tarja vermelho sólido M100/Y100 = #FF0000 (homologação "SEM VALIDADE JURÍDICA") */
    public const COR_VERMELHO_HOMOL = [255, 0, 0];

    /** Marca d'água "CANCELADA" — cinza K35 (NT 008 item 2.5.1) */
    public const COR_MARCA_AGUA = [165, 165, 165];

    // ============ Fontes (NT 008 item 2.4) ============
    // Arial pros títulos/labels, Microsoft Sans Serif pros conteúdos.
    // Como TCPDF não inclui essas fontes nativamente, usamos `helvetica`
    // (substituto métrico de Arial) e `helvetica` também pra conteúdos —
    // visualmente compatível e sem necessidade de instalar fontes externas.
    // Quem quiser as fontes originais pode instalar via tools/fonts_install.
    public const FONTE_TITULO = 'helvetica';      // ≈ Arial
    public const FONTE_CONTEUDO = 'helvetica';    // ≈ Microsoft Sans Serif

    /** Item 2.4.3 — "DANFSe v2.0" e "Documento Auxiliar da NFS-e": 9pt bold Arial */
    public const TAM_CABECALHO_TITULO = 9;

    /** Item 2.4.3 — Município no canto direito: 8pt normal */
    public const TAM_CABECALHO_MUNICIPIO = 8;

    /** Item 2.4.3 — Ambiente Gerador / Tipo de Ambiente: 6pt normal */
    public const TAM_CABECALHO_AMBIENTE = 6;

    /** Item 2.4.3 — texto abaixo do QR Code (3 linhas): 6pt normal */
    public const TAM_DESCRICAO_QR = 6;

    /** Item 2.4.1 — labels dos blocos: 7pt bold MAIÚSCULAS */
    public const TAM_LABEL_BLOCO = 7;

    /** Item 2.4.2 — labels dos campos: 6pt bold (primeira letra maiúscula) */
    public const TAM_LABEL_CAMPO = 6;

    /** Item 2.4.2 — labels do bloco IDENTIFICAÇÃO: 7pt bold MAIÚSCULAS */
    public const TAM_LABEL_IDENTIFICACAO = 7;

    /** Item 2.4.4 — conteúdo dos campos: 7pt normal */
    public const TAM_CONTEUDO = 7;

    // ============ Marca d'água (item 2.5.1 NT 008) ============
    /** Tamanho mínimo da marca d'água "CANCELADA"/"SUBSTITUÍDA": 50pt */
    public const TAM_MARCA_AGUA = 50;

    // ============ Espessura de linhas (item 2.2.3) ============
    /** Linhas divisórias dos blocos: 0,5pt */
    public const ESPESSURA_LINHA_DIVISORIA = 0.5;
    /** Borda externa da página: 1pt */
    public const ESPESSURA_LINHA_BORDA = 1.0;

    // ============ Posições/dimensões da grade ============
    // Conforme tabela 2.4.5 — coordenadas (Esq./Sup.) e tamanhos (Alt./Larg.) em cm.
    // Layout funciona como uma grade de 4 colunas:
    //   Col 1: Esq=0.30,  Larg=5.09
    //   Col 2: Esq=5.41,  Larg=5.09
    //   Col 3: Esq=10.51, Larg=5.09
    //   Col 4: Esq=15.62, Larg=5.09 (varia em alguns blocos)
    public const COL_1_X_CM = 0.30;
    public const COL_2_X_CM = 5.41;
    public const COL_3_X_CM = 10.51;
    public const COL_4_X_CM = 15.62;
    public const COL_LARGURA_CM = 5.09;

    // QR Code — item 2.4.3: mínimo 1,52 × 1,52 cm em X=17,48 / Y=1,67
    public const QR_X_CM = 17.48;
    public const QR_Y_CM = 1.67;
    public const QR_TAMANHO_CM = 1.52;

    // Posições Y das principais seções (tabela 2.4.5, coluna "Sup.")
    public const Y_CABECALHO_CM = 0.30;
    public const Y_DADOS_NFSE_CM = 1.48;
    public const Y_PRESTADOR_CM = 4.34;
    public const Y_TOMADOR_CM = 6.92;
    public const Y_DESTINATARIO_CM = 8.86;
    public const Y_INTERMEDIARIO_CM = 10.80;
    public const Y_SERVICO_CM = 12.74;
    public const Y_TRIBUTACAO_MUNICIPAL_CM = 14.43;
    public const Y_TRIBUTACAO_FEDERAL_CM = 17.02;
    public const Y_TRIBUTACAO_IBSCBS_CM = 18.32;
    public const Y_VALOR_TOTAL_CM = 20.90;
    public const Y_INFO_COMPL_CM = 22.27;
    public const Y_CANHOTO_CM = 28.10;

    // ============ URLs e textos fixos ============
    public const URL_CONSULTA_PUBLICA = 'https://www.nfse.gov.br/ConsultaPublica/?tpc=1&chave=';

    public const TEXTO_AUTENTICIDADE_QR = "A autenticidade desta NFS-e pode ser verificada\npela leitura deste código QR ou pela consulta da\nchave de acesso no portal nacional da NFS-e";

    public const TEXTO_HOMOLOGACAO = 'NFS-e SEM VALIDADE JURÍDICA';
    public const TEXTO_DANFSE_TITULO = 'DANFSe v2.0';
    public const TEXTO_DANFSE_SUBTITULO = 'Documento Auxiliar da NFS-e';

    // ============ Helpers de conversão ============
    public static function cmToMm(float $cm): float
    {
        return $cm * 10.0;
    }

    // ============ Helpers de formatação ============

    /**
     * Labels textuais dos códigos de Regime Especial de Tributação (tabela do leiaute).
     *
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
            6 => 'Microempreendedor Individual (MEI)',
            7 => 'Microempresa ou EPP (Simples Nacional)',
            8 => 'Lucro Real',
            9 => 'Lucro Presumido',
        ];
    }

    /**
     * Labels do código `opSimpNac` (Simples Nacional na Data de Competência).
     *
     * @return array<int, string>
     */
    public static function simplesNacionalLabels(): array
    {
        return [
            1 => 'Não Optante',
            2 => 'MEI',
            3 => 'ME EPP (Simples Nacional)',
        ];
    }

    /**
     * Labels do tipo de tributação do ISSQN (tribISSQN).
     *
     * @return array<int, string>
     */
    public static function tipoTributacaoIssqn(): array
    {
        return [
            1 => 'Operação Tributável',
            2 => 'Exportação de Serviço',
            3 => 'Não Incidência',
            4 => 'Imunidade',
        ];
    }

    /**
     * Labels da retenção do ISSQN (tpRetISSQN).
     *
     * @return array<int, string>
     */
    public static function tipoRetencaoIssqn(): array
    {
        return [
            1 => 'Não Retido',
            2 => 'Retido pelo Tomador',
            3 => 'Retido pelo Intermediário',
        ];
    }

    public static function formatarMoeda(mixed $valor): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '-';
        }
        return 'R$ ' . number_format((float) $valor, 2, ',', '.');
    }

    public static function formatarMoedaSemPrefixo(mixed $valor): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '-';
        }
        return number_format((float) $valor, 2, ',', '.');
    }

    public static function formatarPercentual(mixed $valor): string
    {
        if ($valor === null || $valor === '' || !is_numeric($valor)) {
            return '-';
        }
        return number_format((float) $valor, 2, ',', '.') . ' %';
    }

    public static function formatarDocumento(?string $valor): string
    {
        if ($valor === null) {
            return '-';
        }
        $digitos = preg_replace('/\D/', '', $valor) ?? '';
        return match (strlen($digitos)) {
            11 => preg_replace('/^(\d{3})(\d{3})(\d{3})(\d{2})$/', '$1.$2.$3-$4', $digitos) ?? $digitos,
            14 => preg_replace('/^(\d{2})(\d{3})(\d{3})(\d{4})(\d{2})$/', '$1.$2.$3/$4-$5', $digitos) ?? $digitos,
            default => $digitos !== '' ? $digitos : '-',
        };
    }

    public static function formatarCep(?string $valor): string
    {
        if ($valor === null) {
            return '-';
        }
        $d = preg_replace('/\D/', '', $valor) ?? '';
        if (strlen($d) !== 8) {
            return $d !== '' ? $d : '-';
        }
        return substr($d, 0, 5) . '-' . substr($d, 5);
    }

    public static function formatarTelefone(?string $valor): string
    {
        if ($valor === null) {
            return '-';
        }
        $d = preg_replace('/\D/', '', $valor) ?? '';
        return match (strlen($d)) {
            10 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 4), substr($d, 6)),
            11 => sprintf('(%s) %s-%s', substr($d, 0, 2), substr($d, 2, 5), substr($d, 7)),
            default => $d !== '' ? $d : '-',
        };
    }

    public static function formatarDataHora(?string $iso): string
    {
        if ($iso === null) {
            return '-';
        }
        try {
            $dt = new \DateTimeImmutable($iso);
            return $dt->format('d/m/Y H:i:s');
        } catch (\Exception) {
            return $iso;
        }
    }

    public static function formatarData(?string $iso): string
    {
        if ($iso === null) {
            return '-';
        }
        try {
            $dt = new \DateTimeImmutable($iso);
            return $dt->format('d/m/Y');
        } catch (\Exception) {
            return $iso;
        }
    }

    /**
     * Formata chave de acesso (50 dígitos) em grupos de 4 pra leitura,
     * conforme costume das DANFSE oficiais. Mantém o conteúdo cru se não
     * tiver 50 dígitos.
     */
    public static function formatarChave(?string $chave): string
    {
        if ($chave === null) {
            return '-';
        }
        $d = preg_replace('/\D/', '', $chave) ?? '';
        if (strlen($d) !== 50) {
            return $d !== '' ? $d : '-';
        }
        return trim(chunk_split($d, 4, ' '));
    }
}
