# Manual de Referência — `mendesalexandre/php-nfse-nacional`

Referência completa da API pública do SDK. Cada operação documenta:
**assinatura**, **parâmetros**, **retorno**, **exceções**, **exemplo**.

Para tutorial introdutório e quickstart, ver [README.md](README.md).
Para histórico de versões, ver [CHANGELOG.md](CHANGELOG.md).

---

## Sumário

1. [Instalação](#instalação)
2. [Bootstrap](#bootstrap)
   - [`Certificate`](#certificate) — carga do .pfx
   - [`Prestador`](#prestador) e [`Endereco`](#endereco)
   - [`Config`](#config)
   - [`NFSe::create()`](#nfsecreate) — facade
3. [Operações](#operações)
   - [Emissão](#emissão) — `$nfse->emitir()`
   - [Consulta](#consulta) — `consultarNfse / consultarDps / consultarEventos`
   - [Cancelamento](#cancelamento) — `$nfse->cancelar()`
   - [Manifestação de NFS-e](#manifestação-de-nfs-e) — `confirmar / rejeitar / anularRejeicao`
   - [Substituição](#substituição) — `$nfse->substituir()`
   - [Download](#download) — `xmlNfse / pdfDanfse`
   - [DANFSe local](#danfse-local) — `$nfse->danfseLocal()`
4. [Tipos de retorno](#tipos-de-retorno)
   - [`SefinResposta`](#sefinresposta)
5. [DTOs de entrada](#dtos-de-entrada)
   - [`Identificacao`](#identificacao)
   - [`Tomador`](#tomador)
   - [`Servico`](#servico)
   - [`Valores`](#valores)
6. [Enums](#enums)
7. [Exceções](#exceções)
8. [Eventos customizados](#eventos-customizados) — extensibilidade
9. [Emissão retroativa (~contingência)](#emissão-retroativa-contingência)
10. [Apêndice — OpenSSL legacy provider](#apêndice--openssl-legacy-provider)

---

## Instalação

```bash
composer require mendesalexandre/php-nfse-nacional
```

**Requisitos:** PHP 8.1+, ext-dom, ext-openssl, ext-libxml, ext-zlib, ext-mbstring.
Para emissão real é necessário certificado A1 (.pfx) válido do prestador.

---

## Bootstrap

### `Certificate`

```php
namespace PhpNfseNacional\Certificate;

final class Certificate
{
    public static function fromPfxFile(string $caminhoPfx, string $senha): self;
    public static function fromPfxContent(string $pfxBinary, string $senha): self;

    public function __construct(
        public readonly string $privateKeyPem,
        public readonly string $certificatePem,
        public readonly DateTimeImmutable $validade,
        public readonly string $cnpj,
        public readonly string $subjectCN,
    );

    public function estaVencido(): bool;
    public function diasParaVencer(): int;
}
```

Carrega o certificado A1 do prestador. A senha não fica em memória após o retorno.

| Método | Quando usar |
|---|---|
| `fromPfxFile($path, $senha)` | Cert no disco |
| `fromPfxContent($bytes, $senha)` | Cert vindo de banco / S3 / ENV var (binário) |
| `new Certificate(...)` direto | Cert já decomposto em PEM (uso avançado) |

**Exceções:** `CertificateException` se arquivo ausente, senha errada, PFX corrompido ou parse x509 falhar.

```php
$cert = Certificate::fromPfxFile('/etc/cert/prestador.pfx', getenv('PFX_SENHA'));
echo "CN: {$cert->subjectCN}, validade {$cert->validade->format('d/m/Y')}";
if ($cert->diasParaVencer() < 30) {
    trigger_error('Certificado vence em menos de 30 dias', E_USER_WARNING);
}
```

### `Prestador`

```php
namespace PhpNfseNacional\DTO;

final class Prestador
{
    public readonly string $cnpj;                // limpo, só dígitos
    public readonly ?string $inscricaoMunicipal; // trim aplicado; '' → null

    public function __construct(
        string $cnpj,
        ?string $inscricaoMunicipal,
        public readonly string $razaoSocial,
        public readonly Endereco $endereco,
        public readonly RegimeEspecialTributacao $regimeEspecial = RegimeEspecialTributacao::Nenhum,
        public readonly SituacaoSimplesNacional $simplesNacional = SituacaoSimplesNacional::NaoOptante,
        public readonly bool $incentivadorCultural = false,
        public readonly ?string $email = null,
        public readonly ?string $telefone = null,
    );
}
```

Singleton dentro da app — instancie uma vez no bootstrap e reuse.

**Validações:** CNPJ ≥ 14 dígitos, razão social não-vazia. Lança `ValidationException` em caso de erro.

> **`inscricaoMunicipal` é opcional.** Passe `null` ou string vazia quando o prestador não tem (ou não deve declarar) IM. Caso de uso típico: **MEI emitindo em município sem informações complementares cadastradas no CNC NFS-e** — SEFIN devolve cStat **120** ("IM não deve ser informada, pois não existem informações complementares registradas no CNC NFS-e do município emissor"). Quando `null`/vazio, o SDK omite o nó `<IM>` do prestador no DPS.

> **Atenção `regimeEspecial`:** SEFIN rejeita combinação `regEspTrib != Nenhum` + dedução (`vDR > 0`) com erro **E0438**. Quando há dedução, o SDK força automaticamente `Nenhum` antes de assinar. Se você sempre tem dedução (ex.: cartórios usam ISSQN "por dentro"), pode deixar `Nenhum` direto.

### `Endereco`

```php
final class Endereco
{
    public function __construct(
        public readonly string $logradouro,
        public readonly string $numero,
        public readonly string $bairro,
        public readonly string $cep,                  // formatos: '78550200' ou '78550-200'
        public readonly string $codigoMunicipioIbge,  // 7 dígitos
        public readonly string $uf,                   // 2 letras maiúsculas
        public readonly ?string $complemento = null,
    );
}
```

**Validações:** CEP 8 dígitos pós-limpeza, IBGE exato `\d{7}`, UF `[A-Z]{2}`. Acumula erros e lança `ValidationException` única.

### `Config`

```php
namespace PhpNfseNacional;

final class Config
{
    public const NFSE_NAMESPACE = 'http://www.sped.fazenda.gov.br/nfse';
    public const LEIAUTE_VERSAO = '1.01';
    public const TIMEZONE_DPS   = 'America/Sao_Paulo';

    public function __construct(
        public readonly Prestador $prestador,
        public readonly Ambiente $ambiente = Ambiente::Homologacao,
        public readonly int $timeoutSegundos = 30,        // 5..300
        public readonly int $maxTentativas = 3,           // 1..10
        public readonly string $versaoAplicacao = 'php-nfse-1.0',  // max 20 chars
        public readonly bool $debugLogPayload = false,    // log XML do DPS no debug
        public readonly bool $incluirIbsCbs = false,      // Reforma Tributária — opt-in
    );
}
```

> **Sem helper `isProduction()`.** Comparação direta com o enum é mais expressiva e segue o estilo do `nfephp-org/sped-nfe`: `if ($config->ambiente === Ambiente::Producao) { ... }`.

Imutável. Habilite `debugLogPayload: true` quando estiver investigando rejeição da SEFIN — o `SefinClient` vai logar o XML completo via PSR-3 no nível `debug`.

> **`incluirIbsCbs` (Reforma Tributária):** default `false`. Quando `true`, inclui o bloco `<IBSCBS>` no DPS de envio. Validado em homologação que SEFIN aceita DPS com OU sem o bloco — quando ausente, o IBSCBS também não aparece na resposta autorizada (opt-in pelo emissor). A Reforma está em rampa de subida (alíquotas simbólicas em 2026: IBS UF 0.10%, IBS Mun 0%, CBS 0.90%); deixe `false` enquanto não for obrigatório. Independente desse toggle, o DANFSe local SEMPRE renderiza IBS/CBS se vier no XML autorizado.

### `NFSe::create()`

```php
namespace PhpNfseNacional;

final class NFSe
{
    public static function create(
        Config $config,
        Certificate $certificate,
        ?\Psr\Http\Client\ClientInterface $http = null,
        ?\Psr\Log\LoggerInterface $logger = null,
    ): self;

    // Emissão
    public function emitir(Identificacao, Tomador, Servico, Valores): SefinResposta;

    // Consulta
    public function consultar(string $chave): SefinResposta;
    public function consultarDps(string $chave): SefinResposta;
    public function consultarEventos(string $chave, ?string $tipo = null, ?int $seq = null): SefinResposta;

    // Cancelamento / Substituição
    public function cancelar(string $chave, MotivoCancelamento, string $just): SefinResposta;
    public function substituir(string $orig, string $subst, MotivoSubstituicao, string $just = ''): SefinResposta;

    // Manifestação
    public function confirmar(string $chave, AutorManifestacao): SefinResposta;
    public function rejeitar(string $chave, AutorManifestacao, MotivoRejeicao, string $xMotivo = ''): SefinResposta;
    public function anularRejeicao(string $chave, string $cpf, string $idRej, string $xMotivo): SefinResposta;

    // Download / DANFSe local
    public function baixarXml(string $chave): string;
    public function baixarPdf(string $chave): string;
    public function danfseLocal(string $xml, ?DanfseCustomizacao = null): string;
    public function danfseLocalDeDados(DanfseDados, ?DanfseCustomizacao = null): string;
}
```

> **API achatada (v0.5.0+)** — antes era `$nfse->emissao()->emitir(...)`, agora é só `$nfse->emitir(...)`. Os Service classes (`EmissaoService`, `CancelamentoService`, etc.) continuam públicos em `PhpNfseNacional\Services\` — quem usa DI granular pode instanciá-los diretamente.

Facade unificado — entry point recomendado. Resolve toda a árvore de dependências (builders, signer, client) internamente.

**Injeção opcional:**
- `$http` — qualquer cliente PSR-18 (default: Guzzle com mTLS via cert do prestador)
- `$logger` — qualquer PSR-3 (default: `NullLogger`)

```php
$cert = Certificate::fromPfxFile($pfxPath, $pfxSenha);
$config = new Config(prestador: $prestador, ambiente: Ambiente::Homologacao);
$nfse = NFSe::create($config, $cert, logger: $appLogger);
```

Para DI containers (Symfony/Laravel/etc.) que precisam wirear cada serviço manualmente, use os construtores individuais — ver [`src/NFSe.php`](src/NFSe.php) como referência.

---

## Operações

### Emissão

```php
$nfse->emitir(
    Identificacao $identificacao,
    Tomador       $tomador,
    Servico       $servico,
    Valores       $valores,
): SefinResposta
```

Monta o DPS, assina (xmldsig + rsa-sha1), envia ao Portal Nacional e parseia a resposta.

| Erro | Como propaga |
|---|---|
| DTO inválido (CNPJ, CEP, alíquota...) | `ValidationException` — antes de qualquer HTTP |
| Cert vencido / OPENSSL_CONF / assinatura | `CertificateException` |
| SEFIN rejeitou (cStat ≠ 100) | `SefinException` (cStat, xMotivo, rawResponse) |

Ajustes feitos automaticamente pelo SDK na hora de montar o DPS:
- `dhEmi` em `America/Sao_Paulo` `−60s` (clock drift, evita E0008)
- `regimeEspecial` forçado a `Nenhum` se houver dedução (evita E0438)
- `cTribNac` validado em 6 dígitos (default `'210101'` para serviços notariais — ajuste por DTO `Servico`)
- `<locPrest>` sempre `cLocPrestacao` (nunca junto com `cPaisPrestacao` — evita E1235)

```php
$resp = $nfse->emitir(
    identificacao: new Identificacao(numeroDps: 1745, serie: '1'),
    tomador:       new Tomador('12345678901', 'Cliente Exemplo', $endereco),
    servico:       new Servico('Certidão de matrícula', codigoMunicipioPrestacao: '5107909'),
    valores:       new Valores(valorServicos: 100.00, deducoesReducoes: 20.00, aliquotaIssqnPercentual: 4.00),
);

echo $resp->chaveAcesso;  // 50 dígitos
echo $resp->numeroNfse;   // ex: '2026-0000123'
echo $resp->cStat;        // 100 (emitida)
```

### Consulta

```php
$nfse->consultar(string $chaveAcesso): SefinResposta
$nfse->consultarDps(string $chaveAcesso): SefinResposta
$nfse->consultarEventos(
    string $chaveAcesso,
    ?string $tipoEvento = null,    // ex: '101101' — null devolve todos
    ?int $nSequencial = null,      // null devolve mais recente
): SefinResposta
```

| Método | Para que serve |
|---|---|
| `consultarNfse` | Status atual da NFS-e (emitida, cancelada, substituída) |
| `consultarDps` | Status do DPS (útil quando emissão saiu em transit e quer confirmar) |
| `consultarEventos` | Eventos vinculados — cancelamento, substituição |

**Validação:** `ValidationException` se chave de acesso ≠ 50 dígitos.

```php
$resp = $nfse->consultar('51079092200179028000138000000000005726057774456203');

if ($resp->cancelada()) { /* ... */ }
echo $resp->numeroNfse;
```

### Cancelamento

```php
$nfse->cancelar(
    string              $chaveAcesso,
    MotivoCancelamento  $motivo,
    string              $justificativa,   // 15..200 chars
): SefinResposta
```

Evento e101101. **Aceitação de cStat:**

| cStat | Tratamento |
|---|---|
| 100, 135, 155 | Sucesso |
| 840 (E0840) | Idempotente — cancelamento já registrado previamente |
| Demais | `SefinException` |

**Validação:** chave 50 dígitos, justificativa 15–200 caracteres, sequencial 1–99.

```php
use PhpNfseNacional\DTO\MotivoCancelamento;

$resp = $nfse->cancelar(
    '51079092200179028000138000000000005726057774456203',
    MotivoCancelamento::ErroEmissao,
    'Valor da NFS-e divergente do recibo',
);

if ($resp->cStat === 840) {
    echo "Já estava cancelada antes — operação idempotente\n";
}
```

### Manifestação de NFS-e

Eventos pelo Prestador, Tomador ou Intermediário pra **confirmar** ou **rejeitar** uma NFS-e emitida. Útil quando o tomador recebe uma nota e quer (a) reconhecer formalmente, (b) recusar (motivo: duplicidade, sem fato gerador, valor errado, etc.) ou (c) anular uma rejeição feita por engano.

```php
$nfse->confirmar(
    string             $chaveAcesso,
    AutorManifestacao  $autor,                // Prestador, Tomador, Intermediario
): SefinResposta

$nfse->rejeitar(
    string             $chaveAcesso,
    AutorManifestacao  $autor,
    MotivoRejeicao     $motivo,                // Duplicidade, JaEmitidaPeloTomador, ...
    string             $xMotivo = '',          // obrigatório se motivo=Outros (15-200 chars)
): SefinResposta

$nfse->anularRejeicao(
    string $chaveAcesso,
    string $cpfAgente,        // CPF do responsável pela anulação (11 dígitos)
    string $idEvManifRej,     // Id da Rejeição original — aceita "PRE+56dig" OU 59 dígitos puros
    string $xMotivo,          // 15-200 chars, obrigatório
): SefinResposta
```

> **Sobre o `idEvManifRej`:** o leiaute exige formato `TSIdNumEvento` (59 dígitos puros = chave50 + tipoEvento6 + nSeqEvento3). Mas o SDK aceita também o formato `PRE+56dig` (que é o `Id` do `<infPedReg>` da Rejeição original) e converte automaticamente. Use o que for mais conveniente.

> **Sobre o `cpfAgente`:** apesar do nome do campo no leiaute ser `CPFAgTrib` (sugerindo "agente tributário"), na prática é o CPF do **responsável** pela anulação. Em PJ, use o CPF do sócio/responsável legal vinculado ao certificado.

Códigos de evento gerados:

| Operação | Prestador | Tomador | Intermediário |
|---|---|---|---|
| Confirmação | `e202201` | `e203202` | `e204203` |
| Rejeição | `e202205` | `e203206` | `e204207` |
| Anulação Rejeição | `e205208` | `e205208` | `e205208` |

> **Restrição (E1833):** cada autor (Prestador/Tomador/Intermediário) pode emitir UMA Confirmação OU UMA Rejeição — não ambos. Tentar uma segunda manifestação resulta em erro do ADN.

> **Restrição (E1835):** uma Rejeição só pode ser anulada UMA vez. Após anular, não dá pra rejeitar de novo.

> **Validado em homologação SEFIN (13/05/2026, cartório de Sinop):**
> - ✅ **Confirmação do Prestador** (e202201) — cStat=100, NFS-e #72
> - ✅ **Rejeição do Prestador** (e202205, motivo Duplicidade) — cStat=100, NFS-e #73
> - ⚠️ **Anulação da Rejeição** (e205208) — cStat=999 ("Falha de configuração"). Provavelmente a parametrização do município de Sinop ainda não habilita esse evento em homologação. Outros municípios podem ter habilitado — teste no seu.

```php
use PhpNfseNacional\DTO\MotivoRejeicao;
use PhpNfseNacional\Enums\AutorManifestacao;

// Tomador rejeita NFS-e que recebeu por duplicidade
$resp = $nfse->rejeitar(
    chaveAcesso: '51079...',
    autor: AutorManifestacao::Tomador,
    motivo: MotivoRejeicao::Duplicidade,
);

// 1 hora depois, tomador percebe que rejeitou por engano
$idRejeicaoOriginal = 'PRE51079...203206';   // do `<infPedReg Id="...">` da rejeição

$resp = $nfse->anularRejeicao(
    chaveAcesso: '51079...',
    idEvManifRej: $idRejeicaoOriginal,
    xMotivo: 'Rejeição feita por engano — NFS-e está correta',
);
```

### Substituição

```php
$nfse->substituir(
    string              $chaveOriginal,    // NFS-e a cancelar (50 dígitos)
    string              $chaveSubstituta,  // NFS-e nova já emitida (50 dígitos)
    MotivoSubstituicao  $motivo,            // ⚠ enum DIFERENTE de MotivoCancelamento
    string              $justificativa = '', // obrigatório só se motivo=Outros (15..200)
): SefinResposta
```

Evento e105102 — cancela `chaveOriginal` e registra o vínculo com `chaveSubstituta`.

> **Pré-requisito:** `chaveSubstituta` já tem que ter sido emitida normalmente via `$nfse->emitir()`. Esse método **não** emite a substituidora — apenas registra o vínculo + cancelamento.

> **⚠ `MotivoSubstituicao` é DIFERENTE de `MotivoCancelamento`** — o leiaute SefinNacional usa `TSCodJustSubst` (códigos 01-05/99) pra substituição, não `TSCodJustCanc` (1/2/9). Cases: `DesenquadramentoSimples` (01), `EnquadramentoSimples` (02), `InclusaoImunidade` (03), `ExclusaoImunidade` (04), `RejeicaoTomador` (05), `Outros` (99 — exige `xMotivo`).

**Validações:** ambas as chaves 50 dígitos, distintas entre si; quando `motivo=Outros`, justificativa obrigatória 15–200 chars; sequencial 1–99.

Mesma regra de aceitação de cStat do cancelamento ({100, 135, 155} → ok; 840 → idempotente; demais → `SefinException`).

```php
use PhpNfseNacional\DTO\MotivoSubstituicao;

$resp = $nfse->substituir(
    chaveOriginal:   $chaveOriginal,
    chaveSubstituta: $resp->chaveAcesso,  // veio da emissão da nova
    motivo:          MotivoSubstituicao::DesenquadramentoSimples,
);
```

### Download

```php
$nfse->baixarXml(string $chaveAcesso): string         // XML autorizado
$nfse->baixarPdf(string $chaveAcesso): string       // bytes do PDF
$nfse->consultar(string $chaveAcesso): SefinResposta
```

| Método | Onde busca | Observação |
|---|---|---|
| `xmlNfse` | SEFIN Nacional `/nfse/{chave}` | XML completo (DPS + assinaturas + autorização) |
| `pdfDanfse` | ADN `/danfse/{chave}` | **A partir de 01/07/2026 ADN desativa esse endpoint** — use [`danfse()`](#danfse-local) |
| `consultarNfse` | SEFIN | Helper, idêntico a `consulta()->consultarNfse` |

**Erros:**
- `ValidationException` — chave inválida
- `RuntimeException` — chave válida mas SEFIN não devolveu XML
- `SefinException` — HTTP ≠ 200 ou conteúdo não é PDF (`pdfDanfse` valida magic bytes `%PDF`)

```php
$xml = $nfse->baixarXml($chave);
file_put_contents("/var/nfse/{$chave}.xml", $xml);

$pdf = $nfse->baixarPdf($chave);
file_put_contents("/var/nfse/{$chave}.pdf", $pdf);
```

### DANFSe local

```php
$nfse->danfseLocal(string $xmlNfse, ?DanfseCustomizacao $custom = null): string
$nfse->danfseLocalDeDados(DanfseDados $dados, ?DanfseCustomizacao $custom = null): string
$nfse->danfse()->parser(): DanfseXmlParser
```

#### Customização opcional

```php
use PhpNfseNacional\Danfse\DanfseCustomizacao;

$custom = new DanfseCustomizacao(
    logoPrestadorPath:    '/var/cartorio/logo.png',  // opcional, PNG/JPG
    observacoesAdicionais: 'Esta NFS-e refere-se a serviço cartorial. ' .
                           'Para autenticidade consulte https://...',  // opcional, max 2000 chars
);

$pdf = $nfse->danfseLocal($xmlAutorizado, $custom);
```

| Customização | Onde aparece no DANFSe | Limite |
|---|---|---|
| `logoPrestadorPath` | Canto superior direito do bloco PRESTADOR (4cm × 1.26cm) | qualquer formato suportado pelo TCPDF (PNG/JPG/GIF) |
| `observacoesAdicionais` | Concatenado ao bloco INFORMAÇÕES COMPLEMENTARES (após o `<xOutInf>` do XML) | 2000 chars |

> **Logo institucional NFSe NÃO pode ser substituído** — é obrigatório no cabeçalho conforme item 2.2.4 da NT 008/2026. O logo do prestador é renderizado em espaço dedicado dentro do bloco PRESTADOR.

Gera o DANFSe (PDF) localmente seguindo o leiaute **NT 008/2026** — todos os 13 blocos do Anexo I. **Não exige certificado.** Substitui o download oficial após 01/07/2026 quando o ADN desativa.

| Caminho | Uso |
|---|---|
| `gerarDoXml($xmlAutorizado)` | Path mais comum: você já tem o XML (do retorno de `emitir()` ou `download()->xmlNfse()`) |
| `gerarDeDados($dados)` | Você quer customizar o DTO antes de renderizar |
| `parser()` | Só extrair dados sem gerar PDF |

**Erros:** `NfseException` se XML inválido / sem campos obrigatórios.

Marcas d'água automáticas:
- **NFS-e SEM VALIDADE JURÍDICA** — ambGer=2 (homologação)
- **CANCELADA** diagonal cinza — cStat 101/102
- **SUBSTITUÍDA** diagonal cinza — quando há evento de substituição

```php
$pdf = $nfse->danfseLocal($xmlAutorizado);
file_put_contents('danfse.pdf', $pdf);
// → 80-150 KB, layout NT 008
```

---

## Tipos de retorno

### `SefinResposta`

```php
namespace PhpNfseNacional\Sefin;

final class SefinResposta
{
    public function __construct(
        public readonly ?string $chaveAcesso,         // 50 dígitos ou null se rejeição
        public readonly ?int    $cStat,               // 100=ok, 101/102=cancelada, ...
        public readonly ?string $xMotivo,             // mensagem do portal
        public readonly ?string $protocolo,           // id da operação no SEFIN (nDFSe/nProt)
        public readonly ?string $numeroNfse,          // ex: '2026-0000123'
        public readonly ?string $codigoVerificacao,
        public readonly ?string $dataProcessamento,   // timestamp ISO -03:00
        public readonly ?string $xmlRetorno,          // XML bruto (descomprimido)
        public readonly string  $rawResponse,         // body cru do HTTP (debug)
    );

    public function emitida(): bool;            // cStat=100 + chaveAcesso != null
    public function cancelada(): bool;          // cStat ∈ {101, 102, 135, 155}
    public function erro(): bool;               // !emitida && !cancelada
    public function eventoIdempotente(): bool;  // cStat=840 (já estava vinculado)
    public function cStatTipado(): ?CStat;      // tryFrom() do enum CStat — null se desconhecido
}
```

Imutável. `xmlRetorno` é útil pra arquivar em S3; `rawResponse` é só pra debug.

---

## DTOs de entrada

### `Identificacao`

```php
final class Identificacao
{
    public function __construct(
        public readonly int                 $numeroDps,            // 1..99_999_999
        public readonly string              $serie = '1',          // 1-5 chars
        public readonly ?DateTimeImmutable  $dataCompetencia = null,
        public readonly TipoEmissaoDps      $tipoEmissao = TipoEmissaoDps::Normal,
    );

    public function dataCompetenciaResolvida(): DateTimeImmutable;  // null → now()
}
```

`numeroDps` é gerado pela aplicação cliente (geralmente formato `AANNNNNN` = ano + 6 sequenciais). Não há gerador automático no SDK.

### `Tomador`

```php
final class Tomador
{
    public readonly string $documento;   // limpo (só dígitos), 11 ou 14

    public function __construct(
        string                $documento,             // CPF ou CNPJ, com ou sem máscara
        public readonly string  $nome,
        public readonly Endereco $endereco,
        public readonly ?string $email = null,         // valida format se != null
        public readonly ?string $telefone = null,
        public readonly ?string $inscricaoMunicipal = null,  // <toma><IM> opcional
    );

    public function ehPessoaFisica(): bool;     // strlen($documento) === 11
}
```

> **`inscricaoMunicipal`:** opcional. Útil quando o tomador é PJ no mesmo município do prestador — permite cruzamento de dados pela prefeitura e tratamento de imunidade tributária por IM. Em cartório de RI o tomador é majoritariamente PF, então fica null normalmente.

### `Servico`

```php
final class Servico
{
    public function __construct(
        public readonly string $discriminacao,             // 10..2000 chars
        public readonly string $codigoMunicipioPrestacao,  // IBGE 7 dígitos
        public readonly string $cTribNac = '210101',       // LC 116 — default = serviços notariais
        public readonly string $cNBS = '113040000',
        public readonly string $cIndOp = '100301',
    );
}
```

#### Códigos de tributação por segmento

Os defaults do `Servico` são pra **cartório de registro de imóveis/notarial**. Pra outros segmentos, sobrescreva os 3 campos no construtor:

| Segmento | `cTribNac` | LC 116/2003 | Observação |
|---|---|---|---|
| Cartório (notarial/registro) | `210101` | item 21.01 | default do SDK |
| Advocacia | `170101` | item 17.13 | "Advocacia" |
| Medicina/saúde | `040101` | item 4.01 | "Medicina e biomedicina" |
| Engenharia/arquitetura | `070301` | item 7.03 | "Elaboração de planos diretores" |
| Contabilidade | `170201` | item 17.19 | "Contabilidade, auditoria" |
| Educação | `080101` | item 8.01 | "Ensino regular" |
| Informática (desenvolvimento) | `010501` | item 1.04 | "Elaboração de programas" |
| Construção civil | `070201` | item 7.02 | "Execução por administração" |
| Transporte municipal | `160101` | item 16.01 | "Serviços de transporte" |

A tabela completa está no [Anexo II do leiaute SefinNacional 1.6](https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica) (`AnexoII-TabelaTributosNacionais`). `cNBS` e `cIndOp` também variam — consulte a tabela oficial do município de destino.

> **⚠ Atenção:** `cTribNac` errado é causa frequente de E1235 ou de NFS-e tributada em alíquota errada. O leiaute valida o cruzamento `cTribNac × cClassTrib × município`. Use sempre o código exato do seu segmento.

> **Cartório de Sinop:** sempre `'210101'`. NUNCA `'140101'` (lubrificação/limpeza — bug histórico já corrigido).

### `Valores`

```php
final class Valores
{
    public function __construct(
        public readonly float $valorServicos,                // > 0
        public readonly float $deducoesReducoes,             // 0 .. valorServicos
        public readonly float $aliquotaIssqnPercentual,      // 0..10
        public readonly bool  $issqnRetido = false,
        public readonly float $descontoIncondicionado = 0.0,
    );

    public function baseCalculo(): float;   // valorServicos − descIncond − deducoesReducoes
    public function valorIssqn(): float;    // baseCalculo × alíquota / 100, arredondado 2 casas
}
```

> **ISSQN "por dentro":** o leiaute SefinNacional computa `vBC = vServ − vDR`. Para a base bater com a real, `deducoesReducoes` precisa **incluir o ISSQN** (= soma de taxas + ISSQN arredondado). É contraintuitivo mas é regra do leiaute. Calcule no cliente antes de instanciar.

> **Precisão e arredondamento:**
> - Valores monetários (`vServ`, `vDR`, `vBC`, `vISSQN`, `vLiq`) — sempre 2 casas decimais.
> - Alíquotas (`pTotTribMun`) — **2 casas decimais fixas no DPS**. O leiaute SefinNacional 1.6 restringe ao tipo `TSDec3V2` (`\d{1,3}\.\d{2}`). Diferente da NF-e (NT 03.14, que ampliou pra 4 casas) — passar `4.0000` ao SEFIN resulta em E1235 ("Pattern constraint failed"). O SDK arredonda HALF_UP automaticamente: alíquota `3.5125` vira `3.51` no XML.
> - Modo de arredondamento: PHP `round()` `HALF_UP` (5 vai pra cima): `0.125 → 0.13`, `0.005 → 0.01`. PHP 8+ corrigiu o caveat float-point clássico (`0.115` agora retorna `0.12`, não mais `0.11` como em PHP 7).
>
> **Tabela validada empiricamente (PHP 8.4 + SEFIN homologação):**
>
> | Input `aliquotaIssqnPercentual` | `<pTotTribMun>` no DPS | `<pAliqAplic>` na resposta SEFIN | Comentário |
> |---|---|---|---|
> | `4.00` (2 casas) | `4.00` | `4.00` | bate exato |
> | `3.5125` (3ª casa = 2, abaixo de 5) | `3.51` | `4.00` | input arredondado HALF_UP, SEFIN ignora — usa cadastro |
> | `3.5555` (3ª casa = 5 exato) | `3.56` | `4.00` | 5 vai pra cima |
> | `3.5995` (3ª casa = 9, transborda unidade) | `3.60` | `4.00` | `…995 → 3.60` |
> | `3.5099` (3ª casa = 0, mas há 99) | `3.51` | `4.00` | sobe pela 4ª/5ª casa fazendo a 3ª passar de 5 |
> | `0.0049` | `0.00` | n/a | abaixo de 5 na 3ª, zera |
> | `0.005` | `0.01` | n/a | exatamente 5, vai pra 0.01 |
> | `0.006` | `0.01` | n/a | acima de 5 |
> | `0.115` | `0.12` | n/a | PHP 8+ resolveu — em PHP 7 dava `0.11` |

> **⚠ ACHADO IMPORTANTE — `pTotTribMun` é declaratório, não tributário:**
>
> O `pTotTribMun` enviado no DPS **não define a alíquota efetiva** do ISSQN. É a "alíquota aproximada de tributos municipais" pra atender a Lei 12.741/2012 (Lei da Transparência Fiscal — exibe na nota a carga tributária estimada).
>
> A alíquota real do ISSQN é determinada pelo **cadastro tributário do município no SEFIN** (vinculado ao `cTribNac` × `cClassTrib`). Vem na resposta autorizada como `<pAliqAplic>` e é usada pra calcular `<vISSQN>`.
>
> Validado em homologação 13/05/2026 (NFS-es #61 e #62 do cartório de Sinop): mesmo enviando `pTotTribMun=3.51` ou `3.56`, SEFIN aplicou `pAliqAplic=4.00` (alíquota oficial de Sinop pra LC 116 item 21.01) e calculou ISSQN sobre 4%.
>
> Implicação prática: você não precisa "acertar" a alíquota tributária no DPS — basta enviar uma estimativa razoável da carga total. Em cartório de RI fica `4.00` (alíquota local). Pra outros segmentos, consulte a alíquota cadastrada pelo município.

---

## Enums

```php
namespace PhpNfseNacional\Enums;

enum Ambiente: int {
    case Producao    = 1;
    case Homologacao = 2;
    public function label(): string;
}

enum RegimeEspecialTributacao: int {
    case Nenhum                  = 0;
    case MicroempresaMunicipal   = 1;
    case Estimativa              = 2;
    case SociedadeProfissionais  = 3;
    case NotarioOuRegistrador    = 4;   // ⚠ rejeitado pela SEFIN se houver vDR — use Nenhum
    case Cooperativa             = 5;
    case MEI                     = 6;
    case MeEppSimples            = 7;
}

enum SituacaoSimplesNacional: int {
    case NaoOptante = 1;
    case MEI        = 2;
    case MeEpp      = 3;
}

namespace PhpNfseNacional\DTO;

enum TipoEmissaoDps: int {
    case Prestador     = 1;   // default — emissão pelo próprio prestador
    case Tomador       = 2;   // ⚠ leiaute aceita, mas SEFIN ainda não habilitou (cStat=9996)
    case Intermediario = 3;   // ⚠ idem
}

enum MotivoCancelamento: int {
    case ErroEmissao         = 1;
    case ServicoNaoPrestado  = 2;
    case Outros              = 9;
    public function label(): string;
}

// Códigos de status retornados pelo SEFIN/ADN — lista NÃO exaustiva (centenas
// possíveis). Cobre sucessos, erros comuns da emissão, idempotência e os
// 41 códigos do Anexo IV/CSV ADN (eventos avançados).
enum CStat: int {
    // Sucesso
    case Emitida                   = 100;
    case Cancelada                 = 101;
    case CanceladaPorSubstituicao  = 102;
    case EventoRegistrado          = 135;
    case CancelamentoHomologado    = 155;
    case EventoVinculado           = 840;  // idempotente

    // Erros comuns SEFIN
    case ErroDhEmiPosteriorAoProc  = 8;
    case ErroCompetPosteriorAoEmi  = 15;
    case ErroConvenioInativo       = 38;
    case ErroRegEspTribComDeducao  = 438;
    case ErroDeducaoNaoPermitida   = 440;
    case ErroSchemaXml             = 1235;
    case ErroEmitenteNaoHabilitado = 9996;

    // 41 códigos AdnXXXX = 1800–2032 (eventos avançados ADN)

    public function descricao(): string;       // mensagem oficial
    public function ehSucesso(): bool;
    public function ehErroSefin(): bool;       // !sucesso && !ADN
    public function ehErroAdn(): bool;         // 1800 ≤ value < 3000
    public function ehErroSchema(): bool;      // === ErroSchemaXml

    public static function aceitosEvento(): array;    // [100, 135, 155, 840]
    public static function estadosCancelada(): array; // [101, 102, 135, 155]
}
```

> **Como usar:** `SefinResposta::$cStat` continua `?int` (qualquer código possível). Pra comparação tipada use `CStat::tryFrom($resp->cStat)` ou o helper `$resp->cStatTipado()`. Pra checagem rápida de idempotência: `$resp->eventoIdempotente()`.

> **`tpEmit` é "quem emitiu", não "modo contingência".** Diferente da NF-e (que usa tpEmit pra distinguir online/offline). Pra emissão tipo contingência no SefinNacional, ver a seção [Emissão retroativa (~contingência)](#emissão-retroativa-contingência) abaixo.

---

## Exceções

Hierarquia:

```
\RuntimeException
└── PhpNfseNacional\Exceptions\NfseException        (base — catch genérico)
    ├── ValidationException                         (DTOs inválidos, antes de qualquer HTTP)
    │     public function errors(): array<string>
    ├── CertificateException                        (PFX, OpenSSL, assinatura)
    └── SefinException                              (rejeição do portal)
          public readonly ?int    $cStat;
          public readonly ?string $xMotivo;
          public readonly ?string $rawResponse;
```

| Quando ocorre | Tipo |
|---|---|
| CPF/CNPJ/CEP/IBGE/UF inválidos, alíquota > 10% etc. | `ValidationException` |
| Arquivo .pfx ausente, senha errada, openssl_sign falha | `CertificateException` |
| SEFIN devolveu cStat de erro ou HTTP ≠ 2xx | `SefinException` |
| Catch tudo do SDK | `NfseException` |
| Não relacionado ao SDK | `\Throwable` (rede, disk full, etc.) |

```php
try {
    $resp = $nfse->emitir(...);
} catch (ValidationException $e) {
    foreach ($e->errors() as $msg) { /* ... */ }
} catch (CertificateException $e) {
    // dica: setar OPENSSL_CONF — ver Apêndice
} catch (SefinException $e) {
    log("cStat={$e->cStat}: {$e->xMotivo}");
    file_put_contents("/var/log/sefin-erro.txt", $e->rawResponse);
}
```

---

## Eventos customizados

Para tipos de evento que ainda não têm wrapper de alto nível no SDK
(carta de correção, futuros eventos do leiaute), implemente a interface
`EventoNfse` e use `EventoBuilder` diretamente — sem alterar o SDK.

```php
namespace PhpNfseNacional\Dps;

interface EventoNfse
{
    public function chaveAcesso(): string;       // 50 dígitos
    public function codigoTipoEvento(): string;  // 6 dígitos (ex: '101103')
    public function nSequencial(): int;          // geralmente 1
    public function descricao(): string;         // <xDesc>
    /** @return array<string, string>            child elements do <e{tipoEvento}> */
    public function camposGrupo(): array;
}

final class EventoBuilder
{
    public function __construct(Config $config);
    public function build(EventoNfse $evento): string;   // XML cru, ainda sem assinar
}
```

```php
final class MeuEventoFuturo implements EventoNfse {
    public function __construct(public readonly string $chave) {}
    public function chaveAcesso(): string { return $this->chave; }
    public function codigoTipoEvento(): string { return '101199'; }
    public function nSequencial(): int { return 1; }
    public function descricao(): string { return 'Meu evento'; }
    public function camposGrupo(): array { return ['campoX' => 'Y']; }
}

$xmlCru = (new EventoBuilder($config))->build(new MeuEventoFuturo($chave));
$xmlAss = $signer->sign($xmlCru, 'infPedReg');
$resp   = $sefinClient->postEvento(
    $endpoints->cancelarNfse($chave),  // mesmo URL pra qualquer evento
    $xmlAss,
);
```

---

## Emissão retroativa (~contingência)

O SefinNacional 1.6 **não tem flag dedicada de contingência** (diferente da NF-e). Cenários offline são resolvidos enviando o `dhEmi` no passado, com `dCompet` acompanhando.

### Como usar

Passe `dataEmissao` no `Identificacao` (e `dataCompetencia` igual ou anterior — NÃO pode ser posterior, senão E0015):

```php
use PhpNfseNacional\DTO\Identificacao;

$tz = new DateTimeZone('America/Sao_Paulo');
$ontem = new DateTimeImmutable('yesterday 14:30', $tz);

$identificacao = new Identificacao(
    numeroDps: 12345,
    dataCompetencia: $ontem,
    dataEmissao: $ontem,
);
$resp = $nfse->emitir($identificacao, $tomador, $servico, $valores);
```

Sem `dataEmissao`, o SDK gera `dhEmi` em `now()` SP recuado 60s (default).

### Limites empíricos

Não há limite "fixo" de dias. O SEFIN aceita `dhEmi` retroativo **até onde o convênio do município E a parametrização tributária estavam vigentes naquela data**. Validado em homologação 13/05/2026 pro cartório de Sinop:

| `dhEmi` | Resultado | cStat | Causa |
|---|---|---|---|
| Hoje a -63d | ✅ EMITIDA | 100 | convênio + parametrização atuais (Sinop conveniou em 11/mar/2026) |
| -64d (dedução) | ❌ rejeitada | 440 | `tipo de dedução não permitida pelo município de incidência` — parametrização daquela data não permitia `vDR` |
| -65d em diante | ❌ rejeitada | 38 | `convênio do município emissor não estava ATIVO` |
| -365d (1 ano) | ❌ rejeitada | 38 | idem |

### Erros comuns ao emitir retroativo

| cStat | Mensagem | Como resolver |
|---|---|---|
| `15` | "data de competência informada na DPS não pode ser posterior à data de emissão" | Passe `dataCompetencia` igual ou anterior a `dataEmissao` |
| `38` | "situação do convênio do município emissor … deve ser ATIVO" | Município ainda não estava conveniado naquela data — não dá pra emitir |
| `440` | "tipo de dedução/redução … não é permitida pelo município de incidência" | Parametrização tributária histórica não permitia o `vDR`/regime usado — verifique o cadastro vigente daquela data |

### Quando usar

- DPS gerada em sistema offline e enviada quando a rede voltou
- Replay de NFS-e que falhou em outro provedor
- Backfill de período que ficou sem emissão (até o limite do convênio)

> **Atenção:** mesmo com retroativo, o `dhProc` (data de processamento na resposta) é a hora real do servidor SEFIN. Não dá pra "antedatar" a NFS-e oficialmente — só registrar quando a operação aconteceu (`dhEmi`/`dCompet`).

---

## Apêndice — OpenSSL legacy provider

OpenSSL **3.5+** (Fedora 43, RHEL 9 atualizado) **desabilita SHA1 por padrão**.
A DPS do SefinNacional exige `rsa-sha1` — sem habilitar legacy, `openssl_sign`
falha com `error:03000098:digital envelope routines::invalid digest`.

**Opção 1 (recomendada em produção):** variável de ambiente.

Crie `/etc/ssl/openssl-sha1.cnf`:

```ini
openssl_conf = openssl_init
[openssl_init]
providers = provider_sect
[provider_sect]
default = default_sect
legacy = legacy_sect
[default_sect]
activate = 1
[legacy_sect]
activate = 1
```

Setar no Supervisor / php-fpm:

```ini
environment=OPENSSL_CONF=/etc/ssl/openssl-sha1.cnf
```

**Opção 2 (dev/local):** runtime helper.

```php
use PhpNfseNacional\Certificate\Signer;
Signer::habilitarLegacyProviderRuntime();   // chamar UMA vez no bootstrap
```

Em OpenSSL < 3.0 nenhuma das opções é necessária — `legacy` é o default.
