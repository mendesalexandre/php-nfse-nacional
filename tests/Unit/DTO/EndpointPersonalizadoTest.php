<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\DTO;

use PhpNfseNacional\DTO\EndpointPersonalizado;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class EndpointPersonalizadoTest extends TestCase
{
    public function test_aceita_urls_validas(): void
    {
        $ep = new EndpointPersonalizado(
            producao: 'https://164.152.60.237/nota/nacional',
            homologacao: 'https://catanduva.prefeitura.rlz.com.br/nota/nacional',
        );

        self::assertSame('https://164.152.60.237/nota/nacional', $ep->producao);
        self::assertSame('https://catanduva.prefeitura.rlz.com.br/nota/nacional', $ep->homologacao);
    }

    public function test_rejeita_producao_vazia(): void
    {
        $this->expectException(ValidationException::class);
        new EndpointPersonalizado(producao: '', homologacao: 'https://exemplo.com.br');
    }

    public function test_rejeita_homologacao_vazia(): void
    {
        $this->expectException(ValidationException::class);
        new EndpointPersonalizado(producao: 'https://exemplo.com.br', homologacao: '');
    }

    public function test_rejeita_url_malformada(): void
    {
        $this->expectException(ValidationException::class);
        new EndpointPersonalizado(producao: 'não é uma url', homologacao: 'https://exemplo.com.br');
    }
}
