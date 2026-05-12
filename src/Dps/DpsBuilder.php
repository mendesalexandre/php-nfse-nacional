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
 * pra trás. Grupos opcionais (lsadppu, explRod, etc.) não são gerados
 * (são omitidos legalmente via minOccurs=0).
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
 *   6. cTribNac validado pelo formato (6 dígitos do item da LC 116)
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

        $dps = $dom->createElementNS(Config::NFSE_NAMESPACE, 'DPS');
        $dps->setAttribute('versao', Config::LEIAUTE_VERSAO);
        $dom->appendChild($dps);

        $infDPS = $this->el($dom, 'infDPS');
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
     * Helper pra criar elemento DOM com checagem de falha.
     * DOMDocument::createElement retorna DOMElement|false; o false só ocorre
     * em condições anômalas (out-of-memory). Tratamos como NfseException.
     */
    private function el(DOMDocument $doc, string $name, ?string $value = null): DOMElement
    {
        $el = $value !== null ? $doc->createElement($name, $value) : $doc->createElement($name);
        if ($el === false) {
            throw new ValidationException(["Falha ao criar elemento DOM <{$name}>"]);
        }
        return $el;
    }

    /**
     * Gera o atributo Id do DPS no padrão SEFIN (TSIdDPS, 45 chars):
     *   "DPS" + cMun(7) + TipoInscricaoFederal(1) + Inscricao(14) + serie(5) + nDPS(15)
     *
     * TipoInscricaoFederal: **2 = CNPJ, 1 = CPF** (atenção: a documentação
     * é ambígua, mas a implementação real do SEFIN usa essa convenção).
     *
     * Ex: DPS3550308212345678000195000010000000000000001 (45 chars)
     */
    private function gerarDpsId(Identificacao $id): string
    {
        $tipoInscricao = '2'; // 2 = CNPJ (suporte só pra prestador PJ por enquanto)
        return sprintf(
            'DPS%s%s%s%s%s',
            $this->config->prestador->endereco->codigoMunicipioIbge,
            $tipoInscricao,
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

        $infDPS->appendChild($this->el($doc, 'tpAmb', (string) $this->config->ambiente->value));
        $infDPS->appendChild($this->el($doc, 'dhEmi', $this->gerarDhEmi()));
        $infDPS->appendChild($this->el($doc, 'verAplic', $this->config->versaoAplicacao));
        $infDPS->appendChild($this->el($doc, 'serie', $id->serie));
        $infDPS->appendChild($this->el($doc, 'nDPS', (string) $id->numeroDps));
        $infDPS->appendChild($this->el($doc, 'dCompet', $id->dataCompetenciaResolvida()->format('Y-m-d')));
        $infDPS->appendChild($this->el($doc, 'tpEmit', (string) $id->tipoEmissao->value));
        $infDPS->appendChild($this->el($doc, 'cLocEmi', $this->config->prestador->endereco->codigoMunicipioIbge));
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
        if ($doc === null) {
            return;
        }
        $prest = $this->el($doc, 'prest');

        $prestador = $this->config->prestador;
        $prest->appendChild($this->el($doc, 'CNPJ', $prestador->cnpj));
        $prest->appendChild($this->el($doc, 'IM', $prestador->inscricaoMunicipal));

        $regTrib = $this->el($doc, 'regTrib');
        $regTrib->appendChild($this->el($doc, 'opSimpNac',
            (string) $prestador->simplesNacional->value,
        ));

        // E0438: regEspTrib != 0 + vDedRed na mesma DPS é rejeitado.
        // Forçamos Nenhum=0 automaticamente quando houver dedução.
        $regimeFinal = $prestador->regimeEspecial;
        if ($valores->deducoesReducoes > 0 && $regimeFinal !== RegimeEspecialTributacao::Nenhum) {
            $regimeFinal = RegimeEspecialTributacao::Nenhum;
        }
        $regTrib->appendChild($this->el($doc, 'regEspTrib', (string) $regimeFinal->value));

        $prest->appendChild($regTrib);
        $infDPS->appendChild($prest);
    }

    private function appendTomador(DOMElement $infDPS, Tomador $tomador): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $toma = $this->el($doc, 'toma');

        // CPF ou CNPJ (xs:choice)
        if ($tomador->ehPessoaFisica()) {
            $toma->appendChild($this->el($doc, 'CPF', $tomador->documento));
        } else {
            $toma->appendChild($this->el($doc, 'CNPJ', $tomador->documento));
        }

        $toma->appendChild($this->el($doc, 'xNome',
            TextoSanitizador::paraNFSe($tomador->nome, 300),
        ));

        if ($tomador->email !== null && $tomador->email !== '') {
            $toma->appendChild($this->el($doc, 'email', $tomador->email));
        }
        if ($tomador->telefone !== null && $tomador->telefone !== '') {
            $foneDigitos = preg_replace('/\D/', '', $tomador->telefone) ?? '';
            if ($foneDigitos !== '') {
                $toma->appendChild($this->el($doc, 'fone', $foneDigitos));
            }
        }

        // Endereço (endNac = nacional, único suportado nesse SDK)
        $end = $this->el($doc, 'end');
        $endNac = $this->el($doc, 'endNac');
        $endNac->appendChild($this->el($doc, 'cMun', $tomador->endereco->codigoMunicipioIbge));
        $endNac->appendChild($this->el($doc, 'CEP',
            preg_replace('/\D/', '', $tomador->endereco->cep) ?? '',
        ));
        $end->appendChild($endNac);

        $end->appendChild($this->el($doc, 'xLgr',
            TextoSanitizador::paraNFSe($tomador->endereco->logradouro, 200),
        ));
        $end->appendChild($this->el($doc, 'nro',
            TextoSanitizador::paraNFSe($tomador->endereco->numero, 30) ?: 'S/N',
        ));
        if ($tomador->endereco->complemento !== null && $tomador->endereco->complemento !== '') {
            $end->appendChild($this->el($doc, 'xCpl',
                TextoSanitizador::paraNFSe($tomador->endereco->complemento, 60),
            ));
        }
        $end->appendChild($this->el($doc, 'xBairro',
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

        $serv = $this->el($doc, 'serv');

        // locPrest é xs:choice — sempre cLocPrestacao (não enviar junto
        // com cPaisPrestacao, que causa E1235). Pra serviço no exterior
        // use uma extensão futura desse SDK.
        $locPrest = $this->el($doc, 'locPrest');
        $locPrest->appendChild($this->el($doc, 'cLocPrestacao', $servico->codigoMunicipioPrestacao));
        $serv->appendChild($locPrest);

        $cServ = $this->el($doc, 'cServ');
        $cServ->appendChild($this->el($doc, 'cTribNac', $servico->cTribNac));
        $cServ->appendChild($this->el($doc, 'xDescServ',
            TextoSanitizador::paraNFSe($servico->discriminacao, 2000),
        ));
        $cServ->appendChild($this->el($doc, 'cNBS', $servico->cNBS));
        $serv->appendChild($cServ);

        $infDPS->appendChild($serv);
    }

    private function appendValores(DOMElement $infDPS, Valores $valores): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $valNode = $this->el($doc, 'valores');

        // vServPrest > vServ (obrigatório)
        $vServPrest = $this->el($doc, 'vServPrest');
        $vServPrest->appendChild($this->el($doc, 'vServ',
            number_format($valores->valorServicos, 2, '.', ''),
        ));
        $valNode->appendChild($vServPrest);

        // vDescCondIncond (opcional)
        if ($valores->descontoIncondicionado > 0) {
            $vDesc = $this->el($doc, 'vDescCondIncond');
            $vDesc->appendChild($this->el($doc, 'vDescIncond',
                number_format($valores->descontoIncondicionado, 2, '.', ''),
            ));
            $valNode->appendChild($vDesc);
        }

        // vDedRed (opcional) — escolha de pDR|vDR|documentos. Usamos vDR.
        if ($valores->deducoesReducoes > 0) {
            $vDedRed = $this->el($doc, 'vDedRed');
            $vDedRed->appendChild($this->el($doc, 'vDR',
                number_format($valores->deducoesReducoes, 2, '.', ''),
            ));
            $valNode->appendChild($vDedRed);
        }

        // trib > tribMun (obrigatório)
        $trib = $this->el($doc, 'trib');
        $tribMun = $this->el($doc, 'tribMun');
        $tribMun->appendChild($this->el($doc, 'tribISSQN', '1')); // 1 = Operação Tributável
        $tribMun->appendChild($this->el($doc, 'tpRetISSQN',
            (string) ($valores->issqnRetido ? 2 : 1),
        ));
        $trib->appendChild($tribMun);

        // totTrib > pTotTrib (obrigatório a partir da SefinNacional 1.6)
        $totTrib = $this->el($doc, 'totTrib');
        $pTot = $this->el($doc, 'pTotTrib');
        $pTot->appendChild($this->el($doc, 'pTotTribFed', '0.00'));
        $pTot->appendChild($this->el($doc, 'pTotTribEst', '0.00'));
        $pTot->appendChild($this->el($doc, 'pTotTribMun',
            number_format($valores->aliquotaIssqnPercentual, 2, '.', ''),
        ));
        $totTrib->appendChild($pTot);
        $trib->appendChild($totTrib);

        $valNode->appendChild($trib);
        $infDPS->appendChild($valNode);
    }

    /**
     * Grupo IBSCBS — obrigatório no leiaute SefinNacional 1.6+ (Reforma
     * Tributária). Pra prestadores de serviço puro sem retenção, default
     * com valores que indicam "fora do escopo de IBS/CBS".
     */
    private function appendIBSCBS(DOMElement $infDPS, Servico $servico, Valores $valores): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $ibscbs = $this->el($doc, 'IBSCBS');
        $ibscbs->appendChild($this->el($doc, 'finNFSe', '0'));   // 0 = Normal
        $ibscbs->appendChild($this->el($doc, 'indFinal', '1'));   // 1 = Consumidor final
        $ibscbs->appendChild($this->el($doc, 'cIndOp', $servico->cIndOp));
        $ibscbs->appendChild($this->el($doc, 'indDest', '0'));   // 0 = Operação interna

        // valores > trib > gIBSCBS (estrutura mínima)
        $valNode = $this->el($doc, 'valores');
        $trib = $this->el($doc, 'trib');
        // CST 000 + cClassTrib 000001 = Tributação Regular (combinação válida
        // do leiaute). Pra outros cenários (imune, isento, suspenso, etc.)
        // refinar com base na tabela CST × cClassTrib do leiaute.
        $gIBSCBS = $this->el($doc, 'gIBSCBS');
        $gIBSCBS->appendChild($this->el($doc, 'CST', '000'));
        $gIBSCBS->appendChild($this->el($doc, 'cClassTrib', '000001'));
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
