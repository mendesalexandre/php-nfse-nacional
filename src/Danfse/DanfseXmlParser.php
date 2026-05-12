<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

use DOMDocument;
use DOMXPath;
use PhpNfseNacional\Exceptions\NfseException;

/**
 * Parser do XML autorizado da NFS-e (retorno do Portal Nacional SEFIN) pra
 * estrutura achatada do {@see DanfseDados}, organizada por bloco do Anexo I
 * da NT 008/2026.
 *
 * Estrutura esperada:
 *   <NFSe versao="1.01" xmlns="http://www.sped.fazenda.gov.br/nfse">
 *     <infNFSe Id="NFS{chave:50}">
 *       <xLocEmi/> <xLocPrestacao/> <nNFSe/> <cLocIncid/> <xLocIncid/>
 *       <xTribNac/> <xNBS/> <verAplic/> <ambGer/> <tpEmis/> <procEmi/>
 *       <cStat/> <dhProc/> <nDFSe/>
 *       <emit>...</emit>
 *       <valores>... (vBC, pAliqAplic, vISSQN, vLiq, vCalcDR — apurados pelo SEFIN)</valores>
 *       <IBSCBS>... (Reforma Tributária — totais IBS/CBS apurados)</IBSCBS>
 *       <DPS>
 *         <infDPS>... (DPS enviado: prest, toma, serv, valores, IBSCBS)</infDPS>
 *       </DPS>
 *     </infNFSe>
 *   </NFSe>
 *
 * Em caso de evento de cancelamento aplicado, o XML pode trazer também o
 * grupo <eventos> — não usado pelo gerador (a tarja "CANCELADA" é controlada
 * por cStat=101/102 ou pelo flag manual).
 */
final class DanfseXmlParser
{
    private const NFSE_NS = 'http://www.sped.fazenda.gov.br/nfse';

    public function parse(string $xml): DanfseDados
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        if (!@$dom->loadXML($xml)) {
            throw new NfseException('XML da NFS-e inválido');
        }

        $xpath = new DOMXPath($dom);
        $xpath->registerNamespace('n', self::NFSE_NS);

        $identificacao = $this->extrairIdentificacao($xpath);
        $prestador = $this->extrairPrestador($xpath);
        $tomador = $this->extrairTomador($xpath);
        $destinatario = $this->extrairDestinatario($xpath, $tomador);
        $intermediario = $this->extrairIntermediario($xpath);
        $servico = $this->extrairServico($xpath);
        $tributacaoMunicipal = $this->extrairTributacaoMunicipal($xpath);
        $tributacaoFederal = $this->extrairTributacaoFederal($xpath);
        $tributacaoIbsCbs = $this->extrairTributacaoIbsCbs($xpath);
        $valorTotal = $this->extrairValorTotal($xpath);
        $informacoesComplementares = $this->extrairInformacoesComplementares($xpath);

        $cStat = (int) ($identificacao['cStat'] ?? 0);
        $cancelada = in_array($cStat, [101, 102], true);

        // Ambiente: 2 = homologação ("produção restrita")
        $ambGer = (int) ($identificacao['ambiente_gerador'] ?? 0);
        $tpAmb = (int) ($identificacao['tpAmb_dps'] ?? 0);
        $homologacao = $ambGer === 2 || $tpAmb === 2;

