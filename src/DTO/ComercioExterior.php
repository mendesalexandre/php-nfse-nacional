<?php

declare(strict_types=1);

namespace PhpNfseNacional\DTO;

use PhpNfseNacional\Enums\EnvioMdic;
use PhpNfseNacional\Enums\MecanismoFomentoPrestador;
use PhpNfseNacional\Enums\MecanismoFomentoTomador;
use PhpNfseNacional\Enums\ModoPrestacao;
use PhpNfseNacional\Enums\MovimentacaoTemporariaBens;
use PhpNfseNacional\Enums\VinculoEntrePartes;
use PhpNfseNacional\Exceptions\ValidationException;

/**
 * Grupo `<comExt>` (Comércio Exterior) — opcional dentro de `<serv>`,
 * obrigatório quando `tributacaoIssqn=ExportacaoServico` (cStat=330
 * caso contrário). XSD V1.01 (TCComExterior, linhas 1364-1484).
 *
 * Campos obrigatórios pelo schema:
 * - mdPrestacao, vincPrest, tpMoeda, vServMoeda, mecAFComexP,
 *   mecAFComexT, movTempBens, mdic
 *
 * Campos opcionais:
 * - nDI (número da Declaração de Importação)
 * - nRE (número do Registro de Exportação)
 */
final class ComercioExterior
{
    public function __construct(
        public readonly ModoPrestacao $modoPrestacao,
        public readonly VinculoEntrePartes $vinculoEntrePartes,
        /**
         * Código BACEN da moeda — **3 dígitos numéricos** (não ISO 4217 alfa).
         * Tabela BACEN: USD=220, EUR=978, BRL=790, GBP=540, JPY=470, etc.
         * Confirmado contra XSD `TSCodMoeda` pattern `[0-9]{3}`.
         */
        public readonly string $codigoMoeda,
        /** Valor do serviço expresso na moeda estrangeira informada. */
        public readonly float $valorServicoMoeda,
        public readonly MecanismoFomentoPrestador $mecanismoFomentoPrestador,
        public readonly MecanismoFomentoTomador $mecanismoFomentoTomador,
        public readonly MovimentacaoTemporariaBens $movimentacaoTemporariaBens,
        public readonly EnvioMdic $envioMdic = EnvioMdic::NaoEnviar,
        /** Número da Declaração de Importação (DI/DSI/DA/DRI-E) averbada. */
        public readonly ?string $numeroDeclaracaoImportacao = null,
        /** Número do Registro de Exportação (RE) averbado. */
        public readonly ?string $numeroRegistroExportacao = null,
    ) {
        $errors = [];

        if (!preg_match('/^\d{3}$/', $codigoMoeda)) {
            $errors[] = "codigoMoeda inválido: '{$codigoMoeda}' (esperado 3 dígitos numéricos BACEN — ex: 220=USD, 978=EUR)";
        }
        if ($valorServicoMoeda <= 0) {
            $errors[] = 'valorServicoMoeda deve ser maior que zero';
        }
        if ($numeroDeclaracaoImportacao !== null && mb_strlen($numeroDeclaracaoImportacao) > 50) {
            $errors[] = 'numeroDeclaracaoImportacao muito longo (máx 50)';
        }
        if ($numeroRegistroExportacao !== null && mb_strlen($numeroRegistroExportacao) > 50) {
            $errors[] = 'numeroRegistroExportacao muito longo (máx 50)';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'ComercioExterior inválido');
        }
    }
}
