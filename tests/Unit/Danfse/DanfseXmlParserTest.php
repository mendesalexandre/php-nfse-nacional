<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\Danfse;

use PhpNfseNacional\Danfse\DanfseXmlParser;
use PhpNfseNacional\Exceptions\NfseException;
use PHPUnit\Framework\TestCase;

final class DanfseXmlParserTest extends TestCase
{
    private function xmlAutorizado(): string
    {
        $path = __DIR__ . '/../../fixtures/nfse-autorizada.xml';
        $conteudo = file_get_contents($path);
        self::assertNotFalse($conteudo, "Fixture {$path} não encontrada");
        return $conteudo;
    }

    public function test_xml_invalido_lanca_excecao(): void
    {
        $this->expectException(NfseException::class);
        (new DanfseXmlParser())->parse('<nao-eh-xml-valido');
    }

    public function test_identificacao_extrai_chave_numero_e_codigo_verificacao(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertSame('35012345200001234567890123456789012345678123456789', $dados->chave());
        self::assertSame('123', $dados->numero());
        // O retorno SEFIN traz cStat=100 quando emitida
        self::assertSame('100', $dados->identificacao['cStat']);
    }

    public function test_prestador_extrai_cnpj_e_endereco(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertSame('12345678000195', $dados->prestador['documento']);
        self::assertSame('CNPJ', $dados->prestador['tipo_documento']);
        self::assertSame('12345', $dados->prestador['inscricao_municipal']);
        self::assertSame('01310100', $dados->prestador['cep']);
        self::assertSame('SP', $dados->prestador['uf']);
    }

    public function test_tomador_extrai_cpf_e_marca_tipo_documento(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertSame('12345678909', $dados->tomador['documento']);
        self::assertSame('CPF', $dados->tomador['tipo_documento']);
    }

    public function test_servico_extrai_cTribNac_210101(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        // 21.01.01 — serviços de registros públicos (item da lista LC 116/2003)
        self::assertSame('210101', $dados->servico['codigo_tributacao_nacional']);
        self::assertNotEmpty($dados->servico['descricao_servico']);
    }

    public function test_valor_total_extraido_corretamente(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        // Valores do fixture: vBC=23,08, ISSQN=0,92, vServ=32,97, vDR=9,89, aliq=4%
        // (cenário "ISSQN por dentro" — vDR inclui o ISSQN na dedução redutora)
        self::assertSame(32.97, $dados->valorTotal['valor_servicos']);
        self::assertSame(32.97, $dados->valorTotal['valor_liquido']);
        self::assertSame(0.92, $dados->valorTotal['issqn_apurado']);
    }

    public function test_reducao_aliquota_ibs_cbs_extraida_dos_campos_pRedAliq(): void
    {
        // NT 008/2026 item 2.1.10 — DANFSe deve exibir "Reduções da Alíquota
        // do IBS / Reduções da Alíquota da CBS". XSD V1.01 define os campos
        // `pRedAliqUF`, `pRedAliqMun` e `pRedAliqCBS` em
        // `infNFSe/IBSCBS/valores/{uf,mun,fed}`.
        //
        // Antes do fix, o `DanfseGenerator` hardcodava "- / -" porque o parser
        // não extraía esses campos. Este teste protege contra regressão.
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        $i = $dados->tributacaoIbsCbs;
        self::assertSame(10.00, $i['p_red_aliq_uf']);
        self::assertSame(20.00, $i['p_red_aliq_mun']);
        self::assertSame(5.00, $i['p_red_aliq_cbs']);
    }

    public function test_total_ibscbs_eh_soma_de_vIBSTot_mais_vCBS_nao_vTotNF(): void
    {
        // Regressão: o parser usava `totCIBS/vTotNF` como total do IBS/CBS,
        // mas vTotNF é o valor total da nota (igual ao vLiq), não a soma
        // dos tributos. Bug visível na DANFSe pra nota R$ 100 c/ IBS+CBS
        // de R$ 0.97: aparecia "Total IBS/CBS = R$ 100,00" e
        // "Líquido + IBS/CBS = R$ 200,00".
        //
        // XML inline com cenário típico: IBS UF 0.10 + IBS Mun 0 + CBS 0.87
        // = 0.97 total. Líquido = 100. Líquido + IBS/CBS = 100.97.
        $xml = $this->xmlComIbsCbs(vIbsTot: 0.10, vCbs: 0.87, vLiq: 100.00, vTotNF: 100.00);

        $dados = (new DanfseXmlParser())->parse($xml);

        self::assertSame(0.97, $dados->valorTotal['total_ibscbs']);
        self::assertSame(100.00, $dados->valorTotal['valor_liquido']);
        self::assertSame(100.97, $dados->valorTotal['valor_liquido_mais_ibscbs']);
    }

