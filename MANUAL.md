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
   - [Download](#download) — `baixarXml / baixarPdf` (com retry)
   - [Verificação idempotente](#verificação-idempotente) — `$nfse->verificarDps()`
   - [Listagem de eventos](#listagem-de-eventos-por-nfs-e) — `$nfse->listarEventos()`
   - [Distribuição DFe](#distribuição-dfe-caixa-postal) — `$nfse->sincronizarDfe()`
   - [DANFSe local](#danfse-local) — `$nfse->danfseLocal()`
4. [Tipos de retorno](#tipos-de-retorno)
   - [`SefinResposta`](#sefinresposta)
   - [`RespostaDfe` + `ItemDfe`](#respostadfe--itemdfe) — caixa postal CNPJ
5. [DTOs de entrada](#dtos-de-entrada)
   - [`Identificacao`](#identificacao)
   - [`Tomador`](#tomador)
   - [`Intermediario`](#intermediario) — marketplace/plataforma
   - [`Servico`](#servico) + grupos opcionais (`comExt`, `obra`, `atvEvento`, `infoCompl`)
   - [`Valores`](#valores) + grupos opcionais (`<tribMun>`, `<tribFed/piscofins>`, deduções com docs)
6. [Enums](#enums) — 20+ enums tipados (LC 116, NBS, retenção, tributação, etc.)
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
        public readonly string $cep,                  // formatos: '01310100' ou '01310-100'
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
    servico:       new Servico('Certidão de matrícula', codigoMunicipioPrestacao: '3550308'),
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
$resp = $nfse->consultar('35503082212345678000195000000000005712345678901234');

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
    '35503082212345678000195000000000005712345678901234',
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

> **Validado em homologação SEFIN (13/05/2026):**
> - ✅ **Confirmação do Prestador** (e202201) — cStat=100, NFS-e #72
> - ✅ **Rejeição do Prestador** (e202205, motivo Duplicidade) — cStat=100, NFS-e #73
> - ⚠️ **Anulação da Rejeição** (e205208) — cStat=999 ("Falha de configuração"). Provavelmente parametrização do município ainda não habilita esse evento em homologação. Outros municípios podem ter habilitado — teste no seu.

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
$nfse->baixarXml(string $chaveAcesso): string                              // XML autorizado
$nfse->baixarPdf(string $chaveAcesso, int $tentativas = 3): string         // bytes do PDF, com retry
```

| Método | Onde busca | Observação |
|---|---|---|
| `baixarXml` | SEFIN Nacional `/nfse/{chave}` | XML completo (DPS + assinaturas + autorização) |
| `baixarPdf` | ADN `/danfse/{chave}` | **Retry exponencial em 502/503/504** (1.5s, 3.0s, 4.5s). **A partir de 01/07/2026 ADN desativa esse endpoint** — use [`danfseLocal()`](#danfse-local) |

`baixarPdf` retenta automaticamente em códigos HTTP transientes (502/503/504) e em erros de conexão. O backoff é exponencial: `1.5s × n`. Após esgotar `$tentativas` lança `SefinException`. Em 4xx (404, etc.) lança imediatamente — esses não são transientes.

**Erros:**
- `ValidationException` — chave inválida
- `RuntimeException` — chave válida mas SEFIN não devolveu XML
- `SefinException` — HTTP ≠ 200 (4xx imediato; 5xx só após esgotar retries), ou conteúdo não é PDF (`baixarPdf` valida magic bytes `%PDF`)

```php
$xml = $nfse->baixarXml($chave);
file_put_contents("/var/nfse/{$chave}.xml", $xml);

$pdf = $nfse->baixarPdf($chave);  // ou com tentativas customizado: baixarPdf($chave, 5)
file_put_contents("/var/nfse/{$chave}.pdf", $pdf);
```

### Verificação idempotente

```php
$nfse->verificarDps(string $idDps): bool
```

Usa `HEAD /dps/{id}` no SEFIN — leve, sem baixar o corpo. Retorna `true` se o DPS já existe (HTTP 200), `false` se não existe (HTTP 404). Outros códigos lançam `SefinException`.

Útil pra evitar dupla emissão (cliente que retenta agressivamente, sequencial reutilizado em conflito, processo recuperando-se de crash). Chame antes de `emitir()`:

```php
$idDps = 'DPS35503082212345678000195000010000000001285AB';

if ($nfse->verificarDps($idDps)) {
    // já existe — não emite, consulta o status:
    $resp = $nfse->consultarDps($idDps);
} else {
    $resp = $nfse->emitir(...);
}
```

### Listagem de eventos por NFS-e

```php
$nfse->listarEventos(string $chaveAcesso): array
```

Retorna **todos** os eventos vinculados a uma NFS-e — cancelamento, substituição, manifestações (confirmar/rejeitar/anular). Útil para auditoria.

Diferente de `consultarEventos()` que aceita filtros (`tipoEvento`, `nSequencial`), aqui é tudo de uma vez. Endpoint: `GET /contribuintes/NFSe/{chave}/Eventos` no ADN.

```php
$eventos = $nfse->listarEventos($chave);
foreach ($eventos as $ev) {
    echo "Tipo {$ev['tipoEvento']}, seq {$ev['nSeqEvento']}, dh {$ev['dhRegEvento']}\n";
}
```

Retorna `array<int, mixed>` cru — o caller faz o parse conforme a necessidade.

### Distribuição DFe (caixa postal)

```php
$nfse->sincronizarDfe(int $ultimoNsu = 0, int $maxPaginas = 20): RespostaDfe
```

O SEFIN mantém uma "caixa postal" por CNPJ onde guarda eventos vinculados: NFS-es emitidas **contra** o CNPJ (tomador), cancelamentos recebidos, substituições. O método itera por NSU (Número Sequencial Único) consumindo lotes paginados.

Para sincronização incremental, persista `$ultimoNsu` da última chamada bem-sucedida:

```php
use PhpNfseNacional\Sefin\{ItemDfe, RespostaDfe};

$ultimoNsuConhecido = (int) $cache->get('dfe_ultimo_nsu', 0);

$resp = $nfse->sincronizarDfe($ultimoNsuConhecido);

/** @var ItemDfe $item */
foreach ($resp->itens as $item) {
    $item->nsu;              // 42 — usado pra paginação
    $item->tipoDocumento;    // 'NFS-e' / 'Evento' / etc.
    $item->chaveAcesso;      // 50 dígitos (quando aplicável)
    $item->tipoEvento;       // '101101', '105102', etc.
    $item->sequencialEvento; // 1..99
    $item->dataHora;         // ISO 8601
    $item->bruto;            // array raw do ADN (inspeção)
}

$resp->ultimoNsu;            // 50 — passar na próxima chamada
$resp->statusProcessamento;  // 'NenhumDocumentoLocalizado' / 'ProcessamentoNormal'
$resp->temMais;              // true se atingiu maxPaginas (= mais DFes pendentes)
$resp->vazio();              // === count($resp->itens) === 0

$cache->set('dfe_ultimo_nsu', $resp->ultimoNsu);
```

**Limites:** `maxPaginas` default 20 → até 1000 DFes/chamada (ADN devolve ~50 por página). Acima disso, faça loop externo controlando `$ultimoNsu` entre as chamadas.

**Quando `temMais === true`**: você atingiu o teto de páginas mas ainda há DFes pendentes. Cache o `ultimoNsu` e chame de novo (pode usar `setTimeout` ou um job assíncrono).

### DANFSe local

> **⚠ Em refino.** Esse renderizador local é a implementação da **NT 008/2026** no SDK e **ainda está em maturação** — ajustes de leiaute, posicionamento, blocos opcionais e edge cases continuam saindo a cada release minor/patch. Bugs já corrigidos: tarja indevida em produção via Sistema Nacional (v0.18.1), grid do cabeçalho (v0.10.1), labels `tribISSQN` invertidos (v0.9.1), entre outros.
>
> **Em produção, prefira [`baixarPdf($chave)`](#download-de-xml-e-pdf)** (PDF gerado pelo próprio SEFIN/ADN). Use o `danfseLocal()` para: (a) fallback quando o ADN está instável (HTTP 502 já visto em homologação), (b) customização (logo do prestador, observações), (c) **após 01/07/2026**, quando o ADN desativa o endpoint `/danfse/{chave}` e a geração local vira o caminho oficial.
>
> Quando usar em produção, mantenha o SDK atualizado e teste o PDF gerado contra o leiaute oficial antes de imprimir/enviar pra cliente.

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

> ⚠️ **`$nfse->consultar($chave)->cancelada()` NÃO detecta cancelamento de NFS-e.** O método verifica `cStat ∈ {101, 102, 135, 155}` — mas o `consultar()` retorna sempre cStat=100 (autorizada) mesmo após o cancelamento, porque o cancelamento é um EVENTO separado, não muda o cStat da NFS-e original.
>
> Para detectar cancelamento de uma NFS-e específica, use [`$nfse->estaCancelada($chave)`](#listagem-de-eventos-por-nfs-e) (busca eventos no ADN) ou [`$resp->foiCancelada($chave)`](#respostadfe--itemdfe) sobre um lote DFe.

### `RespostaDfe` + `ItemDfe`

Retornados por [`$nfse->sincronizarDfe()`](#distribuição-dfe-caixa-postal). Representam o lote agregado da caixa postal do CNPJ.

```php
namespace PhpNfseNacional\Sefin;

final class ItemDfe
{
    public function __construct(
        public readonly int     $nsu,
        public readonly ?string $tipoDocumento,   // "NFSE" | "EVENTO"
        public readonly ?string $chaveAcesso,
        public readonly ?string $tipoEvento,      // "CANCELAMENTO" | "CONFIRMACAO_PRESTADOR" | ...
        public readonly ?int    $sequencialEvento,
        public readonly ?string $dataHora,        // ISO 8601 (`DataHoraGeracao`)
        public readonly ?string $arquivoXmlGzipB64, // XML completo embutido
        public readonly array   $bruto,           // payload raw do ADN
    );

    public function arquivoXmlDecodificado(): ?string;  // descomprime gzip+base64 sob demanda
}

final class RespostaDfe
{
    public const STATUS_EMITIDA = 'EMITIDA';
    public const STATUS_CANCELADA = 'CANCELADA';
    public const STATUS_SUBSTITUIDA = 'SUBSTITUIDA';
    public const STATUS_CONFIRMADA = 'CONFIRMADA';
    public const STATUS_REJEITADA = 'REJEITADA';

    public function __construct(
        public readonly array  $itens,         // ItemDfe[]
        public readonly int    $ultimoNsu,     // persistir pra próxima sincronização
        public readonly ?string $statusProcessamento, // "DOCUMENTOS_LOCALIZADOS" | "NenhumDocumentoLocalizado"
        public readonly bool   $temMais,       // true se atingiu maxPaginas
    );

    public function vazio(): bool;
    public function quantidade(): int;

    // Filtros por tipo
    public function itensNfse(): array;        // só NFSE
    public function itensEvento(): array;      // só EVENTO

    // Listas por status (operam sobre o lote em memória, zero HTTP)
    public function chavesCanceladas(): array;    // chaves c/ evento CANCELAMENTO
    public function chavesSubstituidas(): array;
    public function chavesConfirmadas(): array;
    public function chavesRejeitadas(): array;

    // Lookup por chave
    public function foiCancelada(string $chave): bool;
    public function eventosDaChave(string $chave): array;  // ItemDfe[] dos eventos
    public function statusPorChave(string $chave): ?string; // hierarquia: SUBSTITUIDA > CANCELADA > REJEITADA > CONFIRMADA > EMITIDA

    // Agregação
    public function agruparPorChave(): array;  // ['chave' => ['CANCELAMENTO', ...]]
}
```

> **Importante: o `ArquivoXml` vem embutido em cada item** (gzip+base64). Use `$item->arquivoXmlDecodificado()` para obter o XML completo sem chamar `baixarXml()` separadamente — economiza N round-trips ao processar lote.

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
        public readonly ?DateTimeImmutable  $dataEmissao = null,   // override de dhEmi
    );
}
```

`numeroDps` é gerado pela aplicação cliente (geralmente formato `AANNNNNN` = ano + 6 sequenciais). Não há gerador automático no SDK.

Quando `dataCompetencia` é `null`, o `DpsBuilder` deriva o `<dCompet>` da mesma data resolvida do `<dhEmi>` (em `America/Sao_Paulo`). Isso evita uma classe de bug que aparecia na virada do dia em SP, em que `dCompet` saltava pro dia novo enquanto o `dhEmi` recuado em -60s (margem de drift) ainda ficava no dia anterior — SEFIN rejeitava com `cStat=15` (`dCompet > dhEmi.date`). Fix em v0.17.0.

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
        public readonly string $discriminacao,                     // 10..2000 chars
        public readonly string $codigoMunicipioPrestacao,          // IBGE 7 dígitos
        ListaServicosNacional|string $cTribNac = '210101',         // LC 116 — aceita enum ou string
        ListaNbs|string $cNBS = '113040000',                       // NBS — aceita enum ou string
        public readonly string $cIndOp = '100301',
        public readonly ?InformacoesComplementares $infoCompl = null, // <serv/infoCompl>
        public readonly ?ComercioExterior $comExt = null,          // <serv/comExt> — obrigatório em exportação
        public readonly ?InformacaoObra $obra = null,              // <serv/obra> — construção civil
        public readonly ?AtividadeEvento $atvEvento = null,        // <serv/atvEvento> — shows, conferências
    );
}
```

#### Grupos opcionais (v0.13.0+)

- **`infoCompl`** → `<infoCompl>` com `xInfComp`, `idDocTec`, `docRef`. Posicionado como ÚLTIMO filho de `<serv>`. Veja [InformacoesComplementares](#informacoescomplementares).
- **`comExt`** → `<comExt>` (Comércio Exterior). **OBRIGATÓRIO quando `Valores::$tributacaoIssqn = ExportacaoServico`** (sem ele dá cStat=330). Veja [ComercioExterior](#comercioexterior).
- **`obra`** → `<obra>` para serviços de construção civil. Choice entre `cObra` (CNO/CEI), `cCIB` ou endereço. Veja [InformacaoObra](#informacaoobra).
- **`atvEvento`** → `<atvEvento>` para shows, conferências, eventos. Veja [AtividadeEvento](#atividadeevento).

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

> **Cartório de RI:** sempre `'210101'` (Serviços de registros públicos cartorários e notariais). NUNCA `'140101'` (lubrificação/limpeza — bug histórico do legado já corrigido).

### `Intermediario`

```php
final class Intermediario
{
    public function __construct(
        string $documento,                       // CPF (11) ou CNPJ (14)
        public readonly string $nome,            // 1..150 chars
        public readonly ?Endereco $endereco = null,
        public readonly ?string $email = null,
        public readonly ?string $telefone = null,
        public readonly ?string $inscricaoMunicipal = null,
    );
}
```

Marketplaces, plataformas de delivery, agências de turismo. Passado como parâmetro opcional em `$nfse->emitir(..., intermediario: $i)`. Grupo `<interm>` é posicionado entre `<toma>` e `<serv>`.

### `InformacoesComplementares`

```php
final class InformacoesComplementares
{
    public function __construct(
        public readonly ?string $xInfComp = null,   // texto livre, até 2000 chars
        public readonly ?string $idDocTec = null,   // ART/RRT/DRT, até 40 chars
        public readonly ?string $docRef = null,     // ref a doc externo, até 255 chars
    );
}
```

Passada como `Servico::$infoCompl`. Pelo menos 1 campo deve estar preenchido (vazios são rejeitados — use `null`).

### `ComercioExterior`

```php
final class ComercioExterior
{
    public function __construct(
        public readonly ModoPrestacao $modoPrestacao,
        public readonly VinculoEntrePartes $vinculoEntrePartes,
        public readonly string $codigoMoeda,         // 3 dígitos BACEN: 220=USD, 978=EUR, 790=BRL
        public readonly float $valorServicoMoeda,    // valor na moeda estrangeira
        public readonly MecanismoFomentoPrestador $mecanismoFomentoPrestador,
        public readonly MecanismoFomentoTomador $mecanismoFomentoTomador,
        public readonly MovimentacaoTemporariaBens $movimentacaoTemporariaBens,
        public readonly EnvioMdic $envioMdic = EnvioMdic::NaoEnviar,
        public readonly ?string $numeroDeclaracaoImportacao = null,
        public readonly ?string $numeroRegistroExportacao = null,
    );
}
```

**Obrigatório quando `Valores::$tributacaoIssqn = ExportacaoServico`** — sem `<comExt>` SEFIN devolve cStat=330.

> **⚠ Achado importante: `codigoMoeda` exige código BACEN numérico**, não ISO 4217 alfa. USD = `'220'` (não `'USD'`). Pattern XSD `TSCodMoeda` é `[0-9]{3}`. Tentar `'USD'` causa cStat=1235.

### `InformacaoObra`

```php
final class InformacaoObra
{
    public function __construct(
        public readonly ?string $inscricaoImobiliariaFiscal = null,
        // choice obrigatório:
        public readonly ?string $codigoObra = null,   // CNO ou CEI legacy
        public readonly ?string $codigoCib = null,    // Cadastro Imobiliário Brasileiro
        public readonly ?Endereco $endereco = null,
    );
}
```

Choice (XOR) entre `codigoObra`, `codigoCib` ou `endereco` — exatamente 1 obrigatório.

### `AtividadeEvento`

```php
final class AtividadeEvento
{
    public function __construct(
        public readonly string $nome,                  // descrição do evento, até 255 chars
        public readonly DateTimeImmutable $dataInicio,
        public readonly DateTimeImmutable $dataFim,    // >= dataInicio
        // choice obrigatório:
        public readonly ?string $idAtividadeEvento = null,  // código da Administração Municipal
        public readonly ?Endereco $endereco = null,
    );
}
```

### `BeneficioMunicipal`

```php
final class BeneficioMunicipal
{
    public function __construct(
        public readonly string $nBM,                       // 14 dígitos — ID parametrizado pelo município
        public readonly ?float $valorReducaoBc = null,     // choice: monetário ou percentual
        public readonly ?float $percentualReducaoBc = null,
    );
}
```

Composição do `nBM` (14 dígitos): 7 dig IBGE município + 2 dig tipo (01-04) + 5 dig sequencial. Use `valorReducaoBc` OU `percentualReducaoBc` (XOR).

### `ExigibilidadeSuspensa`

```php
final class ExigibilidadeSuspensa
{
    public function __construct(
        public readonly TipoExigibilidadeSuspensa $tipo,   // ProcessoJudicial | ProcessoAdministrativo
        public readonly string $numeroProcesso,            // EXATAMENTE 30 dígitos (XSD [0-9]{30})
    );
}
```

> **⚠ Achado: `numeroProcesso` exige 30 dígitos**, não CNJ (20) nem Receita Federal (17). Confirmado contra `docs/schemas/1.01/tiposSimples_v1.01.xsd` — pattern `TSNumProcExigSuspensa = [0-9]{30}`. Convenção comum: CNJ + 10 zeros de padding.

### `DocumentoDeducao`

```php
final class DocumentoDeducao
{
    public function __construct(
        public readonly TipoDeducaoReducao $tipo,                 // 9 cases (Materiais, Subempreitada, etc.)
        public readonly DateTimeImmutable $dataEmissaoDocumento,
        public readonly float $valorDedutivel,                    // valor total do documento
        public readonly float $valorDeducao,                      // <= valorDedutivel
        // choice obrigatório:
        public readonly ?string $chaveNfse = null,                // 50 dígitos
        public readonly ?string $chaveNfe = null,                 // 44 dígitos
        public readonly ?string $numeroDocumento = null,          // texto livre até 255
        public readonly ?string $descricaoOutraDeducao = null,    // obrigatório se tipo=Outras
    );
}
```

Usado em `Valores::$documentosDeducao` (array). **Choice com `$deducoesReducoes`** no schema — não use ambos.

### `TributacaoPisCofins`

```php
final class TributacaoPisCofins
{
    public function __construct(
        public readonly CstPisCofins $cst,                       // CST 00-09
        public readonly ?float $valorBaseCalculo = null,
        public readonly ?float $aliquotaPis = null,              // 0-100
        public readonly ?float $aliquotaCofins = null,
        public readonly ?float $valorPis = null,
        public readonly ?float $valorCofins = null,
        public readonly ?TipoRetencaoPisCofins $tipoRetencao = null, // Retido | NaoRetido
    );
}
```

Usado em `Valores::$tributacaoPisCofins`. Para serviço sem incidência PIS/COFINS, basta passar `cst: CstPisCofins::OperacaoSemIncidenciaContribuicao` e omitir os demais.

### `Valores`

```php
final class Valores
{
    public function __construct(
        public readonly float $valorServicos,                // > 0
        public readonly float $deducoesReducoes,             // 0 .. valorServicos (choice c/ $documentosDeducao)
        public readonly float $aliquotaIssqnPercentual,      // 0..10 — vai pra <pTotTribMun>
        public readonly TipoRetencaoIssqn $tipoRetencaoIssqn = TipoRetencaoIssqn::NaoRetido,
        public readonly float $descontoIncondicionado = 0.0,
        public readonly ?MotivoDispensaIssqn $motivoDispensaIssqn = null,
        // <tribMun>
        public readonly ?TipoTributacaoIssqn $tributacaoIssqn = null,    // <tribISSQN> — default 1 (Tributável)
        public readonly ?string $codigoPaisResultado = null,             // <cPaisResult> — exportação
        public readonly ?BeneficioMunicipal $beneficioMunicipal = null,  // <BM>
        public readonly ?ExigibilidadeSuspensa $exigibilidadeSuspensa = null, // <exigSusp>
        public readonly ?TipoImunidadeIssqn $imunidade = null,           // <tpImunidade>
        public readonly ?float $aliquotaMunicipal = null,                // <pAliq> — municípios não-conveniados
        // <vDedRed>
        public readonly array $documentosDeducao = [],                   // <documentos><docDedRed>... (choice c/ $deducoesReducoes)
        // <tribFed>
        public readonly ?TributacaoPisCofins $tributacaoPisCofins = null, // <tribFed/piscofins>
        public readonly ?float $valorRetidoIrrf = null,                  // <vRetIRRF>
        public readonly ?float $valorRetidoCp = null,                    // <vRetCP>
        public readonly ?float $valorRetidoCsll = null,                  // <vRetCSLL>
    );

    public function baseCalculo(): float;   // valorServicos − descIncond − deducoesReducoes
    public function valorIssqn(): float;    // baseCalculo × alíquota / 100, arredondado 2 casas
}
```

#### Campos novos (resumo desde v0.10)

| Campo | Vai pra | Notas |
|---|---|---|
| `$tipoRetencaoIssqn` | `<tpRetISSQN>` | enum 3 estados: NaoRetido, RetidoPeloTomador, RetidoPeloIntermediario. **BC-break v0.14.0** (era `bool $issqnRetido`) |
| `$motivoDispensaIssqn` | `<indTotTrib>0</indTotTrib>` | enum 4 cases pra justificar dispensa. Null = sem dispensa (emite `<pTotTrib>`). **BC-break v0.14.0** (era `bool $dispensadoIssqn`). Aceito só pra optantes SN — Não Optante recebe cStat=713 |
| `$tributacaoIssqn` | `<tribISSQN>` | 1=Tributável (default), 2=Imunidade, 3=Exportação, 4=NãoIncidência |
| `$imunidade` | `<tpImunidade>` | Aplicável quando `tributacaoIssqn=2`. Enum 6 cases (CF 150 VI) |
| `$codigoPaisResultado` | `<cPaisResult>` | 2 chars ISO. Aplicável quando `tributacaoIssqn=3` |
| `$beneficioMunicipal` | `<BM>` | DTO com `nBM` (14 dig) + choice `vRedBCBM\|pRedBCBM` |
| `$exigibilidadeSuspensa` | `<exigSusp>` | Suspensão judicial/administrativa. `numeroProcesso` exige `[0-9]{30}` (XSD `TSNumProcExigSuspensa`) |
| `$aliquotaMunicipal` | `<pAliq>` | Alíquota efetiva. Só necessário em municípios NÃO conveniados |
| `$documentosDeducao` | `<documentos>/<docDedRed>` | Array de `DocumentoDeducao`. **Choice com `$deducoesReducoes`** — XOR validado |
| `$tributacaoPisCofins` | `<tribFed/piscofins>` | CST + BC + alíquotas PIS/COFINS + indicação de retenção |
| `$valorRetidoIrrf` | `<vRetIRRF>` | Retenção federal flat |
| `$valorRetidoCp` | `<vRetCP>` | Contribuição Previdenciária retida |
| `$valorRetidoCsll` | `<vRetCSLL>` | CSLL retida |

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
> Validado em homologação 13/05/2026 (NFS-es #61 e #62): mesmo enviando `pTotTribMun=3.51` ou `3.56`, SEFIN aplicou `pAliqAplic=4.00` (alíquota oficial do município pra LC 116 item 21.01) e calculou ISSQN sobre 4%.
>
> Implicação prática: você não precisa "acertar" a alíquota tributária no DPS — basta enviar uma estimativa razoável da carga total. Em cartório de RI fica tipicamente `4.00`. Pra outros segmentos, consulte a alíquota cadastrada pelo município.

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

enum RegimeApuracaoSimplesNacional: int {
    case FederaisEMunicipalPorSN          = 1;  // MEI: tudo via DAS
    case FederaisPorSnMunicipalPorNfse    = 2;
    case FederaisEMunicipalPorNfse        = 3;
}

// ─── Tributação ISSQN (`<tribMun>`) ───

enum TipoTributacaoIssqn: int {
    case OperacaoTributavel = 1;   // default
    case Imunidade          = 2;
    case ExportacaoServico  = 3;
    case NaoIncidencia      = 4;
}

enum TipoImunidadeIssqn: int {
    case NaoInformado                          = 0;
    case PatrimonioRendaServicosEntes          = 1; // CF 150 VI a
    case TemplosQualquerCulto                  = 2; // CF 150 VI b
    case PartidosSindicatosEducacaoAssistencia = 3; // CF 150 VI c
    case LivrosJornaisPeriodicosPapel          = 4; // CF 150 VI d
    case FonogramasVideofonogramasMusicaisBR   = 5; // CF 150 VI e
}

enum TipoRetencaoIssqn: int {
    case NaoRetido                = 1;  // default
    case RetidoPeloTomador        = 2;
    case RetidoPeloIntermediario  = 3;
}

enum MotivoDispensaIssqn: string {
    case OptanteSimplesNacional = 'OPTANTE_SIMPLES_NACIONAL';
    case OperacaoImune          = 'OPERACAO_IMUNE';
    case OperacaoIsenta         = 'OPERACAO_ISENTA';
    case Outros                 = 'OUTROS';
}

enum TipoExigibilidadeSuspensa: int {
    case ProcessoJudicial       = 1;
    case ProcessoAdministrativo = 2;
}

enum TipoBeneficioMunicipal: int {
    case Isencao              = 1;
    case ReducaoBcPercentual  = 2;
    case ReducaoBcValor       = 3;
    case AliquotaDiferenciada = 4;
}

// ─── Deduções / Tributação Federal ───

enum TipoDeducaoReducao: string {
    case AlimentacaoBebidasFrigobar = '01';
    case Materiais                  = '02';
    case ProducaoExterna            = '03';
    case ReembolsoDespesas          = '04';
    case RepasseConsorciado         = '05';
    case RepassePlanoSaude          = '06';
    case Servicos                   = '07';
    case SubempreitadaMaoObra       = '08';
    case Outras                     = '99';  // exige descricaoOutraDeducao
}

enum CstPisCofins: string {
    case Nenhum                                          = '00';
    case OperacaoTributavelAliquotaBasica                = '01';
    case OperacaoTributavelAliquotaDiferenciada          = '02';
    case OperacaoTributavelAliquotaPorUnidadeMedida      = '03';
    case OperacaoTributavelMonofasicaRevendaAliquotaZero = '04';
    case OperacaoTributavelSubstituicaoTributaria        = '05';
    case OperacaoTributavelAliquotaZero                  = '06';
    case OperacaoTributavelContribuicao                  = '07';
    case OperacaoSemIncidenciaContribuicao               = '08';
    case OperacaoComSuspensaoContribuicao                = '09';
}

enum TipoRetencaoPisCofins: int {
    case Retido    = 1;
    case NaoRetido = 2;
}

// ─── Comércio Exterior (`<comExt>`) ───

enum ModoPrestacao: int {
    case Desconhecido                       = 0;
    case Transfronteirico                   = 1;
    case ConsumoNoBrasil                    = 2;
    case MovimentoTemporarioPessoasFisicas  = 3;
    case ConsumoNoExterior                  = 4;
}

enum VinculoEntrePartes: int {
    case SemVinculo       = 0;
    case Controlada       = 1;
    case Controladora     = 2;
    case Coligada         = 3;
    case Matriz           = 4;
    case FilialOuSucursal = 5;
    case OutroVinculo     = 6;
    case Desconhecido     = 9;
}

enum MecanismoFomentoPrestador: string {
    case Desconhecido         = '00';
    case Nenhum               = '01';
    case Acc                  = '02';  // Adiantamento sobre Contrato de Câmbio
    case Ace                  = '03';  // Adiantamento sobre Cambiais Entregues
    case BndesEximPosEmbarque = '04';
    case BndesEximPreEmbarque = '05';
    case Fge                  = '06';  // Fundo de Garantia à Exportação
    case ProexEqualizacao     = '07';
    case ProexFinanciamento   = '08';
}

enum MecanismoFomentoTomador: string {
    // 26 cases — RECINE, RECOPA, REIDI, ZPE, etc.
    // Quando em dúvida: Nenhum = '01'
    case Nenhum = '01';
    // ... ver Enums/MecanismoFomentoTomador.php pra lista completa
}

enum MovimentacaoTemporariaBens: int {
    case Desconhecido                  = 0;
    case Nao                           = 1;
    case VinculadaDeclaracaoImportacao = 2;
    case VinculadaDeclaracaoExportacao = 3;
}

enum EnvioMdic: int {
    case NaoEnviar = 0;
    case Enviar    = 1;
}

// ─── Tabelas oficiais (LC 116 + NBS) ───

enum ListaServicosNacional: string {
    // 335 cases — códigos cTribNac de 6 dígitos (item LC 116 + subitem + desdobro)
    case S010101 = '010101';  // Análise e desenvolvimento de sistemas
    case S010201 = '010201';  // Programação
    case S210101 = '210101';  // Serviços notariais e de registro
    // ... ver Enums/ListaServicosNacional.php

    public function descricao(): string;     // texto completo do serviço
    public function item(): string;          // dígitos 1-2 (grupo LC 116)
    public function subitem(): string;       // dígitos 3-4
    public function desdobro(): string;      // dígitos 5-6
}

enum ListaNbs: string {
    // 917 cases — códigos cNBS de 9 dígitos (S.DDGG.CC.SS)
    case N113040000 = '113040000';
    // ... ver Enums/ListaNbs.php

    public function descricao(): string;
    public function secao(): string;         // dígito 1
    public function divisao(): string;       // dígitos 2-3
    public function grupo(): string;         // dígitos 4-5
    public function classe(): string;        // dígitos 6-7
    public function subclasse(): string;     // dígitos 8-9
}

// ─── DTO/Enums já existentes (mantidos para referência) ───

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

### As duas datas do DPS

| Campo XML | O que significa | Quem gera |
|---|---|---|
| `<dhEmi>` | Data/hora em que **a DPS foi gerada** pelo emissor (datetime ISO 8601 com timezone) | Você (o caller) |
| `<dCompet>` | Data de **competência fiscal** do serviço — em que mês/dia entra na apuração do ISSQN | Você (o caller) |
| `<dhProc>` | Data/hora em que **o SEFIN processou** a DPS (resposta) | SEFIN, não é enviado |

Regra cruzada: `dCompet ≤ dhEmi.date`. Se violar, SEFIN rejeita com `cStat=15`.

### Cenários práticos

| Cenário | `dataEmissao` (DTO) | `dataCompetencia` (DTO) | Resultado no XML |
|---|---|---|---|
| **Emissão online normal** | omitido (null) | omitido (null) | `dhEmi = now() − 60s` em SP, `dCompet` derivado do mesmo dia |
| **Cobrança da competência do mês anterior, emitida hoje** | omitido (null) | `2026-04-30` | `dhEmi = now() − 60s` (hoje), `dCompet = 2026-04-30` |
| **Contingência: DPS gerada offline ontem 14:30, enviada hoje** | `'yesterday 14:30' SP` | omitido (null) | `dhEmi` = ontem 14:30, `dCompet` derivado = data de ontem (deriva do `dhEmi` automaticamente) |
| **Backfill com competência diferente do dhEmi** | `'2026-05-10 09:00' SP` | `'2026-04-30' SP` | `dhEmi` e `dCompet` ambos do passado, independentes |

```php
use PhpNfseNacional\DTO\Identificacao;

$tz = new DateTimeZone('America/Sao_Paulo');

// Cenário "competência anterior, emissão hoje" — caso comum em cobrança mensal
$idCobranca = new Identificacao(
    numeroDps: 12345,
    dataCompetencia: new DateTimeImmutable('2026-04-30', $tz),
    // dataEmissao omitido — SDK usa now() − 60s
);

// Cenário "contingência" — DPS gerada ontem, enviada hoje
$ontem = new DateTimeImmutable('yesterday 14:30', $tz);
$idContingencia = new Identificacao(
    numeroDps: 12346,
    dataEmissao: $ontem,
    // dataCompetencia omitido — SDK deriva da data do dhEmi (= ontem)
);

$resp = $nfse->emitir($idContingencia, $tomador, $servico, $valores);
```

> Importante: quando `dataCompetencia` é `null`, o SDK deriva o `<dCompet>` da mesma data resolvida do `<dhEmi>`. Isso evita uma classe de bug que aparecia na virada do dia em SP (entre `00:00:00` e `00:00:59`) — a margem de `-60s` jogava o `dhEmi` pro dia anterior enquanto um `new DateTimeImmutable()` independente para o `dCompet` ficava no dia novo, e SEFIN rejeitava com E0015. Fix em **v0.17.0**.

### Limites empíricos

Não há limite "fixo" de dias. O SEFIN aceita `dhEmi` retroativo **até onde o convênio do município E a parametrização tributária estavam vigentes naquela data**. Validado em homologação maio/2026:

| `dhEmi` | Resultado | cStat | Causa |
|---|---|---|---|
| Hoje a −63d | ✅ EMITIDA | 100 | convênio + parametrização atuais |
| −64d (com dedução) | ❌ rejeitada | 440 | parametrização daquela data não permitia `vDR` |
| −65d em diante | ❌ rejeitada | 38 | convênio do município emissor não estava ATIVO |
| −365d (1 ano) | ❌ rejeitada | 38 | idem |

O ponto de corte (−64d, −65d) é específico do município que testamos — varia por município conforme data em que conveniou ao Sistema Nacional.

### Erros comuns ao emitir retroativo

| cStat | Mensagem | Como resolver |
|---|---|---|
| `15` | "data de competência informada na DPS não pode ser posterior à data de emissão" | Passe `dataCompetencia` igual ou anterior a `dataEmissao`. Em v0.17.0+, omitir `dataCompetencia` é seguro mesmo na virada do dia. |
| `38` | "situação do convênio do município emissor … deve ser ATIVO" | Município ainda não estava conveniado naquela data — não dá pra emitir |
| `440` | "tipo de dedução/redução … não é permitida pelo município de incidência" | Parametrização tributária histórica não permitia o `vDR`/regime usado — verifique o cadastro vigente daquela data |

### Quando usar

- DPS gerada em sistema offline e enviada quando a rede voltou (contingência)
- Replay de NFS-e que falhou em outro provedor
- Backfill de período que ficou sem emissão (até o limite do convênio)
- Cobrança de serviço prestado em competência anterior (caso clássico do mês fiscal — só passa `dataCompetencia`, deixa `dataEmissao` em `now()`)

> **Atenção:** mesmo com retroativo, o `dhProc` (data de processamento na resposta) é a hora real do servidor SEFIN. Não dá pra "antedatar" a NFS-e oficialmente — só registrar quando a operação aconteceu (`dhEmi`/`dCompet`). O DANFSe exibe os dois campos separadamente ("Data e Hora da emissão da DPS" e "Data e Hora da emissão da NFS-e") por exigência da NT 008/2026.

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
