<?php

declare(strict_types=1);

namespace PhpNfseNacional\Danfse;

use DOMDocument;
use DOMNode;
use DOMXPath;
use PhpNfseNacional\Exceptions\NfseException;

/**
 * Parser do XML da NFS-e (retorno do Portal Nacional com infNFSe completo
 * + DPS embutido + assinaturas).
 *
 * Estrutura esperada:
 *   <NFSe versao="1.01" xmlns="http://www.sped.fazenda.gov.br/nfse">
 *     <infNFSe Id="NFS{50 dígitos}">
 *       <xLocEmi>Município Emissor</xLocEmi>
 *       <nNFSe>148</nNFSe>
 *       <cStat>100</cStat>
 *       <dhProc>2026-05-12T15:12:24-03:00</dhProc>
 *       <emit>...</emit>
 *       <valores>... (computado pelo SEFIN)</valores>
 *       <DPS>
 *         <infDPS>...</infDPS> (com prest, toma, serv, valores enviados por nós)
 *       </DPS>
 *     </infNFSe>
 *   </NFSe>
 *
 * Extrai os campos relevantes pra renderização do DANFSE conforme NT 008.
 * Usa DOMXPath com namespace registrado pra navegar com segurança.
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
        $servico = $this->extrairServico($xpath);
        $valores = $this->extrairValores($xpath);
        $tributos = $this->extrairTributos($xpath);

        $cStat = (int) ($identificacao['cStat'] ?? 0);
        $cancelada = in_array($cStat, [101, 102], true);

        return new DanfseDados(
            identificacao: $identificacao,
            prestador: $prestador,
            tomador: $tomador,
            servico: $servico,
            valores: $valores,
            tributos: $tributos,
            qrCodeUrl: $this->urlConsultaPortal($identificacao['chave'] ?? null),
            cancelada: $cancelada,
        );
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairIdentificacao(DOMXPath $xpath): array
    {
        $infNfseList = $xpath->query('//n:infNFSe');
        $infNfse = $infNfseList !== false ? $infNfseList->item(0) : null;
        $chave = null;
        if ($infNfse !== null && $infNfse->attributes !== null) {
            $idAttr = $infNfse->attributes->getNamedItem('Id');
            if ($idAttr !== null) {
                $chave = preg_replace('/^NFS/', '', $idAttr->nodeValue ?? '');
            }
        }

        return [
            'chave' => $chave,
            'numero' => $this->texto($xpath, '//n:infNFSe/n:nNFSe'),
            'serie' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:serie'),
            'numero_dps' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:nDPS'),
            'data_emissao' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:dhEmi'),
            'data_processamento' => $this->texto($xpath, '//n:infNFSe/n:dhProc'),
            'data_competencia' => $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:dCompet'),
            'codigo_verificacao' => $this->texto($xpath, '//n:infNFSe/n:cVerif'),
            'cStat' => $this->texto($xpath, '//n:infNFSe/n:cStat'),
            'protocolo' => $this->texto($xpath, '//n:infNFSe/n:nDFSe')
                ?? $this->texto($xpath, '//n:infNFSe/n:nProt'),
            'ambiente' => $this->texto($xpath, '//n:infNFSe/n:ambGer')
                ?? $this->texto($xpath, '//n:infNFSe/n:DPS/n:infDPS/n:tpAmb'),
            'local_emissao' => $this->texto($xpath, '//n:infNFSe/n:xLocEmi'),
            'local_prestacao' => $this->texto($xpath, '//n:infNFSe/n:xLocPrestacao'),
            'descricao_tributacao_nacional' => $this->texto($xpath, '//n:infNFSe/n:xTribNac'),
            'descricao_nbs' => $this->texto($xpath, '//n:infNFSe/n:xNBS'),
            'versao_aplicativo' => $this->texto($xpath, '//n:infNFSe/n:verAplic'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairPrestador(DOMXPath $xpath): array
    {
        // Bloco `emit` no header do NFSe + `prest` dentro do DPS
        return [
            'cnpj' => $this->texto($xpath, '//n:infNFSe/n:emit/n:CNPJ')
                ?? $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:CNPJ'),
            'inscricao_municipal' => $this->texto($xpath, '//n:infNFSe/n:emit/n:IM')
                ?? $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:IM'),
            'razao_social' => $this->texto($xpath, '//n:infNFSe/n:emit/n:xNome'),
            'logradouro' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:xLgr'),
            'numero' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:nro'),
            'complemento' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:xCpl'),
            'bairro' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:xBairro'),
            'municipio' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:cMun'),
            'uf' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:UF'),
            'cep' => $this->texto($xpath, '//n:infNFSe/n:emit/n:enderNac/n:CEP'),
            'telefone' => $this->texto($xpath, '//n:infNFSe/n:emit/n:fone'),
            'email' => $this->texto($xpath, '//n:infNFSe/n:emit/n:email'),
            'regime_especial' => $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:regTrib/n:regEspTrib'),
            'opta_simples' => $this->texto($xpath, '//n:DPS/n:infDPS/n:prest/n:regTrib/n:opSimpNac'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairTomador(DOMXPath $xpath): array
    {
        $tomaPath = '//n:DPS/n:infDPS/n:toma';
        $documento = $this->texto($xpath, $tomaPath . '/n:CNPJ')
            ?? $this->texto($xpath, $tomaPath . '/n:CPF');

        return [
            'documento' => $documento,
            'tipo_documento' => $this->texto($xpath, $tomaPath . '/n:CNPJ') !== null ? 'CNPJ' : 'CPF',
            'nome' => $this->texto($xpath, $tomaPath . '/n:xNome'),
            'email' => $this->texto($xpath, $tomaPath . '/n:email'),
            'telefone' => $this->texto($xpath, $tomaPath . '/n:fone'),
            'logradouro' => $this->texto($xpath, $tomaPath . '/n:end/n:xLgr'),
            'numero' => $this->texto($xpath, $tomaPath . '/n:end/n:nro'),
            'complemento' => $this->texto($xpath, $tomaPath . '/n:end/n:xCpl'),
            'bairro' => $this->texto($xpath, $tomaPath . '/n:end/n:xBairro'),
            'municipio' => $this->texto($xpath, $tomaPath . '/n:end/n:endNac/n:cMun'),
            'cep' => $this->texto($xpath, $tomaPath . '/n:end/n:endNac/n:CEP'),
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
            'descricao' => $this->texto($xpath, $servPath . '/n:cServ/n:xDescServ'),
            'codigo_nbs' => $this->texto($xpath, $servPath . '/n:cServ/n:cNBS'),
            'codigo_municipio_prestacao' => $this->texto($xpath, $servPath . '/n:locPrest/n:cLocPrestacao'),
        ];
    }

    /**
     * @return array<string, float|null>
     */
    private function extrairValores(DOMXPath $xpath): array
    {
        return [
            'valor_calculo_deducao_reducao' => $this->float($xpath, '//n:infNFSe/n:valores/n:vCalcDR'),
            'base_calculo_issqn' => $this->float($xpath, '//n:infNFSe/n:valores/n:vBC'),
            'aliquota_aplicada' => $this->float($xpath, '//n:infNFSe/n:valores/n:pAliqAplic'),
            'valor_issqn' => $this->float($xpath, '//n:infNFSe/n:valores/n:vISSQN'),
            'valor_liquido' => $this->float($xpath, '//n:infNFSe/n:valores/n:vLiq'),
            'valor_servicos' => $this->float($xpath, '//n:DPS/n:infDPS/n:valores/n:vServPrest/n:vServ'),
            'valor_deducoes' => $this->float($xpath, '//n:DPS/n:infDPS/n:valores/n:vDedRed/n:vDR'),
            'valor_desconto_incond' => $this->float($xpath, '//n:DPS/n:infDPS/n:valores/n:vDescCondIncond/n:vDescIncond'),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function extrairTributos(DOMXPath $xpath): array
    {
        $tribPath = '//n:DPS/n:infDPS/n:valores/n:trib';
        return [
            'trib_issqn' => $this->texto($xpath, $tribPath . '/n:tribMun/n:tribISSQN'),
            'tipo_retencao_issqn' => $this->texto($xpath, $tribPath . '/n:tribMun/n:tpRetISSQN'),
            'percentual_total_trib_federal' => $this->texto($xpath, $tribPath . '/n:totTrib/n:pTotTrib/n:pTotTribFed'),
            'percentual_total_trib_estadual' => $this->texto($xpath, $tribPath . '/n:totTrib/n:pTotTrib/n:pTotTribEst'),
            'percentual_total_trib_municipal' => $this->texto($xpath, $tribPath . '/n:totTrib/n:pTotTrib/n:pTotTribMun'),
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
     * URL pública pra consultar a NFS-e no Portal Nacional (gerada pelo QR Code).
     */
    private function urlConsultaPortal(?string $chave): ?string
    {
        if ($chave === null || strlen($chave) !== 50) {
            return null;
        }
        return 'https://www.nfse.gov.br/EmissorNacional/ConsultarNfse?chave=' . $chave;
    }
}
