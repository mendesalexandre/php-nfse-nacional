<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit\DTO;

use PhpNfseNacional\DTO\TribRegular;
use PhpNfseNacional\Exceptions\ValidationException;
use PHPUnit\Framework\TestCase;

final class TribRegularTest extends TestCase
{
    public function test_valores_validos_sao_aceitos(): void
    {
        $tr = new TribRegular(cstReg: '000', cClassTribReg: '000001');

        self::assertSame('000', $tr->cstReg);
        self::assertSame('000001', $tr->cClassTribReg);
    }

    public function test_cstreg_com_formato_invalido_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new TribRegular(cstReg: '00', cClassTribReg: '000001');
    }

    public function test_cclasstribreg_com_formato_invalido_eh_rejeitado(): void
    {
        $this->expectException(ValidationException::class);
        new TribRegular(cstReg: '000', cClassTribReg: '1');
    }

    public function test_cclasstribreg_deve_comecar_com_cstreg_informado(): void
    {
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('não pertence ao grupo do CSTReg informado');
        new TribRegular(cstReg: '010', cClassTribReg: '000001');
    }
}
