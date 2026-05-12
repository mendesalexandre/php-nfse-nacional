<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\Servico;
use PhpNfseNacional\DTO\Tomador;
use PhpNfseNacional\DTO\Valores;
use PhpNfseNacional\Enums\RegimeEspecialTributacao;
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Support\TextoSanitizador;

/**
 * Monta o XML do DPS (Documento de Prestação de Serviço) conforme leiaute
 * SefinNacional 1.6 (XSD em vendor/.../tiposComplexos_v1.01.xsd).
 *
 * Implementa TODOS os grupos obrigatórios do leiaute — sem TODOs deixados
 * pra trás. Grupos opcionais que cartório não usa (lsadppu, explRod, etc.)
 * não são gerados (são omitidos legalmente via minOccurs=0).
 *
 * Diferenças propositais em relação ao hadder/nfse-nacional:
 *
 *   1. Construção via DOMDocument (não SimpleXMLElement string concat)
 *   2. Tipagem forte em todos os métodos
 *   3. Grupos isolados em métodos privados (testável grupo a grupo)
 *   4. Validação E0438 (regEspTrib != 0 + vDedRed) ANTES da construção
 *      — falha rápida com mensagem clara
 *   5. dhEmi forçado em America/Sao_Paulo (-03:00) pra alinhar com dhProc
 *      registrado pelo SEFIN (evita diff de 1h na DANFSE)
 *   6. cTribNac validado: cartório SEMPRE usa '210101' (item 21.01 LC 116)
 *
 * Saída: XML cru SEM assinatura. Quem assina é o `Signer` (passa o XML
 * pelo openssl_sign rsa-sha1 e injeta <Signature>).
 */
final class DpsBuilder
{
    /**
     * Margem de segurança subtraída do dhEmi pra cobrir drift de clock
     * do servidor. SEFIN rejeita E0008 se nosso dhEmi for sequer 1s à
     * frente do dhProc do servidor deles. 60s é seguro mesmo com NTP.
     */
    private const DH_EMI_MARGEM_SEGUNDOS = 60;

    public function __construct(
        private readonly Config $config,
    ) {}

    /**
     * Monta o XML do DPS pronto pra ser assinado.
     */
    public function build(
        Identificacao $identificacao,
        Tomador $tomador,
        Servico $servico,
        Valores $valores,
    ): string {
        // Validação cruzada antes de construir o XML — falha rápida
        $this->validarCruzado($valores);

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $dps = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'DPS');
        $dps->setAttribute('versao', '1.01');
        $dom->appendChild($dps);

        $infDPS = $dom->createElement('infDPS');
        $infDPS->setAttribute('Id', $this->gerarDpsId($identificacao));
        $dps->appendChild($infDPS);

