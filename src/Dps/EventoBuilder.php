<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use PhpNfseNacional\Config;
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Support\TextoSanitizador;

/**
 * Constrói o XML genérico de eventos de NFS-e (pedRegEvento).
 *
 * Estrutura conforme leiaute SefinNacional 1.6:
 *
 *   <pedRegEvento versao="1.01">
 *     <infPedReg Id="EVT{chave:50}{tipoEvento:6}{seq:2}">
 *       <chNFSe>...</chNFSe>
 *       <CNPJAutor>...</CNPJAutor>
 *       <dhEvento>datetime</dhEvento>
 *       <tpAmb>1|2</tpAmb>
 *       <verAplic>...</verAplic>
 *       <e{tipoEvento}>
 *         <xDesc>{descricao}</xDesc>
 *         {camposGrupo de chave→valor aplicados como child elements}
 *       </e{tipoEvento}>
 *     </infPedReg>
 *   </pedRegEvento>
 *
 * O XML retornado é SEM assinatura — o Signer assina depois (rsa-sha1
 * sobre <infPedReg>).
 *
 * Pra um novo tipo de evento (carta de correção, substituição, etc.),
 * implemente {@see EventoNfse} e passe pra build() — sem alterar o SDK.
 */
final class EventoBuilder
{
    /**
     * Margem de segurança subtraída do dhEvento — alinha com dhProc do
     * servidor SEFIN. Mesma técnica do DpsBuilder pra dhEmi.
     */
    private const DH_EVENTO_MARGEM_SEGUNDOS = 60;

    public function __construct(
        private readonly Config $config,
    ) {}

    public function build(EventoNfse $evento): string
    {
        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $pedReg = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'pedRegEvento');
        $pedReg->setAttribute('versao', '1.01');
        $dom->appendChild($pedReg);

        $infPedReg = $this->el($dom, 'infPedReg');
        $infPedReg->setAttribute('Id', $this->gerarEventoId($evento));
        $pedReg->appendChild($infPedReg);

        $infPedReg->appendChild($this->el($dom, 'chNFSe', $evento->chaveAcesso()));
        $infPedReg->appendChild($this->el($dom, 'CNPJAutor', $this->config->prestador->cnpj));
        $infPedReg->appendChild($this->el($dom, 'dhEvento', $this->gerarDhEvento()));
        $infPedReg->appendChild($this->el($dom, 'tpAmb', (string) $this->config->ambiente->value));
        $infPedReg->appendChild($this->el($dom, 'verAplic', $this->config->versaoAplicacao));

        // Grupo específico do evento. Nome do nó = 'e' + tipoEvento (ex: 'e101101').
        $grupoNome = 'e' . $evento->codigoTipoEvento();
        $grupo = $this->el($dom, $grupoNome);
        $grupo->appendChild($this->el(
            $dom,
            'xDesc',
            TextoSanitizador::paraNFSe($evento->descricao(), 60),
        ));
        foreach ($evento->camposGrupo() as $campo => $valor) {
            $grupo->appendChild($this->el(
                $dom,
                $campo,
                TextoSanitizador::paraNFSe($valor, 200),
            ));
        }
        $infPedReg->appendChild($grupo);

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new ValidationException(['Falha ao serializar XML do evento']);
        }
        return $xml;
    }

    /**
     * Atributo Id do evento conforme leiaute:
     *   EVT{chave:50}{tipoEvento:6}{nSequencial:2}
     */
    private function gerarEventoId(EventoNfse $evento): string
    {
        return sprintf(
            'EVT%s%s%s',
            $evento->chaveAcesso(),
            $evento->codigoTipoEvento(),
            str_pad((string) $evento->nSequencial(), 2, '0', STR_PAD_LEFT),
        );
    }

    private function gerarDhEvento(): string
    {
        $tz = new \DateTimeZone(Config::TIMEZONE_DPS);
        $now = (new DateTimeImmutable('now', $tz))
            ->modify('-' . self::DH_EVENTO_MARGEM_SEGUNDOS . ' seconds');
        return $now->format('Y-m-d\TH:i:sP');
    }

    private function el(DOMDocument $doc, string $name, ?string $value = null): DOMElement
    {
        $el = $value !== null ? $doc->createElement($name, $value) : $doc->createElement($name);
        if ($el === false) {
            throw new ValidationException(["Falha ao criar elemento DOM <{$name}>"]);
        }
        return $el;
    }
}
