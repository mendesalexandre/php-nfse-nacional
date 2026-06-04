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
        $raiz = realpath(__DIR__ . '/../../');
        self::assertNotFalse($raiz, 'raiz do projeto não encontrada');

        $vazamentos = [];
        $docsRaiz = glob($raiz . '/*.md') ?: [];
        $diretorios = array_filter(
            array_map(fn (string $d) => $raiz . '/' . $d, ['src', 'tests', 'examples']),
            is_dir(...),
        );

        $arquivosScan = $docsRaiz;
        foreach ($diretorios as $base) {
            $iter = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \RecursiveDirectoryIterator::SKIP_DOTS),
            );
            foreach ($iter as $arquivo) {
                if ($arquivo->isFile()) {
                    $arquivosScan[] = $arquivo->getPathname();
                }
            }
        }

        foreach ($arquivosScan as $path) {
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (!in_array($ext, ['php', 'xml', 'md', 'json', 'yml', 'yaml'], true)) {
                continue;
            }
            if (str_contains($path, '/SanitizacaoTest.php')) {
                continue; // o próprio guard contém os padrões na lista
            }
            if (str_contains($path, '/CLAUDE.md')) {
                continue; // CLAUDE.md é local-only do mantenedor (.gitignore)
            }
            $conteudo = file_get_contents($path);
            if ($conteudo !== false && preg_match($regex, $conteudo) === 1) {
                $vazamentos[] = str_replace($raiz . '/', '', $path);
            }
        }

        self::assertEmpty(
            $vazamentos,
            "Padrão sensível ({$rotulo}: '{$exemplo}') reintroduzido em: " . implode(', ', $vazamentos),
        );
    }
}
