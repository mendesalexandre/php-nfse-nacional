<?php

declare(strict_types=1);

namespace PhpNfseNacional\Enums;

/**
 * Códigos de status (`cStat`) retornados pelo SEFIN Nacional e ADN.
 *
 * Cobre dois domínios:
 *
 *   1. **SEFIN Nacional** (códigos < 1000) — emissão de DPS/NFS-e via API
 *      `POST /nfse`. Inclui sucessos da emissão, validações de DPS, regras
 *      de eventos básicos (cancelamento simples).
 *
 *   2. **ADN** — Ambiente de Dados Nacional (códigos 1800-2032). Eventos
 *      avançados: manifestação de NFS-e (tomador confirma/rejeita), análise
 *      fiscal pra cancelamento, deferimento/indeferimento, bloqueio,
 *      compartilhamento via CNC.
 *
 * **Lista NÃO exaustiva.** O SEFIN tem centenas de códigos possíveis; só
 * temos enumerados os que (a) aparecem na operação normal do SDK ou
 * (b) constam no Anexo IV (CSV) do leiaute. Pra qualquer outro código,
 * `CStat::tryFrom($cStat)` retorna `null` — código inteiro continua
 * disponível em `SefinResposta::$cStat`.
 *
 * Validados empiricamente em homologação SEFIN (NFS-es #58–#66 emitidas
 * em 12-13/05/2026).
 */
enum CStat: int
{
    // ─── SEFIN Nacional — Sucesso na emissão / consulta ───
    /** NFS-e emitida com sucesso (cStat implícito quando vem o XML autorizado). */
    case Emitida                       = 100;
    /** NFS-e cancelada (estado final após evento e101101). */
    case Cancelada                     = 101;
    /** NFS-e cancelada por substituição (estado final após e105102). */
    case CanceladaPorSubstituicao      = 102;

    // ─── SEFIN Nacional — Sucesso em eventos ───
    /** Evento registrado e vinculado à NFS-e. */
    case EventoRegistrado              = 135;
    /** Cancelamento homologado pela prefeitura. */
    case CancelamentoHomologado        = 155;

    // ─── SEFIN Nacional — Idempotência ───
    /**
     * Evento de cancelamento/substituição já estava vinculado à NFS-e —
     * operação idempotente. Tratado como sucesso pelo SDK (segunda
     * tentativa não invalida a primeira).
     */
    case EventoVinculado             = 840;

    // ─── SEFIN Nacional — Erros comuns na emissão ───
    /** dhEmi futura em relação ao dhProc do servidor (clock drift). */
    case ErroDhEmiPosteriorAoProc      = 8;
    /** Data de competência (dCompet) posterior à data de emissão (dhEmi). */
    case ErroCompetPosteriorAoEmi      = 15;
    /** Convênio do município emissor não está/estava ATIVO no cadastro. */
    case ErroConvenioInativo           = 38;
    /** regimeEspecialTributacao ≠ 0 combinado com vDR > 0 — não permitido. */
    case ErroRegEspTribComDeducao      = 438;
    /** Tipo de dedução/redução não permitido pela parametrização do município. */
    case ErroDeducaoNaoPermitida       = 440;
    /** Falha no esquema XML do DF-e (validação XSD do leiaute). */
    case ErroSchemaXml                 = 1235;
    /** tpEmit=2/3 (Tomador/Intermediário) — não habilitado nesta versão da aplicação. */
    case ErroEmitenteNaoHabilitado     = 9996;
    /** Erro genérico de "falha de configuração" — geralmente evento não habilitado pra município/cenário. */
    case ErroFalhaConfiguracao         = 999;

