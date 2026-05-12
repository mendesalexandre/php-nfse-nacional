# sinop/nfse-nacional

SDK PHP para integração com NFS-e Nacional (Padrão Brasileiro SEFIN).
**Framework-agnostic** — funciona em Laravel, Symfony, projeto vanilla,
qualquer coisa com PSR-3/PSR-18.

## Status

🚧 **Em desenvolvimento.** Não usar em produção ainda.

## Por que

Alternativa ao `hadder/nfse-nacional` (TODOs no leiaute, monolito de 1573 linhas,
tipagem fraca) e ao `nfse-nacional/nfse-php` (em beta, sem suporte à NT 008
ainda). Pacote interno do cartório de Sinop pra ter controle total:

- Suporte completo ao leiaute SefinNacional 1.6 (sem TODOs)
- Suporte à **NT 008/2026** (novo DANFSE válido a partir de 1º/jul/2026)
- Sem dependência de Laravel
- Tipagem forte (PHPStan level 8)
- Testes desde o dia 1

## Requisitos

- PHP **^8.1**
- Extensões: `dom`, `openssl`, `libxml`, `zlib`, `mbstring`
- Certificado digital A1 (.pfx) do prestador
- OpenSSL com legacy provider habilitado (rsa-sha1) — ver
  [Configuração OpenSSL](#configuração-openssl)

## Instalação

Em `composer.json` do projeto consumidor:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:mendesalexandre/sinop-nfse-nacional.git"
        }
    ],
    "require": {
        "sinop/nfse-nacional": "^1.0"
    }
}
```

## Uso rápido (será expandido)

```php
use Sinop\NfseNacional\Config;
use Sinop\NfseNacional\Certificate\Certificate;
use Sinop\NfseNacional\DTO\Prestador;
use Sinop\NfseNacional\DTO\Endereco;
use Sinop\NfseNacional\Enums\Ambiente;
use Sinop\NfseNacional\Enums\RegimeEspecialTributacao;

$cert = Certificate::fromPfxFile('/path/cert.pfx', 'senha-do-pfx');

$prestador = new Prestador(
    cnpj: '00179028000138',
    inscricaoMunicipal: '11408',
    razaoSocial: 'CARTÓRIO DE SINOP',
    endereco: new Endereco(
        logradouro: 'R DAS NOGUEIRAS',
        numero: '1108',
        bairro: 'SETOR COMERCIAL',
        cep: '78550200',
        codigoMunicipioIbge: '5107909',
        uf: 'MT',
    ),
    regimeEspecial: RegimeEspecialTributacao::NotarioOuRegistrador,
);

$config = new Config(
    prestador: $prestador,
    ambiente: Ambiente::Homologacao,
);

// Próximos passos: $emissao->emitir($tomador, $servico, $valores);
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
use Sinop\NfseNacional\Certificate\Signer;
Signer::habilitarLegacyProviderRuntime();
```

## Arquitetura

```
src/
├── Config.php                     # Config singleton
├── DTO/                           # Dados imutáveis (readonly)
├── Enums/                         # Ambiente, RegimeEspecial
├── Certificate/                   # Carga .pfx + assinatura rsa-sha1
├── Dps/                           # DpsBuilder, Validator, Renderer (TODO)
├── Sefin/                         # SefinClient (HTTP via PSR-18) (TODO)
├── Services/                      # Emissão, Consulta, Cancelamento, Download (TODO)
├── Danfse/                        # Geração PDF NT 008 (TODO)
├── Exceptions/
└── Support/
```

## Roadmap

- [x] Estrutura + composer + DTOs + Config
- [x] Certificate + Signer rsa-sha1
- [ ] DpsBuilder (XML completo, sem TODOs do leiaute)
- [ ] SefinClient + EmissaoService
- [ ] ConsultaService (status NFS-e, eventos)
- [ ] CancelamentoService (e101101)
- [ ] DownloadDanfseService (XML + PDF cancelada)
- [ ] DANFSE PDF — NT 008/2026
- [ ] Testes unitários (PHPUnit)
- [ ] CI no GitHub Actions

## Licença

Proprietário. Uso interno do Cartório de Sinop.
