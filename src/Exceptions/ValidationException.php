<?php

declare(strict_types=1);

namespace Sinop\NfseNacional\Exceptions;

/**
 * Erro de validação pré-envio: CNPJ inválido, CEP fora de formato,
 * alíquota negativa, campos obrigatórios ausentes, etc.
 *
 * Lançada antes de qualquer chamada HTTP ao SEFIN.
 */
class ValidationException extends NfseException
{
    /** @var list<string> */
    private array $errors;

    /**
     * @param list<string> $errors Lista de mensagens individuais.
     */
    public function __construct(array $errors, string $context = 'Validação falhou')
    {
        $this->errors = $errors;
        parent::__construct(
            $context . ': ' . implode(' | ', $errors)
        );
    }

    /**
     * @return list<string>
     */
    public function errors(): array
    {
        return $this->errors;
    }
}