    // ─── ADN — Eventos avançados (Manifestação, Análise Fiscal, Bloqueio) ───
    case AdnPrazoLeiauteExpirado       = 1800;
    case AdnIdDuplicadoNoAdn           = 1805;
    case AdnIdDifereDosCampos          = 1808;
    case AdnAmbGerDiferente            = 1814;
    case AdnDhRegPosteriorAoProc       = 1820;
    case AdnVersaoIncompativel1823     = 1823;
    case AdnPrazoLeiauteExpirado1825   = 1825;
    case AdnIdDifereDosCampos1829      = 1829;
    case AdnNfseInexistente            = 1831;
    case AdnManifestUnicoPorNaoEmit    = 1833;
    case AdnAnulacaoUnicaPorRejeicao   = 1835;
    case AdnAmbienteDivergente         = 1845;
    case AdnEventoIndeterminado1847    = 1847;
    case AdnCancelImpedidoPorEvento    = 1850;
    case AdnSubstImpedidoPorEvento     = 1860;
    case AdnSolicAnaliseImpedida       = 1870;
    case AdnDeferimentoSemSolic        = 1880;
    case AdnCancelDeferidoImpedido     = 1890;
    case AdnIndeferimentoSemSolic      = 1900;
    case AdnCancelIndeferidoImpedido   = 1910;
    case AdnManifestImpedida1920       = 1920;
    case AdnManifestImpedida1925       = 1925;
    case AdnManifestImpedida1930       = 1930;
    case AdnManifestImpedida1935       = 1935;
    case AdnManifestImpedida1940       = 1940;
    case AdnDescMotivoObrigatorio1944  = 1944;
    case AdnManifestImpedida1945       = 1945;
    case AdnDescMotivoObrigatorio1949  = 1949;
    case AdnManifestImpedida1950       = 1950;
    case AdnDescMotivoObrigatorio1954  = 1954;
    case AdnManifestImpedida1955       = 1955;
    case AdnRejeicaoOriginalInexist    = 1963;
    case AdnCancelImpedido1960         = 1960;
    case AdnBloqImpedido1965           = 1965;
    case AdnBloqImpedido1967           = 1967;
    case AdnDesbloqImpedido1970        = 1970;
    case AdnIdBloqueioInexistente      = 1976;
    case AdnDesbloqImpedido1978        = 1978;
    case AdnAssinaturaComErro          = 2020;
    case AdnAssinaturaObrigatoria      = 2029;
    case AdnAssinaturaCertMunicipio    = 2032;

    /**
     * Mensagem oficial associada ao código (texto humano).
     */
    public function descricao(): string
    {
        return match ($this) {
            self::Emitida                       => 'NFS-e gerada com sucesso',
            self::Cancelada                     => 'NFS-e cancelada',
            self::CanceladaPorSubstituicao      => 'NFS-e cancelada por substituição',
            self::EventoRegistrado              => 'Evento registrado e vinculado a NFS-e',
            self::CancelamentoHomologado        => 'Cancelamento homologado pela prefeitura',
            self::EventoVinculado             => 'Evento já estava vinculado à NFS-e — operação idempotente',
            self::ErroDhEmiPosteriorAoProc      => 'Data de emissão posterior ao processamento',
            self::ErroCompetPosteriorAoEmi      => 'Data de competência posterior à data de emissão',
            self::ErroConvenioInativo           => 'Convênio do município emissor não está ATIVO',
            self::ErroRegEspTribComDeducao      => 'Regime especial de tributação ≠ 0 combinado com dedução não é permitido',
            self::ErroDeducaoNaoPermitida       => 'Tipo de dedução/redução não permitida pela parametrização do município',
            self::ErroSchemaXml                 => 'Falha no esquema XML do DF-e',
            self::ErroEmitenteNaoHabilitado     => 'Emissão pelo tomador ou intermediário não permitida nesta versão da aplicação',
            self::ErroFalhaConfiguracao         => 'Falha de configuração — geralmente o evento não está habilitado pro município ou parametrização da operação',
            self::AdnPrazoLeiauteExpirado       => 'Prazo de aceitação da versão do leiaute da NFS-e expirou',
            self::AdnIdDuplicadoNoAdn           => 'Já existe um DF-e identificado com este id no ADN',
            self::AdnIdDifereDosCampos,
            self::AdnIdDifereDosCampos1829      => 'Conteúdo do identificador difere da concatenação dos campos correspondentes',
            self::AdnAmbGerDiferente            => 'Ambiente gerador do evento ≠ 1 (sistema próprio do município)',
            self::AdnDhRegPosteriorAoProc       => 'Data/hora do registro do evento é posterior ao processamento do documento',
            self::AdnVersaoIncompativel1823     => 'Versão incompatível',
            self::AdnPrazoLeiauteExpirado1825   => 'Prazo de aceitação da versão do leiaute expirou',
            self::AdnNfseInexistente            => 'NFS-e indicada não existe no ADN',
            self::AdnManifestUnicoPorNaoEmit    => 'Permitido um único evento de Manifestação (Confirmação/Rejeição) por não-emitente',
            self::AdnAnulacaoUnicaPorRejeicao   => 'Permitida uma única Anulação de Rejeição por Evento de Manifestação - Rejeição',
            self::AdnAmbienteDivergente         => 'Ambiente informado diverge do ambiente de recebimento do emitente',
            self::AdnEventoIndeterminado1847    => 'Evento indeterminado',
            self::AdnCancelImpedidoPorEvento    => 'Cancelamento de NFS-e impedido por evento prévio vinculado à NFS-e',
            self::AdnSubstImpedidoPorEvento     => 'Cancelamento por Substituição impedido por evento prévio',
            self::AdnSolicAnaliseImpedida       => 'Solicitação de Análise Fiscal pra Cancelamento impedida por evento prévio',
            self::AdnDeferimentoSemSolic        => 'Deferimento sem solicitação de Análise Fiscal pendente',
            self::AdnCancelDeferidoImpedido     => 'Cancelamento Deferido impedido por evento prévio',
            self::AdnIndeferimentoSemSolic      => 'Indeferimento sem solicitação de Análise Fiscal pendente',
            self::AdnCancelIndeferidoImpedido   => 'Cancelamento Indeferido impedido por evento prévio',
            self::AdnManifestImpedida1920,
            self::AdnManifestImpedida1925,
            self::AdnManifestImpedida1930,
            self::AdnManifestImpedida1935,
            self::AdnManifestImpedida1940,
            self::AdnManifestImpedida1945,
            self::AdnManifestImpedida1950,
            self::AdnManifestImpedida1955       => 'Evento de Manifestação impedido por evento prévio vinculado',
            self::AdnDescMotivoObrigatorio1944,
            self::AdnDescMotivoObrigatorio1949,
            self::AdnDescMotivoObrigatorio1954  => 'Descrição do motivo obrigatória quando tipo = 9 (Outros)',
            self::AdnRejeicaoOriginalInexist    => 'Evento de Manifestação - Rejeição a ser anulado não existe no ADN',
            self::AdnCancelImpedido1960         => 'Cancelamento impedido por evento prévio',
            self::AdnBloqImpedido1965,
            self::AdnBloqImpedido1967           => 'Evento Bloqueio impedido por evento prévio',
            self::AdnDesbloqImpedido1970,
            self::AdnDesbloqImpedido1978        => 'Evento Desbloqueio impedido por evento prévio',
            self::AdnIdBloqueioInexistente      => 'Identificador de bloqueio informado não existe',
            self::AdnAssinaturaComErro          => 'Arquivo enviado com erro na assinatura',
            self::AdnAssinaturaObrigatoria      => 'Assinatura obrigatória quando enviado pra API',
            self::AdnAssinaturaCertMunicipio    => 'Assinatura deve ser feita com certificado digital do município emissor do evento',
        };
    }

