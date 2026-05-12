<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\DTO;

use Sinop\NfseNacional\Enums\RegimeEspecialTributacao;
use Sinop\NfseNacional\Exceptions\ValidationException;
use Sinop\NfseNacional\Support\Documento;

/**
 * Prestador do serviço (emissor da NFS-e).
 *
 * Normalmente é singleton dentro do app — instanciado uma vez a partir
 * da configuração persistente e reutilizado em cada emissão.
 */
final class Prestador
{
    public readonly string $cnpj;

    public function __construct(
        string $cnpj,
        public readonly string $inscricaoMunicipal,
        public readonly string $razaoSocial,
        public readonly Endereco $endereco,
        public readonly RegimeEspecialTributacao $regimeEspecial = RegimeEspecialTributacao::Nenhum,
        public readonly bool $optanteSimplesNacional = false,
        public readonly bool $incentivadorCultural = false,
        public readonly ?string $email = null,
        public readonly ?string $telefone = null,
    ) {
        $this->cnpj = Documento::limpar($cnpj);

        $errors = [];
        if (!Documento::ehCnpj($this->cnpj)) {
            $errors[] = "CNPJ do prestador inválido: {$cnpj}";
        }
        if (trim($inscricaoMunicipal) === '') {
            $errors[] = 'Inscrição municipal vazia';
        }
        if (trim($razaoSocial) === '') {
            $errors[] = 'Razão social vazia';
        }

        if (!empty($errors)) {
            throw new ValidationException($errors, 'Prestador inválido');
        }
    }
}
