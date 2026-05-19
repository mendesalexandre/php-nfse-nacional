<?php

declare(strict_types=1);

namespace PhpNfseNacional\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Guarda contra reintrodução de dados reais de emissores que apareceram em
 * smokes de homologação. Os valores foram sanitizados — qualquer commit que
 * traga um deles de volta quebra este teste.
 *
 * Adicione novos padrões aqui se descobrir mais vazamentos.
 */
final class SanitizacaoTest extends TestCase
{
    /**
     * Padrões sensíveis e o regex que detecta. Pra padrões puramente
     * numéricos, exigimos non-digit boundary nos dois lados pra não
     * disparar contra códigos legítimos que tenham o IM/CEP como substring
     * (ex: NBS 114081100 contém "11408").
     *
     * @return list<array{0:string,1:string,2:string}>  [regex, rótulo, exemplo]
     */
    public static function padroesSensiveisProvider(): array
    {
        return [
            'CNPJ real do emissor de smoke' => ['/(?<!\d)00179028000138(?!\d)/i', 'CNPJ', '00179028000138'],
            'IM real do emissor de smoke' => ['/(?<!\d)11408(?!\d)/i', 'IM', '11408'],
            'CPF de tomador de smoke' => ['/(?<!\d)44208855134(?!\d)/i', 'CPF', '44208855134'],
            'Código IBGE de Sinop/MT' => ['/(?<!\d)5107909(?!\d)/i', 'cMun', '5107909'],
            'Nome do município (Sinop)' => ['/\bSinop\b/i', 'municipio', 'Sinop'],
            'Logradouro do emissor de smoke' => ['/\bNogueiras\b/i', 'logradouro', 'Nogueiras'],
            'CEP do emissor de smoke' => ['/(?<!\d)78550200(?!\d)/i', 'CEP', '78550200'],
            'Razão social do emissor' => ['/SERVICO REGISTRAL/i', 'razaoSocial', 'SERVICO REGISTRAL'],
        ];
    }

    /**
     * @dataProvider padroesSensiveisProvider
     */
    public function test_padrao_nao_aparece_em_codigo_publico(string $regex, string $rotulo, string $exemplo): void
    {
        $raizes = ['src', 'tests', 'examples'];
        $vazamentos = [];

        foreach ($raizes as $raiz) {
            $base = __DIR__ . '/../../' . $raiz;
            if (!is_dir($base)) {
                continue;
            }
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iter as $arquivo) {
                if (!$arquivo->isFile()) {
                    continue;
                }
                $ext = strtolower($arquivo->getExtension());
                if (!in_array($ext, ['php', 'xml', 'md', 'json', 'yml', 'yaml'], true)) {
                    continue;
                }
                if (str_contains($arquivo->getPathname(), '/SanitizacaoTest.php')) {
                    continue; // o próprio guard contém os padrões na lista
                }
                $conteudo = file_get_contents($arquivo->getPathname());
                if ($conteudo !== false && preg_match($regex, $conteudo) === 1) {
                    $vazamentos[] = str_replace(__DIR__ . '/../../', '', $arquivo->getPathname());
                }
            }
        }

        self::assertEmpty(
            $vazamentos,
            "Padrão sensível ({$rotulo}: '{$exemplo}') reintroduzido em: " . implode(', ', $vazamentos),
        );
    }
}
