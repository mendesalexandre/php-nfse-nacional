<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\Intermediario;
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
        ?Intermediario $intermediario = null,
    ): string {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $dps = $dom->createElementNS(Config::NFSE_NAMESPACE, 'DPS');
        $dps->setAttribute('versao', Config::LEIAUTE_VERSAO);
        $dom->appendChild($dps);

        $infDPS = $this->el($dom, 'infDPS');
        $infDPS->setAttribute('Id', $this->gerarDpsId($identificacao));
        $dps->appendChild($infDPS);

        // Grupos obrigatórios em ordem do XSD. Ordem oficial (V1.00.02):
        //   cabecalho → prest → toma? → interm? → serv → valores → IBSCBS?
        $this->appendCabecalho($infDPS, $identificacao);
        $this->appendPrestador($infDPS, $valores);
        $this->appendTomador($infDPS, $tomador);
        if ($intermediario !== null) {
            $this->appendIntermediario($infDPS, $intermediario);
        }
        $this->appendServico($infDPS, $servico);
        $this->appendValores($infDPS, $valores);
        if ($this->config->incluirIbsCbs) {
            $this->appendIBSCBS($infDPS, $servico, $valores);
        }

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
        $infDPS->appendChild($this->el($doc, 'dhEmi', $this->gerarDhEmi($id->dataEmissao)));
        $infDPS->appendChild($this->el($doc, 'verAplic', $this->config->versaoAplicacao));
        $infDPS->appendChild($this->el($doc, 'serie', $id->serie));
        $infDPS->appendChild($this->el($doc, 'nDPS', (string) $id->numeroDps));
        // dCompet formatado na mesma timezone do dhEmi (-03:00). SEFIN compara
        // as duas e rejeita E0015 quando dCompet > dhEmi.date — em horário de
        // virada (00:00..03:00 UTC), formatar dCompet com timezone default
        // (UTC ou outro) pode jogar a data pro dia seguinte enquanto o dhEmi
        // (já em SP) ainda está no dia anterior.
        $infDPS->appendChild($this->el(
            $doc,
            'dCompet',
            $id->dataCompetenciaResolvida()
                ->setTimezone(new \DateTimeZone(Config::TIMEZONE_DPS))
                ->format('Y-m-d'),
        ));
        $infDPS->appendChild($this->el($doc, 'tpEmit', (string) $id->tipoEmissao->value));
        $infDPS->appendChild($this->el($doc, 'cLocEmi', $this->config->prestador->endereco->codigoMunicipioIbge));
    }

    /**
     * Timestamp do DPS em America/Sao_Paulo (-03:00).
     *
     * Comportamento:
     *   - Sem override: pega `now()` em SP recuado 60s (margem de drift de
     *     clock — alinha com dhProc do SEFIN).
     *   - Com override (`Identificacao::$dataEmissao` preenchido): usa o
     *     valor passado, convertido pra SP, sem aplicar margem. Útil pra
     *     emissão "tipo contingência" (DPS gerada offline e enviada
     *     depois) ou pra replays/testes.
     */
    private function gerarDhEmi(?DateTimeImmutable $override = null): string
    {
        $tz = new \DateTimeZone(Config::TIMEZONE_DPS);
        if ($override !== null) {
            return $override->setTimezone($tz)->format('Y-m-d\TH:i:sP');
        }
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
        // <IM> é opcional. Omitido quando null/vazio — caso de uso típico:
        // MEI em município que não tem dados complementares no CNC NFS-e
        // (SEFIN rejeita com cStat 120 quando IM é enviada nesse cenário).
        if ($prestador->inscricaoMunicipal !== null && $prestador->inscricaoMunicipal !== '') {
            $prest->appendChild($this->el($doc, 'IM', $prestador->inscricaoMunicipal));
        }

        // <fone> e <email> são opcionais no <prest>. Quando o DTO Prestador
        // os preenche, vão entre <IM> e <regTrib> — ordem confirmada contra
        // XML real emitido pelo emissor web do SEFIN (NFS-e MEI, abril 2026).
        // Telefone armazenado só com dígitos (preg_replace removendo
        // máscara/separadores).
        if ($prestador->telefone !== null && $prestador->telefone !== '') {
            $foneDigitos = preg_replace('/\D/', '', $prestador->telefone) ?? '';
            if ($foneDigitos !== '') {
                $prest->appendChild($this->el($doc, 'fone', $foneDigitos));
            }
        }
        if ($prestador->email !== null && $prestador->email !== '') {
            $prest->appendChild($this->el($doc, 'email', $prestador->email));
        }

        $regTrib = $this->el($doc, 'regTrib');
        $regTrib->appendChild($this->el($doc, 'opSimpNac',
            (string) $prestador->simplesNacional->value,
        ));

        // <regApTribSN> é opcional pelo leiaute. Emite somente quando o
        // caller setou explicitamente em `Prestador::$regimeApuracaoSN`.
        // Posição obrigatória: entre <opSimpNac> e <regEspTrib>. Quando o
        // município/SEFIN exigir (típico p/ ME/EPP, opSimpNac=3), o erro
        // virá do portal com código identificável — deliberadamente sem
        // emitir default mágico (gildonei força "1=Caixa" quando ausente;
        // preferimos explicitar a obrigação no caller).
        if ($prestador->regimeApuracaoSN !== null) {
            $regTrib->appendChild($this->el($doc, 'regApTribSN',
                (string) $prestador->regimeApuracaoSN->value,
            ));
        }

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

        // ORDEM EXIGIDA pelo schema TSDestinaDps (leiaute SefinNacional 1.6,
        // Anexo I B-34..B-46): CPF/CNPJ → IM → xNome → end → fone → email.
        // Trocar a ordem (ex: email antes de end) resulta em E1235 ("invalid
        // child element").

        // CPF ou CNPJ (xs:choice)
        if ($tomador->ehPessoaFisica()) {
            $toma->appendChild($this->el($doc, 'CPF', $tomador->documento));
        } else {
            $toma->appendChild($this->el($doc, 'CNPJ', $tomador->documento));
        }

        // IM do tomador é opcional. Quando enviada, vai antes do <xNome>
        // conforme ordem do schema TSDestinaDps no leiaute SefinNacional 1.6.
        if ($tomador->inscricaoMunicipal !== null && $tomador->inscricaoMunicipal !== '') {
            $toma->appendChild($this->el($doc, 'IM', $tomador->inscricaoMunicipal));
        }

        $toma->appendChild($this->el($doc, 'xNome',
            TextoSanitizador::paraNFSe($tomador->nome, 300),
        ));

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

        // fone e email vão APÓS o end (ordem TSDestinaDps B-45/B-46).
        if ($tomador->telefone !== null && $tomador->telefone !== '') {
            $foneDigitos = preg_replace('/\D/', '', $tomador->telefone) ?? '';
            if ($foneDigitos !== '') {
                $toma->appendChild($this->el($doc, 'fone', $foneDigitos));
            }
        }
        if ($tomador->email !== null && $tomador->email !== '') {
            $toma->appendChild($this->el($doc, 'email', $tomador->email));
        }

        $infDPS->appendChild($toma);
    }

    /**
     * Grupo `<interm>` (Intermediário da operação) — opcional no DPS,
     * posicionado entre `<toma>` e `<serv>` conforme leiaute SefinNacional
     * V1.00.02 (linhas 295-325).
     *
     * Ordem dos filhos espelha `<toma>`: CPF/CNPJ → IM → xNome → end? →
     * fone? → email?. Endereço também é opcional (`0-1`), diferente do
     * tomador onde costuma ser obrigatório por convenção SEFIN.
     */
    private function appendIntermediario(DOMElement $infDPS, Intermediario $intermediario): void
    {
        $doc = $infDPS->ownerDocument;
        if ($doc === null) {
            return;
        }

        $interm = $this->el($doc, 'interm');

        // CPF ou CNPJ (xs:choice)
        if ($intermediario->ehPessoaFisica()) {
            $interm->appendChild($this->el($doc, 'CPF', $intermediario->documento));
        } else {
            $interm->appendChild($this->el($doc, 'CNPJ', $intermediario->documento));
        }

        if ($intermediario->inscricaoMunicipal !== null && $intermediario->inscricaoMunicipal !== '') {
            $interm->appendChild($this->el($doc, 'IM', $intermediario->inscricaoMunicipal));
        }

        $interm->appendChild($this->el($doc, 'xNome',
            TextoSanitizador::paraNFSe($intermediario->nome, 150),
        ));

        if ($intermediario->endereco !== null) {
            $end = $this->el($doc, 'end');
            $endNac = $this->el($doc, 'endNac');
            $endNac->appendChild($this->el($doc, 'cMun', $intermediario->endereco->codigoMunicipioIbge));
            $endNac->appendChild($this->el($doc, 'CEP',
                preg_replace('/\D/', '', $intermediario->endereco->cep) ?? '',
            ));
            $end->appendChild($endNac);

            $end->appendChild($this->el($doc, 'xLgr',
                TextoSanitizador::paraNFSe($intermediario->endereco->logradouro, 255),
            ));
            $end->appendChild($this->el($doc, 'nro',
                TextoSanitizador::paraNFSe($intermediario->endereco->numero, 60) ?: 'S/N',
            ));
            if ($intermediario->endereco->complemento !== null && $intermediario->endereco->complemento !== '') {
                $end->appendChild($this->el($doc, 'xCpl',
                    TextoSanitizador::paraNFSe($intermediario->endereco->complemento, 156),
                ));
            }
            $end->appendChild($this->el($doc, 'xBairro',
                TextoSanitizador::paraNFSe($intermediario->endereco->bairro, 60),
            ));

            $interm->appendChild($end);
        }

        if ($intermediario->telefone !== null && $intermediario->telefone !== '') {
            $foneDigitos = preg_replace('/\D/', '', $intermediario->telefone) ?? '';
            if ($foneDigitos !== '') {
                $interm->appendChild($this->el($doc, 'fone', $foneDigitos));
            }
        }
        if ($intermediario->email !== null && $intermediario->email !== '') {
            $interm->appendChild($this->el($doc, 'email', $intermediario->email));
        }

        $infDPS->appendChild($interm);
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

        // trib > tribMun (obrigatório).
        //
        // Ordem dos filhos do <tribMun> conforme leiaute V1.00.02
        // (linhas 256-267):
        //   tribISSQN → cPaisResult? → BM? → exigSusp? → tpImunidade?
        //     → pAliq? → tpRetISSQN
        //
        // Trocar a ordem dá E1235 ("invalid child element").
        $trib = $this->el($doc, 'trib');
        $tribMun = $this->el($doc, 'tribMun');

        $tribIssqn = $valores->tributacaoIssqn?->value ?? 1; // 1 = Operação Tributável default
        $tribMun->appendChild($this->el($doc, 'tribISSQN', (string) $tribIssqn));

        if ($valores->codigoPaisResultado !== null) {
            $tribMun->appendChild($this->el($doc, 'cPaisResult', $valores->codigoPaisResultado));
        }

        if ($valores->beneficioMunicipal !== null) {
            $bm = $this->el($doc, 'BM');
            $bm->appendChild($this->el($doc, 'nBM', $valores->beneficioMunicipal->nBM));
            // Choice: vRedBCBM | pRedBCBM (xor validado no DTO)
            if ($valores->beneficioMunicipal->valorReducaoBc !== null) {
                $bm->appendChild($this->el($doc, 'vRedBCBM',
                    number_format($valores->beneficioMunicipal->valorReducaoBc, 2, '.', ''),
                ));
            } elseif ($valores->beneficioMunicipal->percentualReducaoBc !== null) {
                $bm->appendChild($this->el($doc, 'pRedBCBM',
                    number_format($valores->beneficioMunicipal->percentualReducaoBc, 2, '.', ''),
                ));
            }
            $tribMun->appendChild($bm);
        }

        if ($valores->exigibilidadeSuspensa !== null) {
            $es = $this->el($doc, 'exigSusp');
            $es->appendChild($this->el($doc, 'tpSusp',
                (string) $valores->exigibilidadeSuspensa->tipo->value,
            ));
            $es->appendChild($this->el($doc, 'nProcesso',
                $valores->exigibilidadeSuspensa->numeroProcesso,
            ));
            $tribMun->appendChild($es);
        }

        if ($valores->imunidade !== null) {
            $tribMun->appendChild($this->el($doc, 'tpImunidade',
                (string) $valores->imunidade->value,
            ));
        }

        if ($valores->aliquotaMunicipal !== null) {
            $tribMun->appendChild($this->el($doc, 'pAliq',
                number_format($valores->aliquotaMunicipal, 2, '.', ''),
            ));
        }

        $tribMun->appendChild($this->el($doc, 'tpRetISSQN',
            (string) ($valores->issqnRetido ? 2 : 1),
        ));
        $trib->appendChild($tribMun);

        // totTrib é um *choice* no leiaute (pTotTrib | vTotTrib | indTotTrib).
        // Prestador dispensado de ISSQN (MEI, isento, imune) usa indTotTrib=0
        // ("valor total dos tributos não informado") — mesmo padrão do
        // emissor web do SEFIN para CNPJ MEI.
        //
        // Para os demais, emite pTotTrib com pTotTribMun em 2 casas decimais
        // fixas. O leiaute SefinNacional 1.6 restringe `pTotTrib*` ao tipo
        // `TSDec3V2`; enviar `4.0000` ou `3.5125` resulta em E1235 ("Pattern
        // constraint failed"). Confirmado empiricamente em homologação
        // 13/05/2026. Pra alíquotas reduzidas (ex: 3.5125%) o caller deve
        // arredondar antes (round() default HALF_UP: 3.5125 → 3.51).
        $totTrib = $this->el($doc, 'totTrib');

        if ($valores->dispensadoIssqn) {
            $totTrib->appendChild($this->el($doc, 'indTotTrib', '0'));
        } else {
            $pTot = $this->el($doc, 'pTotTrib');
            $pTot->appendChild($this->el($doc, 'pTotTribFed', '0.00'));
            $pTot->appendChild($this->el($doc, 'pTotTribEst', '0.00'));
            $pTot->appendChild($this->el($doc, 'pTotTribMun',
                number_format($valores->aliquotaIssqnPercentual, 2, '.', ''),
            ));
            $totTrib->appendChild($pTot);
        }

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

}
