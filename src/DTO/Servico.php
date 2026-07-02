<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Enums\ListaNbs;
use PhpNfseNacional\Enums\ListaServicosNacional;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Descrição do serviço prestado.
 *
 * - cTribNac: 6 dígitos do código de tributação nacional (LC 116/2003).
 *   Aceita string ("210101") ou enum (`ListaServicosNacional::S210101`).
 *   Default '210101' (serviços notariais e de registro). Ajuste conforme
 *   o item da LC 116 do seu serviço.
 * - cNBS: 9 dígitos do código NBS — derivado do item da LC 116.
 *   Aceita string ou enum (`ListaNbs`).
 * - cIndOp: 6 dígitos do indicador de operação (ex: 100301 = serviço puro).
 * - codigoMunicipioPrestacao: IBGE 7 dígitos do município onde o serviço foi prestado.
 */
final class Servico
{
    public readonly string $cTribNac;
    public readonly string $cNBS;

    public function __construct(
        public readonly string $discriminacao,
        public readonly string $codigoMunicipioPrestacao,
        ListaServicosNacional|string $cTribNac = '210101',
        ListaNbs|string $cNBS = '113040000',
        public readonly string $cIndOp = '100301',
        /**
         * `<cTribMun>` — Código de tributação municipal do ISSQN, opcional
         * (3 dígitos). Só existe pra municípios que mantêm uma codificação
         * própria além do `cTribNac` nacional (LC 116/2003). A maioria dos
         * municípios não usa — deixe `null` se o seu não exigir.
         */
        public readonly ?string $cTribMun = null,
        /**
         * Grupo `<infoCompl>` (Informações Complementares) — opcional.
         * Quando setado, o `DpsBuilder` emite como ÚLTIMO filho de
         * `<serv>`. Use pra observações da nota, referência a documento
         * externo, identificador de documento técnico, etc.
         */
        public readonly ?InformacoesComplementares $infoCompl = null,
        /**
         * Grupo `<comExt>` (Comércio Exterior) — opcional. Obrigatório
         * quando `Valores::$tributacaoIssqn = ExportacaoServico` (caso
         * contrário SEFIN devolve cStat=330).
         */
        public readonly ?ComercioExterior $comExt = null,
        /**
         * Grupo `<obra>` (Informação de Obra) — opcional. Para serviços
         * de construção civil vinculados a obra.
         */
        public readonly ?InformacaoObra $obra = null,
        /**
         * Grupo `<atvEvento>` (Atividade de Evento) — opcional. Para
         * serviços vinculados a eventos artísticos/culturais/esportivos.
         */
        public readonly ?AtividadeEvento $atvEvento = null,
    ) {
        $this->cTribNac = $cTribNac instanceof ListaServicosNacional ? $cTribNac->value : $cTribNac;
        $this->cNBS = $cNBS instanceof ListaNbs ? $cNBS->value : $cNBS;

        $errors = [];

        if (mb_strlen(trim($discriminacao)) < 10) {
            $errors[] = 'Discriminação muito curta (mínimo 10 caracteres)';
        }
        if (mb_strlen($discriminacao) > 2000) {
            $errors[] = 'Discriminação muito longa (máximo 2000 caracteres)';
        }
        if (!preg_match('/^\d{7}$/', $codigoMunicipioPrestacao)) {
            $errors[] = "codigoMunicipioPrestacao inválido: {$codigoMunicipioPrestacao}";
        }
        if (!preg_match('/^\d{6}$/', $this->cTribNac)) {
            $errors[] = "cTribNac inválido: {$this->cTribNac} (esperado 6 dígitos)";
        }
        if ($cTribMun !== null && !preg_match('/^\d{3}$/', $cTribMun)) {
            $errors[] = "cTribMun inválido: {$cTribMun} (esperado 3 dígitos)";
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Serviço inválido');
        }
    }
}
