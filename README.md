# mendesalexandre/php-nfse-nacional

[![CI](https://github.com/mendesalexandre/php-nfse-nacional/actions/workflows/ci.yml/badge.svg)](https://github.com/mendesalexandre/php-nfse-nacional/actions/workflows/ci.yml)
[![Packagist Version](https://img.shields.io/packagist/v/mendesalexandre/php-nfse-nacional.svg)](https://packagist.org/packages/mendesalexandre/php-nfse-nacional)
[![PHP Version](https://img.shields.io/packagist/php-v/mendesalexandre/php-nfse-nacional.svg)](https://packagist.org/packages/mendesalexandre/php-nfse-nacional)
[![License](https://img.shields.io/packagist/l/mendesalexandre/php-nfse-nacional.svg)](LICENSE)

SDK PHP framework-agnostic para integração com NFS-e Nacional (Padrão
Brasileiro SEFIN). Funciona em Laravel, Symfony, projeto vanilla — qualquer
coisa com PHP 8.1+ e suporte a PSR-3/PSR-18.

## Status

Ciclo de vida da NFS-e completo: **emissão, consulta, cancelamento, download
e DANFSe NT 008/2026**. PHPStan level 8 limpo, 62 testes verdes, validado
ponta-a-ponta em homologação SEFIN (NFS-e #57, chave
`51079092200179028000138000000000005726057774456203`). Pré-1.0 — API pode
sofrer ajustes minor antes do `1.0.0`; ver [CHANGELOG](CHANGELOG.md).

## Por que

- Suporte completo ao leiaute SefinNacional 1.6
- Suporte à **NT 008/2026** (novo DANFSE válido a partir de 1º/jul/2026)
- Sem dependência de framework — funciona em Laravel, Symfony, projetos vanilla
- Tipagem forte (PHPStan level 8)
- Testes desde o dia 1

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
$resposta = $nfse->emissao()->emitir(
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

$resposta = $nfse->cancelamento()->cancelar(
    chaveAcesso: '35012345200001234567890123456789012345678123456789',
    motivo: MotivoCancelamento::ErroEmissao,
    justificativa: 'Valor da NFS-e divergente do recibo',
);
```

### Substituição

Cancela uma NFS-e e a vincula a uma substituidora previamente emitida.
A substituidora precisa ter sido emitida antes via `$nfse->emissao()->emitir(...)`.

```php
use PhpNfseNacional\DTO\MotivoCancelamento;

$resposta = $nfse->substituicao()->substituir(
    chaveOriginal:   '51079092200179028000138000000000005726057774456203',
    chaveSubstituta: '51079092200179028000138000000000005826057774456204',
    motivo:          MotivoCancelamento::ErroEmissao,
    justificativa:   'Reemissão por divergência de valor',
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
- [x] Certificate + Signer rsa-sha1
- [x] DpsBuilder (XML completo)
- [x] SefinClient + EmissaoService
- [x] ConsultaService (status NFS-e, eventos)
- [x] CancelamentoService (e101101)
- [x] DownloadService (XML + PDF cancelada)
- [x] DANFSe PDF — NT 008/2026 (TCPDF + QR Code)
- [x] Testes unitários (PHPUnit) — 62 testes
- [x] PHPStan level 8 limpo
- [x] CI no GitHub Actions (PHP 8.1 – 8.4)
- [x] Validação ponta-a-ponta em homologação SEFIN
- [x] Substituição de NFS-e (evento 101102)
- [x] Examples completos do ciclo de vida (emitir, cancelar, substituir,
      consultar, download, danfse-local)
- [ ] Cobertura ampliada (DanfseGenerator, Certificate, Signer)
- [ ] Lote síncrono de DPS
- [ ] Múltiplos serviços por NFS-e

## Licença

MIT
