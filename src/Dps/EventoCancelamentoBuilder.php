<?php

declare(strict_types=1);

namespace PhpNfseNacional\Dps;

use DateTimeImmutable;
use DOMDocument;
use DOMElement;
use PhpNfseNacional\Config;
use PhpNfseNacional\DTO\MotivoCancelamento;
use PhpNfseNacional\Exceptions\ValidationException;
use PhpNfseNacional\Support\TextoSanitizador;

/**
 * Constrói o XML do evento e101101 (cancelamento de NFS-e).
 *
 * Estrutura conforme leiaute SefinNacional 1.6:
 *
 *   <pedRegEvento versao="1.01">
 *     <infPedReg Id="EVT...">
 *       <chNFSe>...</chNFSe>
 *       <CNPJAutor>...</CNPJAutor>
 *       <dhEvento>datetime</dhEvento>
 *       <tpAmb>1|2</tpAmb>
 *       <verAplic>...</verAplic>
 *       <e101101>
 *         <xDesc>Cancelamento de NFS-e</xDesc>
 *         <cMotivo>1|2|9</cMotivo>
 *         <xMotivo>15..200 chars</xMotivo>
 *       </e101101>
 *     </infPedReg>
 *   </pedRegEvento>
 *
 * O Signer assina depois (rsa-sha1 sobre <infPedReg>).
 */
final class EventoCancelamentoBuilder
{
    public function __construct(
        private readonly Config $config,
    ) {}

    public function build(
        string $chaveAcesso,
        MotivoCancelamento $motivo,
        string $justificativa,
    ): string {
        $errors = [];

        $chaveLimpa = preg_replace('/\D/', '', $chaveAcesso) ?? '';
        if (strlen($chaveLimpa) !== 50) {
            $errors[] = "Chave inválida: {$chaveAcesso} (esperado 50 dígitos)";
        }
        $just = trim($justificativa);
        if (mb_strlen($just) < 15 || mb_strlen($just) > 200) {
            $errors[] = sprintf(
                'Justificativa deve ter entre 15 e 200 caracteres (atual: %d)',
                mb_strlen($just),
            );
        }
        if (!empty($errors)) {
            throw new ValidationException($errors, 'Cancelamento inválido');
        }

        $dom = new DOMDocument('1.0', 'UTF-8');
        $dom->preserveWhiteSpace = false;
        $dom->formatOutput = false;

        $pedReg = $dom->createElementNS('http://www.sped.fazenda.gov.br/nfse', 'pedRegEvento');
        $pedReg->setAttribute('versao', '1.01');
        $dom->appendChild($pedReg);

        $infPedReg = $this->el($dom, 'infPedReg');
        $infPedReg->setAttribute('Id', $this->gerarEventoId($chaveLimpa));
        $pedReg->appendChild($infPedReg);

        $infPedReg->appendChild($this->el($dom, 'chNFSe', $chaveLimpa));
        $infPedReg->appendChild($this->el($dom, 'CNPJAutor', $this->config->prestador->cnpj));
        $infPedReg->appendChild($this->el($dom, 'dhEvento', $this->gerarDhEvento()));
        $infPedReg->appendChild($this->el($dom, 'tpAmb', (string) $this->config->ambiente->value));
        $infPedReg->appendChild($this->el($dom, 'verAplic', $this->config->versaoAplicacao));

        $e101101 = $this->el($dom, 'e101101');
        $e101101->appendChild($this->el($dom, 'xDesc', 'Cancelamento de NFS-e'));
        $e101101->appendChild($this->el($dom, 'cMotivo', (string) $motivo->value));
        $e101101->appendChild($this->el($dom, 'xMotivo', TextoSanitizador::paraNFSe($just, 200)));
        $infPedReg->appendChild($e101101);

        $xml = $dom->saveXML();
        if ($xml === false) {
            throw new ValidationException(['Falha ao serializar XML do evento']);
        }
        return $xml;
    }

    private function el(DOMDocument $doc, string $name, ?string $value = null): DOMElement
    {
        $el = $value !== null ? $doc->createElement($name, $value) : $doc->createElement($name);
        if ($el === false) {
            throw new ValidationException(["Falha ao criar elemento DOM <{$name}>"]);
        }
        return $el;
    }

    private function gerarEventoId(string $chave): string
    {
        // Padrão: EVT{chave}{tipoEvento:6}{nSequencial:2}
        // Pra primeira tentativa, nSequencial=01
        return 'EVT' . $chave . '101101' . '01';
    }

    private function gerarDhEvento(): string
    {
        $tz = new \DateTimeZone(Config::TIMEZONE_DPS);
        $now = (new DateTimeImmutable('now', $tz))->modify('-60 seconds');
        return $now->format('Y-m-d\TH:i:sP');
    }
}
