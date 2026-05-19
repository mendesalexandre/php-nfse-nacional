# mendesalexandre/php-nfse-nacional

[![CI](https://github.com/mendesalexandre/php-nfse-nacional/actions/workflows/ci.yml/badge.svg)](https://github.com/mendesalexandre/php-nfse-nacional/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/mendesalexandre/php-nfse-nacional.svg)](https://packagist.org/packages/mendesalexandre/php-nfse-nacional)
[![PHP Version](https://img.shields.io/packagist/php-v/mendesalexandre/php-nfse-nacional.svg)](https://packagist.org/packages/mendesalexandre/php-nfse-nacional)
[![License](https://img.shields.io/packagist/l/mendesalexandre/php-nfse-nacional.svg)](LICENSE)

SDK PHP framework-agnostic para integração com NFS-e Nacional (Padrão
Brasileiro SEFIN). Funciona em Laravel, Symfony, projeto vanilla — qualquer
coisa com PHP 8.1+ e suporte a PSR-3/PSR-18.

📖 **Documentação:**
- Este README — quickstart, instalação, exemplos rápidos
- **[MANUAL.md](MANUAL.md)** — referência completa da API (cada método: assinatura, parâmetros, retorno, exceções)
- [CHANGELOG.md](CHANGELOG.md) — histórico de versões
- [`examples/`](examples/) — scripts ponta-a-ponta (emitir, cancelar, substituir, consultar, download, danfse-local)

## Status

Ciclo de vida da NFS-e completo: **emissão, consulta, cancelamento, substituição,
manifestação, download (com retry), DANFSe NT 008/2026 e Distribuição de DFe
(caixa postal do CNPJ)**. Cobertura ampla do DPS conforme leiaute V1.00.02:
**Intermediário** (`<interm>`), **Informações Complementares** (`<infoCompl>`),
**Deduções com documentos referenciados** (`<docDedRed>`),
**PIS/COFINS e retenções federais** (`<tribFed>`), `<tribMun>` completo
(imunidade, exportação, benefício municipal, exigibilidade suspensa).
**XSDs oficiais** versionados em `docs/schemas/`. PHPStan level 8 limpo,
**286 testes verdes**, validado ponta-a-ponta em homologação SEFIN. Pré-1.0 —
API pode sofrer ajustes minor antes do `1.0.0`; ver [CHANGELOG](CHANGELOG.md).

## Por que

- Suporte completo ao leiaute SefinNacional 1.6 / V1.00.02 do Sistema Nacional NFS-e
- Suporte à **NT 008/2026** (novo DANFSe válido a partir de 1º/jul/2026)
- Cobertura tributária ampla: imunidade, exportação, benefício municipal,
  exigibilidade suspensa, dispensa de ISSQN para MEI
- Tabelas oficiais como enums tipados: `ListaServicosNacional` (335 cases da
  LC 116/2003) e `ListaNbs` (917 cases)
- Sem dependência de framework — funciona em Laravel, Symfony, projetos vanilla
- **Intermediário** (`<interm>`) — marketplaces, plataformas de delivery,
  agências de turismo
- **Deduções com documentos** (`<docDedRed>`) — construção civil
  (materiais/subempreitada), repasses, reembolsos
- **PIS/COFINS + retenções federais** (`<tribFed>`) — CST configurável
  + retenções de IRRF/CP/CSLL
- **Distribuição de DFe paginada** (caixa postal do CNPJ no SEFIN), com
  helpers `chavesCanceladas`, `statusPorChave`, `agruparPorChave`, etc.
- **Detecção de cancelamento** via `$nfse->estaCancelada($chave)` (forma
  canônica — `consultar()` retorna cStat=100 mesmo após cancelar)
- Retry automático com backoff exponencial no download de DANFSe (502/503/504)
- Verificação idempotente de DPS (`HEAD /dps/{id}`) antes de emitir
- Tipagem forte (PHPStan level 8)
- **Comércio exterior** (`<comExt>`) — exportação de serviços + códigos
  BACEN de moeda + mecanismos de fomento PROEX/BNDES-Exim
- **Construção civil** (`<obra>`) — CNO/CIB ou endereço
- **Eventos** (`<atvEvento>`) — shows, conferências, etc.
- **Endereço internacional** (`<endExt>`) em Tomador e Intermediário —
  exportação, marketplace global, tomador estrangeiro
- Testes desde o dia 1 — **286 testes verdes** em CI (PHP 8.1–8.5)

## Requisitos

- PHP **^8.1**
- Extensões: `dom`, `openssl`, `libxml`, `zlib`, `mbstring`
- Certificado digital A1 (.pfx) do prestador
- OpenSSL com legacy provider habilitado (rsa-sha1) — ver
  [Configuração OpenSSL](#configuração-openssl)

## Instalação

```bash
composer require mendesalexandre/php-nfse-nacional
```

## Uso rápido

```php
use PhpNfseNacional\NFSe;
use PhpNfseNacional\Config;
use PhpNfseNacional\Certificate\Certificate;
use PhpNfseNacional\DTO\{Prestador, Tomador, Endereco, Servico, Valores, Identificacao};
use PhpNfseNacional\Enums\{Ambiente, RegimeEspecialTributacao};

// 1. Carregue o certificado A1
$cert = Certificate::fromPfxFile('/path/cert.pfx', 'senha-do-pfx');

// 2. Configure o prestador (singleton, uma vez na app)
$prestador = new Prestador(
    cnpj: '12345678000195',
    inscricaoMunicipal: '11408',
    razaoSocial: 'EMPRESA XYZ',
    endereco: new Endereco(
        logradouro: 'R DAS NOGUEIRAS',
        numero: '1108',
        bairro: 'SETOR COMERCIAL',
        cep: '01310100',
        codigoMunicipioIbge: '3550308',
        uf: 'MT',
    ),
    regimeEspecial: RegimeEspecialTributacao::NotarioOuRegistrador,
);

$config = new Config(
    prestador: $prestador,
    ambiente: Ambiente::Homologacao,
);

// 3. Crie o SDK
$nfse = NFSe::create($config, $cert);

// 4. Emita uma NFS-e
$resposta = $nfse->emitir(
    identificacao: new Identificacao(numeroDps: 1, serie: '1'),
    tomador: new Tomador(
        documento: '12345678901',
        nome: 'Cliente Exemplo',
        endereco: new Endereco(
            logradouro: 'Rua A',
            numero: '100',
            bairro: 'Centro',
            cep: '01310100',
            codigoMunicipioIbge: '3550308',
            uf: 'MT',
        ),
    ),
    servico: new Servico(
        discriminacao: 'Certidão de matrícula',
        codigoMunicipioPrestacao: '3550308',
    ),
    valores: new Valores(
        valorServicos: 100.00,
        deducoesReducoes: 20.00,
        aliquotaIssqnPercentual: 4.00,
    ),
);

echo "Chave: " . $resposta->chaveAcesso;
echo "Número: " . $resposta->numeroNfse;
```

### Cancelamento

```php
use PhpNfseNacional\DTO\MotivoCancelamento;

$resposta = $nfse->cancelar(
    chaveAcesso: '35012345200001234567890123456789012345678123456789',
    motivo: MotivoCancelamento::ErroEmissao,
    justificativa: 'Valor da NFS-e divergente do recibo',
);
```

### Cenários tributários comuns

**MEI (dispensa do ISSQN apurado):**

```php
use PhpNfseNacional\DTO\Valores;
use PhpNfseNacional\Enums\{SituacaoSimplesNacional, MotivoDispensaIssqn};

$prestador = new Prestador(
    /* ... */
    simplesNacional: SituacaoSimplesNacional::MEI,
    inscricaoMunicipal: null, // MEI normalmente sem IM cadastrada
);

$valores = new Valores(
    valorServicos: 800.00,
    deducoesReducoes: 0.00,
    aliquotaIssqnPercentual: 0.00,
    motivoDispensaIssqn: MotivoDispensaIssqn::OptanteSimplesNacional,
    // emite <indTotTrib>0</indTotTrib> em vez de <pTotTrib>
);
```

**Tomador estrangeiro (exportação de serviço):**

```php
use PhpNfseNacional\DTO\{Tomador, EnderecoExterior};

$tomador = new Tomador(
    documento: '12345678901',       // ou NIF estrangeiro (futuro)
    nome: 'JOHN DOE',
    endereco: new EnderecoExterior(
        logradouro: '5th Avenue',
        numero: '350',
        bairro: 'Manhattan',
        codigoPaisIso: 'US',            // 2 letras ISO
        codigoEnderecamentoPostal: '10118',
        cidade: 'New York',
        estadoProvinciaRegiao: 'NY',
    ),
);
// DpsBuilder detecta o tipo e emite <endExt> em vez de <endNac>
```

**Retenção do ISSQN (3 estados):**

```php
use PhpNfseNacional\Enums\TipoRetencaoIssqn;

$valores = new Valores(
    valorServicos: 1000.00,
    deducoesReducoes: 0.00,
    aliquotaIssqnPercentual: 4.00,
    tipoRetencaoIssqn: TipoRetencaoIssqn::RetidoPeloTomador, // tomador retém ISSQN
    // alternativas: NaoRetido (default), RetidoPeloIntermediario
);
```

**Imunidade (templos, partidos, livros, etc.):**

```php
use PhpNfseNacional\Enums\{TipoTributacaoIssqn, TipoImunidadeIssqn};

$valores = new Valores(
    valorServicos: 100.00,
    deducoesReducoes: 0.00,
    aliquotaIssqnPercentual: 0.00,
    tributacaoIssqn: TipoTributacaoIssqn::Imunidade,
    imunidade: TipoImunidadeIssqn::TemplosQualquerCulto,
);
```

**Benefício Municipal (redução de BC concedida pelo município):**

```php
use PhpNfseNacional\DTO\BeneficioMunicipal;

$valores = new Valores(
    valorServicos: 100.00,
    deducoesReducoes: 0.00,
    aliquotaIssqnPercentual: 4.00,
    beneficioMunicipal: new BeneficioMunicipal(
        nBM: '51079090100001',     // ID parametrizado pelo município
        percentualReducaoBc: 50.00, // ou valorReducaoBc — choice no schema
    ),
);
```

**Códigos de serviço via enum (LC 116):**

```php
use PhpNfseNacional\Enums\{ListaServicosNacional, ListaNbs};

$servico = new Servico(
    discriminacao: 'Análise e desenvolvimento de sistemas',
    codigoMunicipioPrestacao: '5107909',
    cTribNac: ListaServicosNacional::S010101,  // ou string '010101'
    cNBS:     ListaNbs::N101011100,             // ou string '101011100'
);

echo $servico->cTribNac;                                 // '010101'
echo ListaServicosNacional::S010101->descricao();        // 'Análise e desenvolvimento...'
```

**Intermediário (marketplace / plataforma):**

```php
use PhpNfseNacional\DTO\Intermediario;

$intermediario = new Intermediario(
    documento: '12345678000195',
    nome: 'MARKETPLACE EXEMPLO LTDA',
    endereco: $endereco,        // Endereco nacional OU EnderecoExterior — opcional
    inscricaoMunicipal: '987654',
    email: 'fiscal@marketplace.com',
);

$nfse->emitir(
    identificacao: $id,
    tomador: $tomador,
    servico: $servico,
    valores: $valores,
    intermediario: $intermediario,
);
```

**Informações complementares na NFS-e:**

```php
use PhpNfseNacional\DTO\InformacoesComplementares;

$servico = new Servico(
    discriminacao: 'Serviço prestado',
    codigoMunicipioPrestacao: '...',
    infoCompl: new InformacoesComplementares(
        xInfComp: 'Pedido #12345 - pagamento à vista - cliente VIP',
    ),
);
```

**Deduções com documentos (construção civil, repasses):**

```php
use PhpNfseNacional\DTO\DocumentoDeducao;
use PhpNfseNacional\Enums\TipoDeducaoReducao;

$deducao = new DocumentoDeducao(
    tipo: TipoDeducaoReducao::Materiais,
    dataEmissaoDocumento: new DateTimeImmutable('2026-05-01'),
    valorDedutivel: 5000.00,
    valorDeducao: 4500.00,
    chaveNfe: '35012345...',  // ou chaveNfse / numeroDocumento
);

$valores = new Valores(
    valorServicos: 10000.00,
    deducoesReducoes: 0.00,           // ZERO! choice no schema
    aliquotaIssqnPercentual: 4.00,
    documentosDeducao: [$deducao],
);
```

**PIS/COFINS retido + IRRF/CP/CSLL:**

```php
use PhpNfseNacional\DTO\TributacaoPisCofins;
use PhpNfseNacional\Enums\{CstPisCofins, TipoRetencaoPisCofins};

$valores = new Valores(
    valorServicos: 1000.00,
    deducoesReducoes: 0.00,
    aliquotaIssqnPercentual: 4.00,
    tributacaoPisCofins: new TributacaoPisCofins(
        cst: CstPisCofins::OperacaoTributavelAliquotaBasica,
        valorBaseCalculo: 1000.00,
        aliquotaPis: 0.65,
        aliquotaCofins: 3.00,
        valorPis: 6.50,
        valorCofins: 30.00,
        tipoRetencao: TipoRetencaoPisCofins::Retido,
    ),
    valorRetidoIrrf: 15.00,
    valorRetidoCp: 11.00,
    valorRetidoCsll: 10.00,
);
```

### Idempotência: verificar antes de emitir

Pra evitar dupla emissão (sequencial DPS reutilizado, retry agressivo do
cliente, etc.), use `verificarDps()` antes de chamar `emitir()`:

```php
$idDps = 'DPS510790920017902800013800001000000000128585';

if ($nfse->verificarDps($idDps)) {
    echo "DPS já existe no SEFIN — não emite de novo\n";
} else {
    $resposta = $nfse->emitir(...);
}
```

Usa `HEAD /dps/{id}` — leve, sem baixar o corpo.

### Distribuição de DFe (caixa postal)

Quando alguém emite uma NFS-e contra o seu CNPJ (como tomador), o SEFIN
guarda numa "caixa postal" associada ao CNPJ. O método `sincronizarDfe()`
itera por todos os DFes desde o último NSU conhecido:

```php
// Persiste o último NSU pra sincronização incremental
$ultimoNsuConhecido = (int) $cache->get('dfe_ultimo_nsu', 0);

$resp = $nfse->sincronizarDfe($ultimoNsuConhecido);

foreach ($resp->itens as $item) {
    echo "NSU {$item->nsu}: {$item->tipoDocumento} chave={$item->chaveAcesso}\n";
}

$cache->set('dfe_ultimo_nsu', $resp->ultimoNsu);
```

O loop interno paginado pega até 20 páginas (= até 1000 DFes) por chamada.
Para auditoria de eventos vinculados a uma NFS-e específica, use
`listarEventos($chave)`.

### Substituição

Cancela uma NFS-e e a vincula a uma substituidora previamente emitida.
A substituidora precisa ter sido emitida antes via `$nfse->emitir(...)`.

```php
use PhpNfseNacional\DTO\MotivoCancelamento;

use PhpNfseNacional\DTO\MotivoSubstituicao;

$resposta = $nfse->substituir(
    chaveOriginal:   '51079092200179028000138000000000005726057774456203',
    chaveSubstituta: '51079092200179028000138000000000005826057774456204',
    motivo:          MotivoSubstituicao::DesenquadramentoSimples,
);
```

### Eventos customizados

Pra outros tipos de evento que aparecerem no leiaute, implemente a interface
`EventoNfse` e use o `EventoBuilder` diretamente — sem alterar o SDK:

```php
use PhpNfseNacional\Dps\EventoNfse;
use PhpNfseNacional\Dps\EventoBuilder;

final class MeuEventoCustomizado implements EventoNfse
{
    public function chaveAcesso(): string { return '...'; }
    public function codigoTipoEvento(): string { return '101102'; } // ex: substituição
    public function nSequencial(): int { return 1; }
    public function descricao(): string { return 'Substituição de NFS-e'; }
    public function camposGrupo(): array { return ['campoX' => 'valor']; }
}

$xml = (new EventoBuilder($config))->build(new MeuEventoCustomizado());
$xmlAssinado = $signer->sign($xml, 'infPedReg');
$resposta = $client->postXml($endpoints->cancelarNfse($chave), $xmlAssinado);
```

## Configuração OpenSSL

OpenSSL 3.5+ (Fedora 43, RHEL 9) **desabilita SHA1 por padrão**. A DPS do
SEFIN usa rsa-sha1 — sem habilitar legacy, `openssl_sign` falha com
`error:03000098:digital envelope routines::invalid digest`.

**Opção 1 (recomendada em prod): env var**

Criar `/etc/ssl/openssl-sha1.cnf`:
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

Setar no Supervisor/php-fpm:
```
environment=OPENSSL_CONF=/etc/ssl/openssl-sha1.cnf
```

**Opção 2 (dev/local): runtime**

```php
use PhpNfseNacional\Certificate\Signer;
Signer::habilitarLegacyProviderRuntime();
```

## Arquitetura

```
src/
├── NFSe.php                     # Facade unificado (entry point)
├── Config.php                    # Config imutável
├── DTO/                          # Dados imutáveis readonly
├── Enums/                        # Ambiente, RegimeEspecial, etc.
├── Certificate/                  # Carga .pfx + rsa-sha1
├── Dps/                          # DpsBuilder + EventoCancelamentoBuilder
├── Sefin/                        # SefinClient (HTTP), Endpoints, Resposta
├── Services/                     # Emissão, Consulta, Cancelamento, Download
├── Danfse/                       # DANFSE NT 008 (parser + layout + generator)
├── Exceptions/
└── Support/                      # Documento, TextoSanitizador
```

## Roadmap

- [x] Estrutura + composer + DTOs + Config
- [x] Certificate + Signer rsa-sha1 (com fallback SAN OID 2.16.76.1.3.3)
- [x] DpsBuilder com cobertura completa `<tribMun>` (BM/exigSusp/imunidade)
- [x] SefinClient + EmissaoService
- [x] ConsultaService (status NFS-e, eventos)
- [x] CancelamentoService (e101101)
- [x] DownloadService (XML + PDF + retry exponencial no DANFSe)
- [x] DANFSe PDF local — NT 008/2026 (TCPDF + QR Code)
- [x] DANFSe customizável (logo do prestador, observações livres)
- [x] Substituição de NFS-e (evento e105102)
- [x] Manifestação (Confirmar / Rejeitar / Anular Rejeição)
- [x] **DFe paginado** (`GET /contribuintes/DFe/{NSU}`) — caixa postal do CNPJ
- [x] **Listagem de eventos por NFS-e** (`GET /contribuintes/NFSe/{chave}/Eventos`)
- [x] **Verificação idempotente** de DPS (`HEAD /dps/{id}`)
- [x] Enums das tabelas oficiais: `ListaServicosNacional` (335), `ListaNbs` (917),
      `TipoBeneficioMunicipal`, `TipoImunidadeIssqn`, `TipoExigibilidadeSuspensa`,
      `RegimeApuracaoSimplesNacional`, `TipoRetencaoIssqn`, `TipoTributacaoIssqn`
- [x] PHPStan level 8 limpo, 219 testes unitários
- [x] CI no GitHub Actions (PHP 8.1 – 8.5)
- [x] Validação ponta-a-ponta em homologação SEFIN
- [x] Examples completos do ciclo de vida
- [x] **`<interm>`** (Intermediário) — v0.12.0
- [x] **`<serv/infoCompl>`** (Informações Complementares) — v0.13.0
- [x] **`<vDedRed/documentos>/<docDedRed>`** (Deduções com docs referenciados) — v0.13.0
- [x] **`<trib/tribFed>/<piscofins>`** + retenções federais — v0.13.0
- [x] **`<BM>` + `<exigSusp>` + `<tpImunidade>`** dentro de `<tribMun>` — v0.10.0
- [x] XSDs oficiais versionados em `docs/schemas/` — v0.12.0
- [x] BC-break v0.14.0: `Valores::$issqnRetido` (bool) → `tipoRetencaoIssqn` (enum 3 estados) + `$dispensadoIssqn` (bool) → `$motivoDispensaIssqn` (enum 4 cases)
- [x] **Onda 5**: `<comExt>` (exportação), `<obra>` (construção civil),
      `<atvEvento>` (eventos) — v0.15.0. `<explRod>` e `<lsadppu>`
      fora-de-escopo (removidos do leiaute entre v1.00 e v1.01)
- [x] Endereço internacional (`endExt`) em Tomador e Intermediário — v0.16.0
- [ ] Endereço internacional em Prestador (caso raro: prestador estrangeiro)
- [ ] Grupo `<fornec>` dentro de `<docDedRed>` (fornecedor do documento de dedução)

## Licença

MIT