    private function xmlComIbsCbs(float $vIbsTot, float $vCbs, float $vLiq, float $vTotNF): string
    {
        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <NFSe versao="1.01" xmlns="http://www.sped.fazenda.gov.br/nfse">
          <infNFSe Id="NFS00000000000000000000000000000000000000000000000001">
            <nNFSe>1</nNFSe>
            <cStat>100</cStat>
            <dhProc>2026-05-12T22:11:12-03:00</dhProc>
            <verAplic>SefinNacional_1.6.0</verAplic>
            <ambGer>2</ambGer>
            <valores>
              <vBC>96.80</vBC>
              <pAliqAplic>4.00</pAliqAplic>
              <vISSQN>3.20</vISSQN>
              <vLiq>{$vLiq}</vLiq>
            </valores>
            <IBSCBS>
              <cLocalidadeIncid>3550308</cLocalidadeIncid>
              <xLocalidadeIncid>Cidade Exemplo</xLocalidadeIncid>
              <valores>
                <vBC>96.80</vBC>
                <uf><pIBSUF>0.10</pIBSUF><pAliqEfetUF>0.10</pAliqEfetUF></uf>
                <mun><pIBSMun>0.00</pIBSMun><pAliqEfetMun>0.00</pAliqEfetMun></mun>
                <fed><pCBS>0.90</pCBS><pAliqEfetCBS>0.90</pAliqEfetCBS></fed>
              </valores>
              <totCIBS>
                <vTotNF>{$vTotNF}</vTotNF>
                <gIBS>
                  <vIBSTot>{$vIbsTot}</vIBSTot>
                  <gIBSUFTot><vIBSUF>{$vIbsTot}</vIBSUF></gIBSUFTot>
                  <gIBSMunTot><vIBSMun>0.00</vIBSMun></gIBSMunTot>
                </gIBS>
                <gCBS><vCBS>{$vCbs}</vCBS></gCBS>
              </totCIBS>
            </IBSCBS>
            <DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">
              <infDPS Id="DPS00000000000000000000000000000000000000000000000001">
                <tpAmb>2</tpAmb>
                <dhEmi>2026-05-12T22:10:11-03:00</dhEmi>
                <serie>1</serie>
                <nDPS>1</nDPS>
                <dCompet>2026-05-12</dCompet>
                <prest><CNPJ>12345678000195</CNPJ><IM>12345</IM></prest>
                <toma><CPF>12345678909</CPF><xNome>X</xNome></toma>
                <serv><cServ><cTribNac>210101</cTribNac><xDescServ>X</xDescServ><cNBS>113040000</cNBS></cServ></serv>
                <valores><vServPrest><vServ>{$vLiq}</vServ></vServPrest></valores>
              </infDPS>
            </DPS>
          </infNFSe>
        </NFSe>
        XML;
    }

    public function test_total_retencoes_usa_vTotalRet_da_nfse_autorizada(): void
    {
        // Fonte primária: <vTotalRet> computado pelo SEFIN na NFS-e autorizada
        // (infNFSe/valores), entre vISSQN e vLiq. Quando presente, é usado
        // direto — sem recálculo.
        $xml = $this->xmlComRetencoes(vTotalRet: '17.15');

        $dados = (new DanfseXmlParser())->parse($xml);

        self::assertSame(17.15, $dados->valorTotal['total_retencoes']);
    }

    public function test_total_retencoes_fallback_soma_retencoes_do_dps(): void
    {
        // Quando o município não devolve <vTotalRet> (opcional, minOccurs=0),
        // o parser reconstrói pela fórmula oficial (Anexo IV linha 43):
        //   vRetCP + vRetIRRF + vRetCSLL + vISSQN* + (vPis + vCofins)**
        // ISSQN só soma se retido (tpRetISSQN 2/3); Pis/Cofins só se
        // tpRetPisCofins=1. Valores do smoke #159 (homologação):
        //   11.00 + 1.50 + 1.00 + 0.92 (ISSQN retido) + 0.65 + 3.00 = 18.07
        $xml = $this->xmlComRetencoes(vTotalRet: null);

        $dados = (new DanfseXmlParser())->parse($xml);

        self::assertSame(18.07, $dados->valorTotal['total_retencoes']);
    }

    public function test_total_retencoes_null_quando_sem_retencao(): void
    {
        // Fixture padrão não tem retenção nenhuma → campo fica null (e "-" na
        // DANFSe). Não cai no fallback porque a soma daria 0.
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertNull($dados->valorTotal['total_retencoes']);
    }

