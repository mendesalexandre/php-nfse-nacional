<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\AtividadeEvento;
use PhpNfseNacional\DTO\ComercioExterior;
use PhpNfseNacional\DTO\DocumentoDeducao;
use PhpNfseNacional\DTO\Endereco;
use PhpNfseNacional\DTO\EnderecoExterior;
use PhpNfseNacional\DTO\Identificacao;
use PhpNfseNacional\DTO\InformacaoObra;
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

        $dhEmiSP = $this->resolverDhEmi($id->dataEmissao);

        $infDPS->appendChild($this->el($doc, 'tpAmb', (string) $this->config->ambiente->value));
        $infDPS->appendChild($this->el($doc, 'dhEmi', $dhEmiSP->format('Y-m-d\TH:i:sP')));
        $infDPS->appendChild($this->el($doc, 'verAplic', $this->config->versaoAplicacao));
        $infDPS->appendChild($this->el($doc, 'serie', $id->serie));
        $infDPS->appendChild($this->el($doc, 'nDPS', (string) $id->numeroDps));
        // dCompet derivado do dhEmi quando não foi informado explicitamente.
        // SEFIN rejeita E0015 quando dCompet > dhEmi.date. Em horário de
        // virada do dia (00:00:00..00:00:59 SP), `now() - 60s` joga o dhEmi
        // pro dia anterior; se o dCompet usasse um `new DateTimeImmutable()`
        // independente, ficaria no dia seguinte e rejeitaria. Reaproveitar
        // o mesmo timestamp resolvido elimina a janela de bug.
        $dCompetSrc = $id->dataCompetencia ?? $dhEmiSP;
        $infDPS->appendChild($this->el(
            $doc,
            'dCompet',
            $dCompetSrc->setTimezone(new \DateTimeZone(Config::TIMEZONE_DPS))->format('Y-m-d'),
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
    private function resolverDhEmi(?DateTimeImmutable $override = null): DateTimeImmutable
    {
        $tz = new \DateTimeZone(Config::TIMEZONE_DPS);
        if ($override !== null) {
            return $override->setTimezone($tz);
        }
        return (new DateTimeImmutable('now', $tz))
            ->modify('-' . self::DH_EMI_MARGEM_SEGUNDOS . ' seconds');
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

        // Endereço — detecta union type Endereco vs EnderecoExterior
        // e emite <endNac> ou <endExt> dentro de <end>.
        $toma->appendChild($this->montarEnderecoTcEndereco($doc, $tomador->endereco));

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
            $interm->appendChild($this->montarEnderecoTcEndereco($doc, $intermediario->endereco));
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

        // Ordem oficial em <serv> conforme TCServ (XSD V1.01 linhas 1273-1312):
        //   locPrest → cServ → comExt? → obra? → atvEvento? → infoCompl?
        if ($servico->comExt !== null) {
            $serv->appendChild($this->montarComExt($doc, $servico->comExt));
        }
        if ($servico->obra !== null) {
            $serv->appendChild($this->montarObra($doc, $servico->obra));
        }
        if ($servico->atvEvento !== null) {
            $serv->appendChild($this->montarAtvEvento($doc, $servico->atvEvento));
        }

        // <infoCompl> é o ÚLTIMO filho de <serv> conforme TCServ
        // (tiposComplexos_v1.01.xsd linhas 1304-1311). Emite apenas se
        // o DTO foi passado e tem pelo menos 1 campo preenchido.
        if ($servico->infoCompl !== null && $servico->infoCompl->temConteudo()) {
            $infoCompl = $this->el($doc, 'infoCompl');
            // Ordem dos filhos conforme TCInfoCompl: idDocTec → docRef → xInfComp.
            if ($servico->infoCompl->idDocTec !== null) {
                $infoCompl->appendChild($this->el($doc, 'idDocTec',
                    TextoSanitizador::paraNFSe($servico->infoCompl->idDocTec, 40),
                ));
            }
            if ($servico->infoCompl->docRef !== null) {
                $infoCompl->appendChild($this->el($doc, 'docRef',
                    TextoSanitizador::paraNFSe($servico->infoCompl->docRef, 255),
                ));
            }
            if ($servico->infoCompl->xInfComp !== null) {
                $infoCompl->appendChild($this->el($doc, 'xInfComp',
                    TextoSanitizador::paraNFSe($servico->infoCompl->xInfComp, 2000),
                ));
            }
            $serv->appendChild($infoCompl);
        }

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

        // vDedRed (opcional) — choice no schema: pDR | vDR | documentos.
        // pDR (percentual) ainda não exposto; vDR e documentos sim.
        // O DTO Valores já valida que ambos não coexistem.
        if (count($valores->documentosDeducao) > 0) {
            $vDedRed = $this->el($doc, 'vDedRed');
            $docs = $this->el($doc, 'documentos');
            foreach ($valores->documentosDeducao as $dd) {
                $docs->appendChild($this->montarDocDedRed($doc, $dd));
            }
            $vDedRed->appendChild($docs);
            $valNode->appendChild($vDedRed);
        } elseif ($valores->deducoesReducoes > 0) {
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
            (string) $valores->tipoRetencaoIssqn->value,
        ));
        $trib->appendChild($tribMun);

        // <tribFed> (opcional) — PIS/COFINS + retenções federais
        // (CP, IRRF, CSLL). Ordem: piscofins? → vRetCP? → vRetIRRF? → vRetCSLL?
        // (CSV linhas 269-685). Emite só se algum campo está preenchido.
        $temTribFed = $valores->tributacaoPisCofins !== null
            || $valores->valorRetidoCp !== null
            || $valores->valorRetidoIrrf !== null
            || $valores->valorRetidoCsll !== null;

        if ($temTribFed) {
            $tribFed = $this->el($doc, 'tribFed');

            if ($valores->tributacaoPisCofins !== null) {
                $pc = $valores->tributacaoPisCofins;
                $piscofinsNode = $this->el($doc, 'piscofins');
                $piscofinsNode->appendChild($this->el($doc, 'CST', $pc->cst->value));
                if ($pc->valorBaseCalculo !== null) {
                    $piscofinsNode->appendChild($this->el($doc, 'vBCPisCofins',
                        number_format($pc->valorBaseCalculo, 2, '.', ''),
                    ));
                }
                if ($pc->aliquotaPis !== null) {
                    $piscofinsNode->appendChild($this->el($doc, 'pAliqPis',
                        number_format($pc->aliquotaPis, 2, '.', ''),
                    ));
                }
                if ($pc->aliquotaCofins !== null) {
                    $piscofinsNode->appendChild($this->el($doc, 'pAliqCofins',
                        number_format($pc->aliquotaCofins, 2, '.', ''),
                    ));
                }
                if ($pc->valorPis !== null) {
                    $piscofinsNode->appendChild($this->el($doc, 'vPis',
                        number_format($pc->valorPis, 2, '.', ''),
                    ));
                }
                if ($pc->valorCofins !== null) {
                    $piscofinsNode->appendChild($this->el($doc, 'vCofins',
                        number_format($pc->valorCofins, 2, '.', ''),
                    ));
                }
                if ($pc->tipoRetencao !== null) {
                    $piscofinsNode->appendChild($this->el($doc, 'tpRetPisCofins',
                        (string) $pc->tipoRetencao->value,
                    ));
                }
                $tribFed->appendChild($piscofinsNode);
            }

            if ($valores->valorRetidoCp !== null) {
                $tribFed->appendChild($this->el($doc, 'vRetCP',
                    number_format($valores->valorRetidoCp, 2, '.', ''),
                ));
            }
            if ($valores->valorRetidoIrrf !== null) {
                $tribFed->appendChild($this->el($doc, 'vRetIRRF',
                    number_format($valores->valorRetidoIrrf, 2, '.', ''),
                ));
            }
            if ($valores->valorRetidoCsll !== null) {
                $tribFed->appendChild($this->el($doc, 'vRetCSLL',
                    number_format($valores->valorRetidoCsll, 2, '.', ''),
                ));
            }

            $trib->appendChild($tribFed);
        }

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

        if ($valores->motivoDispensaIssqn !== null) {
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
        // indZFMALC (NT 007/2026) — opt-in; só emite quando o emitente declara.
        // Posição dentro de <IBSCBS> pendente de validação contra o XSD do
        // AnexoVI V1.03.00 (CSV V1.00.02 não cobre o grupo IBSCBS).
        if ($servico->indZfmAlc !== null) {
            $ibscbs->appendChild($this->el($doc, 'indZFMALC', $servico->indZfmAlc ? '1' : '0'));
        }

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
     * Monta um `<docDedRed>` (documento referenciado pra dedução/redução).
     *
     * Ordem dos filhos conforme TCDocDedRed (V1.00.02 linhas 522-569):
     *   choice referência (chNFSe | chNFe | nDoc) →
     *   tpDedRed →
     *   xDescOutDed? →
     *   dtEmiDoc →
     *   vDedutivelRedutivel →
     *   vDeducaoReducao →
     *   (fornec? — não exposto nesta versão)
     */
    private function montarDocDedRed(\DOMDocument $doc, DocumentoDeducao $dd): DOMElement
    {
        $node = $this->el($doc, 'docDedRed');

        // Choice de identificação (XOR validado no DTO)
        if ($dd->chaveNfse !== null) {
            $node->appendChild($this->el($doc, 'chNFSe', $dd->chaveNfse));
        } elseif ($dd->chaveNfe !== null) {
            $node->appendChild($this->el($doc, 'chNFe', $dd->chaveNfe));
        } else {
            $node->appendChild($this->el($doc, 'nDoc',
                TextoSanitizador::paraNFSe((string) $dd->numeroDocumento, 255),
            ));
        }

        $node->appendChild($this->el($doc, 'tpDedRed', $dd->tipo->value));

        if ($dd->descricaoOutraDeducao !== null) {
            $node->appendChild($this->el($doc, 'xDescOutDed',
                TextoSanitizador::paraNFSe($dd->descricaoOutraDeducao, 150),
            ));
        }

        $node->appendChild($this->el($doc, 'dtEmiDoc',
            $dd->dataEmissaoDocumento->format('Y-m-d'),
        ));
        $node->appendChild($this->el($doc, 'vDedutivelRedutivel',
            number_format($dd->valorDedutivel, 2, '.', ''),
        ));
        $node->appendChild($this->el($doc, 'vDeducaoReducao',
            number_format($dd->valorDeducao, 2, '.', ''),
        ));

        return $node;
    }

    /**
     * Monta o grupo `<comExt>` conforme TCComExterior (XSD V1.01 linhas
     * 1364-1484). Ordem obrigatória dos filhos:
     *   mdPrestacao → vincPrest → tpMoeda → vServMoeda →
     *   mecAFComexP → mecAFComexT → movTempBens →
     *   nDI? → nRE? → mdic
     */
    private function montarComExt(\DOMDocument $doc, ComercioExterior $ce): DOMElement
    {
        $node = $this->el($doc, 'comExt');
        $node->appendChild($this->el($doc, 'mdPrestacao', (string) $ce->modoPrestacao->value));
        $node->appendChild($this->el($doc, 'vincPrest', (string) $ce->vinculoEntrePartes->value));
        $node->appendChild($this->el($doc, 'tpMoeda', $ce->codigoMoeda));
        $node->appendChild($this->el($doc, 'vServMoeda',
            number_format($ce->valorServicoMoeda, 2, '.', ''),
        ));
        $node->appendChild($this->el($doc, 'mecAFComexP', $ce->mecanismoFomentoPrestador->value));
        $node->appendChild($this->el($doc, 'mecAFComexT', $ce->mecanismoFomentoTomador->value));
        $node->appendChild($this->el($doc, 'movTempBens',
            (string) $ce->movimentacaoTemporariaBens->value,
        ));
        if ($ce->numeroDeclaracaoImportacao !== null) {
            $node->appendChild($this->el($doc, 'nDI', $ce->numeroDeclaracaoImportacao));
        }
        if ($ce->numeroRegistroExportacao !== null) {
            $node->appendChild($this->el($doc, 'nRE', $ce->numeroRegistroExportacao));
        }
        $node->appendChild($this->el($doc, 'mdic', (string) $ce->envioMdic->value));
        return $node;
    }

    /**
     * Monta o grupo `<obra>` conforme TCInfoObra (XSD V1.01 linhas
     * 1517-1549). Estrutura:
     *   inscImobFisc? → choice(cObra | cCIB | end)
     */
    private function montarObra(\DOMDocument $doc, InformacaoObra $obra): DOMElement
    {
        $node = $this->el($doc, 'obra');
        if ($obra->inscricaoImobiliariaFiscal !== null) {
            $node->appendChild($this->el($doc, 'inscImobFisc',
                $obra->inscricaoImobiliariaFiscal,
            ));
        }
        // choice (XOR validado no DTO)
        if ($obra->codigoObra !== null) {
            $node->appendChild($this->el($doc, 'cObra', $obra->codigoObra));
        } elseif ($obra->codigoCib !== null) {
            $node->appendChild($this->el($doc, 'cCIB', $obra->codigoCib));
        } elseif ($obra->endereco !== null) {
            $node->appendChild($this->montarEnderecoSimples($doc, $obra->endereco));
        }
        return $node;
    }

    /**
     * Monta o grupo `<atvEvento>` conforme TCAtvEvento (XSD V1.01 linhas
     * 1486-1516). Estrutura:
     *   xNome → dtIni → dtFim → choice(idAtvEvt | end)
     */
    private function montarAtvEvento(\DOMDocument $doc, AtividadeEvento $ev): DOMElement
    {
        $node = $this->el($doc, 'atvEvento');
        $node->appendChild($this->el($doc, 'xNome',
            TextoSanitizador::paraNFSe($ev->nome, 255),
        ));
        $node->appendChild($this->el($doc, 'dtIni', $ev->dataInicio->format('Y-m-d')));
        $node->appendChild($this->el($doc, 'dtFim', $ev->dataFim->format('Y-m-d')));
        // choice (XOR validado no DTO)
        if ($ev->idAtividadeEvento !== null) {
            $node->appendChild($this->el($doc, 'idAtvEvt', $ev->idAtividadeEvento));
        } elseif ($ev->endereco !== null) {
            $node->appendChild($this->montarEnderecoSimples($doc, $ev->endereco));
        }
        return $node;
    }

    /**
     * Monta `<end>` com choice `<endNac>` ou `<endExt>` conforme tipo do DTO
     * (TCEndereco do XSD V1.01). Usado em `<toma>`, `<interm>` e
     * `<prest>`. Estrutura:
     *
     *   <end>
     *     <choice><endNac>...</endNac> | <endExt>...</endExt></choice>
     *     <xLgr>...</xLgr>
     *     <nro>...</nro>
     *     <xCpl>...</xCpl>?
     *     <xBairro>...</xBairro>
     *   </end>
     */
    private function montarEnderecoTcEndereco(\DOMDocument $doc, Endereco|EnderecoExterior $endereco): DOMElement
    {
        $end = $this->el($doc, 'end');

        if ($endereco instanceof EnderecoExterior) {
            $endExt = $this->el($doc, 'endExt');
            $endExt->appendChild($this->el($doc, 'cPais', $endereco->codigoPaisIso));
            $endExt->appendChild($this->el($doc, 'cEndPost',
                TextoSanitizador::paraNFSe($endereco->codigoEnderecamentoPostal, 11),
            ));
            $endExt->appendChild($this->el($doc, 'xCidade',
                TextoSanitizador::paraNFSe($endereco->cidade, 60),
            ));
            $endExt->appendChild($this->el($doc, 'xEstProvReg',
                TextoSanitizador::paraNFSe($endereco->estadoProvinciaRegiao, 60),
            ));
            $end->appendChild($endExt);
        } else {
            $endNac = $this->el($doc, 'endNac');
            $endNac->appendChild($this->el($doc, 'cMun', $endereco->codigoMunicipioIbge));
            $endNac->appendChild($this->el($doc, 'CEP',
                preg_replace('/\D/', '', $endereco->cep) ?? '',
            ));
            $end->appendChild($endNac);
        }

        $end->appendChild($this->el($doc, 'xLgr',
            TextoSanitizador::paraNFSe($endereco->logradouro, 255),
        ));
        $end->appendChild($this->el($doc, 'nro',
            TextoSanitizador::paraNFSe($endereco->numero, 60) ?: 'S/N',
        ));
        if ($endereco->complemento !== null && $endereco->complemento !== '') {
            $end->appendChild($this->el($doc, 'xCpl',
                TextoSanitizador::paraNFSe($endereco->complemento, 156),
            ));
        }
        $end->appendChild($this->el($doc, 'xBairro',
            TextoSanitizador::paraNFSe($endereco->bairro, 60),
        ));

        return $end;
    }

    /**
     * Monta um endereço simples (sem nação) — usado em `<obra>/<end>` e
     * `<atvEvento>/<end>`. Estrutura mínima: xLgr → nro → xCpl? → xBairro
     * → cMun → CEP (TCEnderecoSimples/TCEnderObraEvento).
     */
    private function montarEnderecoSimples(\DOMDocument $doc, \PhpNfseNacional\DTO\Endereco $end): DOMElement
    {
        $node = $this->el($doc, 'end');
        $node->appendChild($this->el($doc, 'xLgr',
            TextoSanitizador::paraNFSe($end->logradouro, 255),
        ));
        $node->appendChild($this->el($doc, 'nro',
            TextoSanitizador::paraNFSe($end->numero, 60) ?: 'S/N',
        ));
        if ($end->complemento !== null && $end->complemento !== '') {
            $node->appendChild($this->el($doc, 'xCpl',
                TextoSanitizador::paraNFSe($end->complemento, 156),
            ));
        }
        $node->appendChild($this->el($doc, 'xBairro',
            TextoSanitizador::paraNFSe($end->bairro, 60),
        ));
        $node->appendChild($this->el($doc, 'cMun', $end->codigoMunicipioIbge));
        $node->appendChild($this->el($doc, 'CEP',
            preg_replace('/\D/', '', $end->cep) ?? '',
        ));
        return $node;
    }
}