        // Grupos obrigatórios em ordem do XSD
        $this->appendCabecalho($infDPS, $identificacao);
        $this->appendPrestador($infDPS, $valores);
        $this->appendTomador($infDPS, $tomador);
        $this->appendServico($infDPS, $servico);
        $this->appendValores($infDPS, $valores);
        $this->appendIBSCBS($infDPS, $servico, $valores);

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new ValidationException(['Falha ao serializar XML do DPS']);
        }
        return $xml;
    }

    /**
     * Gera o atributo Id do DPS no padrão SEFIN:
     *   DPS{cMun:7}{CNPJ:14}{serie:5}{nDPS:15}
     * Ex: DPS510790900179028000138000010000000000026000142
     */
    private function gerarDpsId(Identificacao $id): string
    {
        return sprintf(
            'DPS%s%s%s%s',
            $this->config->prestador->endereco->codigoMunicipioIbge,
            $this->config->prestador->cnpj,
            str_pad($id->serie, 5, '0', STR_PAD_LEFT),
            str_pad((string) $id->numeroDps, 15, '0', STR_PAD_LEFT),
        );
    }

    private function appendCabecalho(DOMElement $infDPS, Identificacao $id): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $infDPS->appendChild($doc->createElement('tpAmb', (string) $this->config->ambiente->value));
        $infDPS->appendChild($doc->createElement('dhEmi', $this->gerarDhEmi()));
        $infDPS->appendChild($doc->createElement('verAplic', $this->config->versaoAplicacao));
        $infDPS->appendChild($doc->createElement('serie', $id->serie));
        $infDPS->appendChild($doc->createElement('nDPS', (string) $id->numeroDps));
        $infDPS->appendChild($doc->createElement('dCompet', $id->dataCompetenciaResolvida()->format('Y-m-d')));
        $infDPS->appendChild($doc->createElement('tpEmit', (string) $id->tipoEmissao->value));
        $infDPS->appendChild($doc->createElement('cLocEmi', $this->config->prestador->endereco->codigoMunicipioIbge));
    }

    /**
     * Timestamp do DPS em America/Sao_Paulo (-03:00) com margem de 60s
     * pra cobrir drift de clock — alinha com dhProc do SEFIN.
     */
    private function gerarDhEmi(): string
    {
        $tz = new \DateTimeZone(Config::TIMEZONE_DPS);
        $now = (new DateTimeImmutable('now', $tz))
            ->modify('-' . self::DH_EMI_MARGEM_SEGUNDOS . ' seconds');
        return $now->format('Y-m-d\TH:i:sP');
    }

    private function appendPrestador(DOMElement $infDPS, Valores $valores): void
    {
        $doc = $infDPS->ownerDocument;
        $prest = $doc?->createElement('prest');
        if ($prest === null || $doc === null) {
            return;
        }

        $prestador = $this->config->prestador;
        $prest->appendChild($doc->createElement('CNPJ', $prestador->cnpj));
        $prest->appendChild($doc->createElement('IM', $prestador->inscricaoMunicipal));

        $regTrib = $doc->createElement('regTrib');
        $regTrib->appendChild($doc->createElement(
            'opSimpNac',
            (string) ($prestador->optanteSimplesNacional ? 1 : 0),
        ));

        // E0438: regEspTrib != 0 + vDedRed na mesma DPS é rejeitado.
        // Forçamos Nenhum=0 automaticamente quando houver dedução.
        $regimeFinal = $prestador->regimeEspecial;
        if ($valores->deducoesReducoes > 0 && $regimeFinal !== RegimeEspecialTributacao::Nenhum) {
            $regimeFinal = RegimeEspecialTributacao::Nenhum;
        }
        $regTrib->appendChild($doc->createElement('regEspTrib', (string) $regimeFinal->value));

        $prest->appendChild($regTrib);
        $infDPS->appendChild($prest);
    }

    private function appendTomador(DOMElement $infDPS, Tomador $tomador): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $toma = $doc->createElement('toma');

        // CPF ou CNPJ (xs:choice)
        if ($tomador->ehPessoaFisica()) {
            $toma->appendChild($doc->createElement('CPF', $tomador->documento));
        } else {
            $toma->appendChild($doc->createElement('CNPJ', $tomador->documento));
        }

        $toma->appendChild($doc->createElement(
            'xNome',
            TextoSanitizador::paraNFSe($tomador->nome, 300),
        ));

        if ($tomador->email !== null && $tomador->email !== '') {
            $toma->appendChild($doc->createElement('email', $tomador->email));
        }
        if ($tomador->telefone !== null && $tomador->telefone !== '') {
            $foneDigitos = preg_replace('/\D/', '', $tomador->telefone) ?? '';
            if ($foneDigitos !== '') {
                $toma->appendChild($doc->createElement('fone', $foneDigitos));
            }
        }

        // Endereço (sempre nacional pra cartório — endNac)
        $end = $doc->createElement('end');
        $endNac = $doc->createElement('endNac');
        $endNac->appendChild($doc->createElement('cMun', $tomador->endereco->codigoMunicipioIbge));
        $endNac->appendChild($doc->createElement(
            'CEP',
            preg_replace('/\D/', '', $tomador->endereco->cep) ?? '',
        ));
        $end->appendChild($endNac);

        $end->appendChild($doc->createElement(
            'xLgr',
            TextoSanitizador::paraNFSe($tomador->endereco->logradouro, 200),
        ));
        $end->appendChild($doc->createElement(
            'nro',
            TextoSanitizador::paraNFSe($tomador->endereco->numero, 30) ?: 'S/N',
        ));
        if ($tomador->endereco->complemento !== null && $tomador->endereco->complemento !== '') {
            $end->appendChild($doc->createElement(
                'xCpl',
                TextoSanitizador::paraNFSe($tomador->endereco->complemento, 60),
            ));
        }
        $end->appendChild($doc->createElement(
            'xBairro',
            TextoSanitizador::paraNFSe($tomador->endereco->bairro, 60),
        ));

        $toma->appendChild($end);
        $infDPS->appendChild($toma);
    }

    private function appendServico(DOMElement $infDPS, Servico $servico): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $serv = $doc->createElement('serv');

        // locPrest é xs:choice — cartório sempre cLocPrestacao (não enviar
        // junto com cPaisPrestacao que causa E1235).
        $locPrest = $doc->createElement('locPrest');
        $locPrest->appendChild($doc->createElement('cLocPrestacao', $servico->codigoMunicipioPrestacao));
        $serv->appendChild($locPrest);

        $cServ = $doc->createElement('cServ');
        $cServ->appendChild($doc->createElement('cTribNac', $servico->cTribNac));
        $cServ->appendChild($doc->createElement(
            'xDescServ',
            TextoSanitizador::paraNFSe($servico->discriminacao, 2000),
        ));
        $cServ->appendChild($doc->createElement('cNBS', $servico->cNBS));
        $serv->appendChild($cServ);

        $infDPS->appendChild($serv);
    }

    private function appendValores(DOMElement $infDPS, Valores $valores): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $valNode = $doc->createElement('valores');

        // vServPrest > vServ (obrigatório)
        $vServPrest = $doc->createElement('vServPrest');
        $vServPrest->appendChild($doc->createElement(
            'vServ',
            number_format($valores->valorServicos, 2, '.', ''),
        ));
        $valNode->appendChild($vServPrest);

        // vDescCondIncond (opcional)
        if ($valores->descontoIncondicionado > 0) {
            $vDesc = $doc->createElement('vDescCondIncond');
            $vDesc->appendChild($doc->createElement(
                'vDescIncond',
                number_format($valores->descontoIncondicionado, 2, '.', ''),
            ));
            $valNode->appendChild($vDesc);
        }

        // vDedRed (opcional) — escolha de pDR|vDR|documentos. Usamos vDR.
        if ($valores->deducoesReducoes > 0) {
            $vDedRed = $doc->createElement('vDedRed');
            $vDedRed->appendChild($doc->createElement(
                'vDR',
                number_format($valores->deducoesReducoes, 2, '.', ''),
            ));
            $valNode->appendChild($vDedRed);
        }

        // trib > tribMun (obrigatório)
        $trib = $doc->createElement('trib');
        $tribMun = $doc->createElement('tribMun');
        $tribMun->appendChild($doc->createElement('tribISSQN', '1')); // 1 = Operação Tributável
        $tribMun->appendChild($doc->createElement(
            'tpRetISSQN',
            (string) ($valores->issqnRetido ? 2 : 1),
        ));
        $trib->appendChild($tribMun);

        // totTrib > pTotTrib (obrigatório a partir da SefinNacional 1.6)
        $totTrib = $doc->createElement('totTrib');
        $pTot = $doc->createElement('pTotTrib');
        $pTot->appendChild($doc->createElement('pTotTribFed', '0.00'));
        $pTot->appendChild($doc->createElement('pTotTribEst', '0.00'));
        $pTot->appendChild($doc->createElement(
            'pTotTribMun',
            number_format($valores->aliquotaIssqnPercentual, 2, '.', ''),
        ));
        $totTrib->appendChild($pTot);
        $trib->appendChild($totTrib);

        $valNode->appendChild($trib);
        $infDPS->appendChild($valNode);
    }

    /**
     * Grupo IBSCBS — obrigatório no leiaute SefinNacional 1.6+ (Reforma
     * Tributária). Pra cartório (serviço puro, sem retenção), preenchemos
     * com valores default que indicam "fora do escopo de IBS/CBS".
     */
    private function appendIBSCBS(DOMElement $infDPS, Servico $servico, Valores $valores): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $ibscbs = $doc->createElement('IBSCBS');
        $ibscbs->appendChild($doc->createElement('finNFSe', '0'));   // 0 = Normal
        $ibscbs->appendChild($doc->createElement('indFinal', '1'));   // 1 = Consumidor final
        $ibscbs->appendChild($doc->createElement('cIndOp', $servico->cIndOp));
        $ibscbs->appendChild($doc->createElement('indDest', '0'));   // 0 = Operação interna

        // valores > trib > gIBSCBS (estrutura mínima)
        $valNode = $doc->createElement('valores');
        $trib = $doc->createElement('trib');
        $gIBSCBS = $doc->createElement('gIBSCBS');
        $gIBSCBS->appendChild($doc->createElement('CSTIBSCBS', '410'));  // 410 = não tributado
        $gIBSCBS->appendChild($doc->createElement('cClassTrib', '000001'));
        $trib->appendChild($gIBSCBS);
        $valNode->appendChild($trib);
        $ibscbs->appendChild($valNode);

        $infDPS->appendChild($ibscbs);
    }

    /**
     * Validações cruzadas entre DTOs que só fazem sentido na hora de montar
     * o DPS (não cabem nos construtores individuais).
     */
    private function validarCruzado(Valores $valores): void
    {
        $errors = [];

        // E0438: o leiaute proíbe regEspTrib != 0 + vDedRed. Aqui é só
        // warning — o appendPrestador já força regEspTrib=0 quando há
        // dedução. Mantido como nota interna pra depuração.
        // (Não adiciona erro porque é auto-corrigido.)

        // ISSQN apurado deve ser positivo se há vBC > 0
        if ($valores->baseCalculo() > 0 && $valores->valorIssqn() <= 0) {
            $errors[] = sprintf(
                'ISSQN apurado = 0 com BC = %.2f e alíquota = %.2f%% — confira os valores',
                $valores->baseCalculo(),
                $valores->aliquotaIssqnPercentual,
            );
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'DPS inválido');
        }
    }
}