    /**
     * Sucesso da operação (emissão, cancelamento, evento aceito).
     */
    public function ehSucesso(): bool
    {
        return in_array($this, [
            self::Emitida,
            self::Cancelada,
            self::CanceladaPorSubstituicao,
            self::EventoRegistrado,
            self::CancelamentoHomologado,
            self::EventoVinculado,
        ], true);
    }

    /**
     * Erro originado no SEFIN Nacional (validação de DPS, regras de emissão,
     * regras de eventos básicos, schema XSD). Inclui também o 9996 que é
     * regra de habilitação do emissor.
     */
    public function ehErroSefin(): bool
    {
        return !$this->ehSucesso() && !$this->ehErroAdn();
    }

    /**
     * Erro originado no ADN (eventos avançados — manifestação, análise fiscal,
     * bloqueio, compartilhamento). Códigos ≥ 1800 e < 3000 (faixa reservada
     * pra ADN no leiaute).
     */
    public function ehErroAdn(): bool
    {
        return $this->value >= 1800 && $this->value < 3000;
    }

    /**
     * Erro de schema (XSD failed). Geralmente indica DPS malformada.
     */
    public function ehErroSchema(): bool
    {
        return $this === self::ErroSchemaXml;
    }

    /**
     * Códigos aceitos como sucesso de evento (cancelamento, substituição):
     * inclui Emitida (resposta com envelope GZipB64), eventos registrados,
     * e o idempotente 840.
     *
     * Use com `in_array($cStat, CStat::aceitosEvento(), true)`.
     *
     * @return list<int>
     */
    public static function aceitosEvento(): array
    {
        return [
            self::Emitida->value,                  // 100
            self::EventoRegistrado->value,         // 135
            self::CancelamentoHomologado->value,   // 155
            self::EventoVinculado->value,        // 840
        ];
    }

    /**
     * Estados terminais de cancelamento (consulta da NFS-e mostra esses cStats).
     *
     * @return list<int>
     */
    public static function estadosCancelada(): array
    {
        return [
            self::Cancelada->value,                // 101
            self::CanceladaPorSubstituicao->value, // 102
            self::EventoRegistrado->value,         // 135
            self::CancelamentoHomologado->value,   // 155
        ];
    }
}