        return new DanfseDados(
            identificacao: $identificacao,
            prestador: $prestador,
            tomador: $tomador,
            destinatario: $destinatario,
            intermediario: $intermediario,
            servico: $servico,
            tributacaoMunicipal: $tributacaoMunicipal,
            tributacaoFederal: $tributacaoFederal,
            tributacaoIbsCbs: $tributacaoIbsCbs,
            valorTotal: $valorTotal,
            informacoesComplementares: $informacoesComplementares,
            qrCodeUrl: $this->urlConsultaPortal($identificacao['chave'] ?? null),
            cancelada: $cancelada,
            substituida: false, // determinado externamente via consulta de eventos
            homologacao: $homologacao,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairIdentificacao(DOMXPath $xpath): array
    {
        $chave = null;
        $infNfseList = $xpath->query('//n:infNFSe');
        $infNFSe = $infNfseList !== false ? $infNfseList->item(0) : null;
        if ($infNFSe !== null && $infNFSe->attributes !== null) {
            $idAttr = $infNFSe->attributes->getNamedItem('Id');
            if ($idAttr !== null) {
                $chave = preg_replace('/^NFS/', '', $idAttr->nodeValue ?? '');
            }
        }

        return [
            'chave' => $chave,
            'numero' => $this->texto($xpath, '//n:infNFSe/n:nNFSe'),
            'serie' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:serie'),
            'numero_dps' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:nDPS'),
            'data_emissao_nfse' => $this->texto($xpath, '//n:infNFSe/n:dhProc'),
            'data_emissao_dps' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:dhEmi'),
            'data_processamento' => $this->texto($xpath, '//n:infNFSe/n:dhProc'),
            'data_competencia' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:dCompet'),
            'codigo_verificacao' => $this->texto($xpath, '//n:infNFSe/n:cVerif'),
            'cStat' => $this->texto($xpath, '//n:infNFSe/n:cStat'),
            'protocolo' => $this->texto($xpath, '//n:infNFSe/n:nDFSe')
                ?? $this->texto($xpath, '//n:infNFSe/n:nProt'),
            'ambiente_gerador' => $this->texto($xpath, '//n:infNFSe/n:ambGer'),
            'tpAmb_dps' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:tpAmb'),
            'tipo_emissao' => $this->texto($xpath, '//n:infNFSe/n:tpEmis'),
            'procedimento_emissao' => $this->texto($xpath, '//n:infNFSe/n:procEmi'),
            'versao_aplicativo' => $this->texto($xpath, '//n:infNFSe/n:verAplic'),
            'local_emissao' => $this->texto($xpath, '//n:infNFSe/n:xLocEmi'),
            'local_prestacao' => $this->texto($xpath, '//n:infNFSe/n:xLocPrestacao'),
            'local_incidencia' => $this->texto($xpath, '//n:infNFSe/n:xLocIncid'),
            'cod_municipio_incidencia' => $this->texto($xpath, '//n:infNFSe/n:cLocIncid'),
            'tipo_emitente' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:tpEmit'),
            'finalidade' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:IBSCBS/n:finNFSe'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairPrestador(DOMXPath $xpath): array
    {
        $opSimpNac = $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:regTrib/n:opSimpNac');
        $regEspTrib = $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:regTrib/n:regEspTrib');

        return [
            'documento' => $this->texto($xpath, '//n:infNFSe/n:emit/n:CNPJ')
                ?? $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:CNPJ')
                ?? $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:CPF'),
            'tipo_documento' => $this->texto($xpath, '//n:infNFSe/n:emit/n:CNPJ') !== null
                || $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:CNPJ') !== null
                ? 'CNPJ' : 'CPF',
            'inscricao_municipal' => $this->texto($xpath, '//n:infNFSe/n:emit/n:IM')
                ?? $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:IM'),
            'nome' => $this->texto($xpath, '//n:infNFSe/n:emit/n:xNome'),
            'telefone' => $this->texto($xpath, '//n:infNFSe/n:emit/n:fone'),
            'email' => $this->texto($xpath, '//n:infNFSe/n:emit/n:email'),
            'logradouro' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:xLgr'),
            'numero' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:nro'),
            'complemento' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:xCpl'),
            'bairro' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:xBairro'),
            'codigo_municipio' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:cMun'),
            'municipio' => $this->texto($xpath, '//n:infNFSe/n:xLocEmi'),
            'uf' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:UF'),
            'cep' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:CEP'),
            'opta_simples' => $opSimpNac,
            'regime_especial' => $regEspTrib,
            'regime_apuracao_sn' => null,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairTomador(DOMXPath $xpath): array
    {
        $tomaPath = '//n:DPS/n:infDPS/n:toma';
        $cnpj = $this->texto($xpath, $tomaPath . '/n:CNPJ');
        $cpf = $this->texto($xpath, $tomaPath . '/n:CPF');

        return [
            'documento' => $cnpj ?? $cpf,
            'tipo_documento' => $cnpj !== null ? 'CNPJ' : 'CPF',
            'inscricao_municipal' => $this->texto($xpath, $tomaPath . '/n:IM'),
            'nome' => $this->texto($xpath, $tomaPath . '/n:xNome'),
            'telefone' => $this->texto($xpath, $tomaPath . '/n:fone'),
            'email' => $this->texto($xpath, $tomaPath . '/n:email'),
            'logradouro' => $this->texto($xpath, $tomaPath . '/n:end/n:xLgr'),
            'numero' => $this->texto($xpath, $tomaPath . '/n:end/n:nro'),
            'complemento' => $this->texto($xpath, $tomaPath . '/n:end/n:xCpl'),
            'bairro' => $this->texto($xpath, $tomaPath . '/n:end/n:xBairro'),
            'codigo_municipio' => $this->texto($xpath, $tomaPath . '/n:end/n:endNac/n:cMun'),
            'municipio' => $this->texto($xpath, $tomaPath . '/n:end/n:xMun'),
            'uf' => $this->texto($xpath, $tomaPath . '/n:end/n:endNac/n:UF'),
            'cep' => $this->texto($xpath, $tomaPath . '/n:end/n:endNac/n:CEP'),
        ];
    }

    /**
     * Bloco DESTINATÁRIO DA OPERAÇÃO — fica em IBSCBS/dest no leiaute.
     * Quando ausente, retornamos os dados do tomador (a NT 008 prevê o
     * texto "O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE" — controlado
     * por {@see DanfseDados::destinatarioIgualTomador}).
     *
     * @param array<string, string|null> $tomador
     * @return array<string, string|null>
     */
    private function extrairDestinatario(DOMXPath $xpath, array $tomador): array
    {
        $destPath = '//n:DPS/n:infDPS/n:IBSCBS/n:dest';
        $cnpj = $this->texto($xpath, $destPath . '/n:CNPJ');
        $cpf = $this->texto($xpath, $destPath . '/n:CPF');

        if ($cnpj === null && $cpf === null) {
            // Sem destinatário próprio — assume tomador como destinatário
            return $tomador;
        }

        return [
            'documento' => $cnpj ?? $cpf,
            'tipo_documento' => $cnpj !== null ? 'CNPJ' : 'CPF',
            'inscricao_municipal' => null,
            'nome' => $this->texto($xpath, $destPath . '/n:xNome'),
            'telefone' => $this->texto($xpath, $destPath . '/n:fone'),
            'email' => $this->texto($xpath, $destPath . '/n:email'),
            'logradouro' => $this->texto($xpath, $destPath . '/n:end/n:xLgr'),
            'numero' => $this->texto($xpath, $destPath . '/n:end/n:nro'),
            'complemento' => $this->texto($xpath, $destPath . '/n:end/n:xCpl'),
            'bairro' => $this->texto($xpath, $destPath . '/n:end/n:xBairro'),
            'codigo_municipio' => $this->texto($xpath, $destPath . '/n:end/n:endNac/n:cMun'),
            'municipio' => null,
            'uf' => $this->texto($xpath, $destPath . '/n:end/n:endNac/n:UF'),
            'cep' => $this->texto($xpath, $destPath . '/n:end/n:endNac/n:CEP'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairIntermediario(DOMXPath $xpath): array
    {
        $intermPath = '//n:DPS/n:infDPS/n:interm';
        $cnpj = $this->texto($xpath, $intermPath . '/n:CNPJ');
        $cpf = $this->texto($xpath, $intermPath . '/n:CPF');

        return [
            'documento' => $cnpj ?? $cpf,
            'tipo_documento' => $cnpj !== null ? 'CNPJ' : 'CPF',
            'inscricao_municipal' => $this->texto($xpath, $intermPath . '/n:IM'),
            'nome' => $this->texto($xpath, $intermPath . '/n:xNome'),
            'telefone' => $this->texto($xpath, $intermPath . '/n:fone'),
            'email' => $this->texto($xpath, $intermPath . '/n:email'),
            'logradouro' => $this->texto($xpath, $intermPath . '/n:end/n:xLgr'),
            'numero' => $this->texto($xpath, $intermPath . '/n:end/n:nro'),
            'bairro' => $this->texto($xpath, $intermPath . '/n:end/n:xBairro'),
            'codigo_municipio' => $this->texto($xpath, $intermPath . '/n:end/n:endNac/n:cMun'),
            'uf' => $this->texto($xpath, $intermPath . '/n:end/n:endNac/n:UF'),
            'cep' => $this->texto($xpath, $intermPath . '/n:end/n:endNac/n:CEP'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairServico(DOMXPath $xpath): array
    {
        $servPath = '//n:DPS/n:infDPS/n:serv';
        return [
            'codigo_tributacao_nacional' => $this->texto($xpath, $servPath . '/n:cServ/n:cTribNac'),
            'descricao_tributacao_nacional' => $this->texto($xpath, '//n:infNFSe/n:xTribNac'),
            'codigo_tributacao_municipal' => $this->texto($xpath, $servPath . '/n:cServ/n:cTribMun'),
            'descricao_tributacao_municipal' => $this->texto($xpath, $servPath . '/n:cServ/n:xTribMun'),
            'codigo_nbs' => $this->texto($xpath, $servPath . '/n:cServ/n:cNBS'),
            'descricao_nbs' => $this->texto($xpath, '//n:infNFSe/n:xNBS'),
            'descricao_servico' => $this->texto($xpath, $servPath . '/n:cServ/n:xDescServ'),
            'codigo_municipio_prestacao' => $this->texto($xpath, $servPath . '/n:locPrest/n:cLocPrestacao'),
            'municipio_prestacao' => $this->texto($xpath, '//n:infNFSe/n:xLocPrestacao'),
            'pais_prestacao' => $this->texto($xpath, $servPath . '/n:locPrest/n:cPaisPrestacao'),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function extrairTributacaoMunicipal(DOMXPath $xpath): array
    {
        $tribPath = '//n:DPS/n:infDPS/n:valores/n:trib/n:tribMun';
        $valPath = '//n:DPS/n:infDPS/n:valores';
        return [
            'tipo_tributacao_codigo' => $this->texto($xpath, $tribPath . '/n:tribISSQN'),
            'tipo_imunidade' => $this->texto($xpath, $tribPath . '/n:tpImunidade'),
            'tipo_suspensao' => $this->texto($xpath, $valPath . '/n:tribMun/n:exigSusp/n:tpSusp'),
            'numero_processo_suspensao' => $this->texto($xpath, $valPath . '/n:tribMun/n:exigSusp/n:nProcesso'),
            'beneficio_municipal' => $this->texto($xpath, $valPath . '/n:tpBM'),
            'calculo_bm' => $this->float($xpath, $valPath . '/n:vCalcBM'),
            'total_deducoes_reducoes' => $this->float($xpath, $valPath . '/n:vDedRed/n:vDR'),
            'desconto_incondicionado' => $this->float($xpath, $valPath . '/n:vDescCondIncond/n:vDescIncond'),
            'base_calculo_issqn' => $this->float($xpath, '//n:infNFSe/n:valores/n:vBC'),
            'aliquota_aplicada' => $this->float($xpath, '//n:infNFSe/n:valores/n:pAliqAplic'),
            'tipo_retencao_issqn' => $this->texto($xpath, $tribPath . '/n:tpRetISSQN'),
            'issqn_apurado' => $this->float($xpath, '//n:infNFSe/n:valores/n:vISSQN'),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function extrairTributacaoFederal(DOMXPath $xpath): array
    {
        $fedPath = '//n:DPS/n:infDPS/n:valores/n:trib/n:tribFed';
        return [
            'irrf' => $this->float($xpath, $fedPath . '/n:vRetIRRF'),
            'contribuicao_previdenciaria' => $this->float($xpath, $fedPath . '/n:vRetCP'),
            'contribuicoes_sociais_retidas' => $this->float($xpath, $fedPath . '/n:vRetCSLL'),
            'pis_debito' => $this->float($xpath, $fedPath . '/n:piscofins/n:vPis'),
            'cofins_debito' => $this->float($xpath, $fedPath . '/n:piscofins/n:vCofins'),
            'descricao_contrib_sociais' => $this->texto($xpath, $fedPath . '/n:piscofins/n:tpRetPisCofins'),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function extrairTributacaoIbsCbs(DOMXPath $xpath): array
    {
        $ibsPath = '//n:infNFSe/n:IBSCBS';
        $totPath = $ibsPath . '/n:totCIBS';
        $dpsIbsPath = '//n:DPS/n:infDPS/n:IBSCBS';
        return [
            'cst' => $this->texto($xpath, $dpsIbsPath . '/n:valores/n:trib/n:gIBSCBS/n:CST'),
            'cclass_trib' => $this->texto($xpath, $dpsIbsPath . '/n:valores/n:trib/n:gIBSCBS/n:cClassTrib'),
            'cod_indicador_operacao' => $this->texto($xpath, $dpsIbsPath . '/n:cIndOp'),
            'cod_localidade_incidencia' => $this->texto($xpath, $ibsPath . '/n:cLocalidadeIncid'),
            'localidade_incidencia' => $this->texto($xpath, $ibsPath . '/n:xLocalidadeIncid'),
            'vbc_apos_exclusoes' => $this->float($xpath, $ibsPath . '/n:valores/n:vBC'),
            'p_ibs_uf' => $this->float($xpath, $ibsPath . '/n:valores/n:uf/n:pIBSUF'),
            'p_aliq_efet_uf' => $this->float($xpath, $ibsPath . '/n:valores/n:uf/n:pAliqEfetUF'),
            'p_ibs_mun' => $this->float($xpath, $ibsPath . '/n:valores/n:mun/n:pIBSMun'),
            'p_aliq_efet_mun' => $this->float($xpath, $ibsPath . '/n:valores/n:mun/n:pAliqEfetMun'),
            'p_cbs' => $this->float($xpath, $ibsPath . '/n:valores/n:fed/n:pCBS'),
            'p_aliq_efet_cbs' => $this->float($xpath, $ibsPath . '/n:valores/n:fed/n:pAliqEfetCBS'),
            'v_ibs_uf' => $this->float($xpath, $totPath . '/n:gIBS/n:gIBSUFTot/n:vIBSUF'),
            'v_ibs_mun' => $this->float($xpath, $totPath . '/n:gIBS/n:gIBSMunTot/n:vIBSMun'),
            'v_ibs_total' => $this->float($xpath, $totPath . '/n:gIBS/n:vIBSTot'),
            'v_cbs' => $this->float($xpath, $totPath . '/n:gCBS/n:vCBS'),
            'v_tot_nf_ibscbs' => $this->float($xpath, $totPath . '/n:vTotNF'),
        ];
    }

    /**
     * @return array<string, scalar|null>
     */
    private function extrairValorTotal(DOMXPath $xpath): array
    {
        $dpsValPath = '//n:DPS/n:infDPS/n:valores';
        $totPath = '//n:infNFSe/n:IBSCBS/n:totCIBS';
        $vLiq = $this->float($xpath, '//n:infNFSe/n:valores/n:vLiq');
        $vTotIbsCbs = $this->float($xpath, $totPath . '/n:vTotNF');
        return [
            'valor_servicos' => $this->float($xpath, $dpsValPath . '/n:vServPrest/n:vServ'),
            'desconto_incondicionado' => $this->float($xpath, $dpsValPath . '/n:vDescCondIncond/n:vDescIncond'),
            'desconto_condicionado' => $this->float($xpath, $dpsValPath . '/n:vDescCondIncond/n:vDescCond'),
            'total_retencoes' => $this->float($xpath, $dpsValPath . '/n:vTotalRet'),
            'valor_liquido' => $vLiq,
            'total_ibscbs' => $vTotIbsCbs,
            // valor líquido + IBS/CBS: somatório (NT 008 item 2.1.11)
            'valor_liquido_mais_ibscbs' => $vLiq !== null && $vTotIbsCbs !== null
                ? $vLiq + $vTotIbsCbs
                : $vLiq,
            'issqn_apurado' => $this->float($xpath, '//n:infNFSe/n:valores/n:vISSQN'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairInformacoesComplementares(DOMXPath $xpath): array
    {
        $infoPath = '//n:DPS/n:infDPS/n:serv/n:infoCompl';
        return [
            'informacoes_complementares' => $this->texto($xpath, $infoPath . '/n:xInfComp'),
            'chave_substituida' => $this->texto($xpath, '//n:DPS/n:infDPS/n:subst/n:chSubstda'),
            'codigo_obra' => $this->texto($xpath, '//n:DPS/n:infDPS/n:serv/n:obra/n:cObra'),
            'inscricao_imobiliaria' => $this->texto($xpath, '//n:DPS/n:infDPS/n:serv/n:obra/n:inscImobFisc'),
            'id_atividade_evento' => $this->texto($xpath, '//n:DPS/n:infDPS/n:serv/n:atvEvento/n:idAtvEvt'),
            'item_pedido' => $this->texto($xpath, '//n:DPS/n:infDPS/n:serv/n:infoCompl/n:gItemPed/n:xItemPed'),
            'numero_pedido' => $this->texto($xpath, '//n:DPS/n:infDPS/n:serv/n:infoCompl/n:xPed'),
            'outras_informacoes_atm' => $this->texto($xpath, '//n:DPS/n:infDPS/n:serv/n:infoCompl/n:xOutInf'),
        ];
    }

    private function texto(DOMXPath $xpath, string $expr): ?string
    {
        $list = $xpath->query($expr);
        $node = $list !== false ? $list->item(0) : null;
        if ($node === null) {
            return null;
        }
        $valor = trim($node->textContent);
        return $valor !== '' ? $valor : null;
    }

    private function float(DOMXPath $xpath, string $expr): ?float
    {
        $v = $this->texto($xpath, $expr);
        return $v !== null ? (float) $v : null;
    }

    /**
     * URL pública pra consulta da NFS-e no Portal Nacional (codificada no QR Code).
     * Conforme item 2.4.3 da NT 008.
     */
    private function urlConsultaPortal(?string $chave): ?string
    {
        if ($chave === null || strlen($chave) !== 50) {
            return null;
        }
        return DanfseLayout::URL_CONSULTA_PUBLICA . $chave;
    }
}