    private function xmlComRetencoes(?string $vTotalRet): string
    {
        $linhaTotalRet = $vTotalRet !== null ? "<vTotalRet>{$vTotalRet}</vTotalRet>" : '';

        return <<<XML
        <?xml version="1.0" encoding="utf-8"?>
        <NFSe versao="1.01" xmlns="http://www.sped.fazenda.gov.br/nfse">
          <infNFSe Id="NFS00000000000000000000000000000000000000000000000001">
            <nNFSe>1</nNFSe>
            <cStat>100</cStat>
            <dhProc>2026-06-23T22:11:12-03:00</dhProc>
            <verAplic>SefinNacional_1.6.0</verAplic>
            <ambGer>2</ambGer>
            <valores>
              <vBC>23.08</vBC>
              <pAliqAplic>4.00</pAliqAplic>
              <vISSQN>0.92</vISSQN>
              {$linhaTotalRet}
              <vLiq>32.97</vLiq>
            </valores>
            <DPS xmlns="http://www.sped.fazenda.gov.br/nfse" versao="1.01">
              <infDPS Id="DPS00000000000000000000000000000000000000000000000001">
                <tpAmb>2</tpAmb>
                <dhEmi>2026-06-23T22:10:11-03:00</dhEmi>
                <serie>1</serie>
                <nDPS>1</nDPS>
                <dCompet>2026-06-23</dCompet>
                <prest><CNPJ>12345678000195</CNPJ><IM>12345</IM></prest>
                <toma><CPF>12345678909</CPF><xNome>X</xNome></toma>
                <serv><cServ><cTribNac>210101</cTribNac><xDescServ>X</xDescServ><cNBS>113040000</cNBS></cServ></serv>
                <valores>
                  <vServPrest><vServ>32.97</vServ></vServPrest>
                  <trib>
                    <tribMun><tribISSQN>1</tribISSQN><tpRetISSQN>2</tpRetISSQN></tribMun>
                    <tribFed>
                      <piscofins>
                        <CST>01</CST><vPis>0.65</vPis><vCofins>3.00</vCofins><tpRetPisCofins>1</tpRetPisCofins>
                      </piscofins>
                      <vRetCP>11.00</vRetCP>
                      <vRetIRRF>1.50</vRetIRRF>
                      <vRetCSLL>1.00</vRetCSLL>
                    </tribFed>
                    <totTrib><indTotTrib>0</indTotTrib></totTrib>
                  </trib>
                </valores>
              </infDPS>
            </DPS>
          </infNFSe>
        </NFSe>
        XML;
    }

    public function test_tributacao_municipal_extraida(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());

        self::assertSame(23.08, $dados->tributacaoMunicipal['base_calculo_issqn']);
        self::assertSame(0.92, $dados->tributacaoMunicipal['issqn_apurado']);
        self::assertSame(4.0, $dados->tributacaoMunicipal['aliquota_aplicada']);
        self::assertSame(9.89, $dados->tributacaoMunicipal['total_deducoes_reducoes']);
    }

    public function test_cancelada_false_quando_cStat_100(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());
        self::assertFalse($dados->cancelada);
    }

    public function test_qr_code_url_aponta_pro_portal_nacional(): void
    {
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());
        self::assertNotNull($dados->qrCodeUrl);
        self::assertStringContainsString('nfse.gov.br', $dados->qrCodeUrl);
        self::assertStringContainsString($dados->chave() ?? 'N/A', $dados->qrCodeUrl);
    }

    public function test_homologacao_true_quando_tpAmb_2(): void
    {
        // Fixture default: ambGer=2 (Sistema Nacional) + tpAmb=2 (homologação)
        $dados = (new DanfseXmlParser())->parse($this->xmlAutorizado());
        self::assertTrue($dados->homologacao);
    }

    public function test_homologacao_false_quando_tpAmb_1_independente_de_ambGer(): void
    {
        // Regressão: ambGer=2 (Sistema Nacional) NÃO indica homologação.
        // Apenas tpAmb decide. Combinação ambGer=2 tpAmb=1 é o cenário de
        // PRODUÇÃO via Sistema Nacional (caso mais comum em produção real).
        $xmlProd = str_replace('<tpAmb>2</tpAmb>', '<tpAmb>1</tpAmb>', $this->xmlAutorizado());
        $dados = (new DanfseXmlParser())->parse($xmlProd);

        self::assertSame('2', $dados->identificacao['ambiente_gerador']);
        self::assertFalse($dados->homologacao);
    }
}
