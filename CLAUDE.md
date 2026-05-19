# `mendesalexandre/php-nfse-nacional` — SDK PHP framework-agnostic

> **Pra próximas sessões:** este arquivo é o ponto de entrada de contexto. Leia ele primeiro.

---

## O que é

SDK PHP **framework-agnostic** pro [Sistema Nacional de NFS-e (SefinNacional 1.6)](https://www.gov.br/nfse/pt-br/) — Padrão Brasileiro de Nota Fiscal de Serviços eletrônica administrado pela SE/CGNFS-e (Receita Federal).

Cobre **ciclo de vida completo:** emissão, consulta, cancelamento, substituição, manifestação, download (com retry exponencial), **distribuição DFe paginada** (caixa postal CNPJ) e **DANFSe local NT 008/2026**.

- **Repo:** github.com/mendesalexandre/php-nfse-nacional
- **Packagist:** `composer require mendesalexandre/php-nfse-nacional`
- **Versão atual:** v0.16.0. Cobertura DPS quase completa — Ondas 1, 2, 5, 7 fechadas + endExt parcial + BC-break v0.14.0. Ver `CHANGELOG.md`
- **Diretório local:** `/home/alexandre/code/sinop-nfse-nacional`
- **Licença:** MIT

## Stack

- PHP 8.1+ (CI matrix 8.1, 8.2, 8.3, 8.4, 8.5)
- Dependências core: `psr/http-client`, `psr/http-message`, `psr/log`, `guzzlehttp/guzzle`, `tecnickcom/tcpdf`
- PHPUnit 10 + PHPStan level 8 (CI verde em todas)
- Sem Laravel/Symfony — quem usa em framework integra via DI manual ou facade

## Estrutura

```
src/
├── NFSe.php                     # Facade unificado (entry point)
├── Config.php                    # Imutável: prestador + ambiente + flags
├── DTO/                          # Imutáveis readonly: Endereco, Tomador, Servico, Valores, Identificacao, Prestador, BeneficioMunicipal, ExigibilidadeSuspensa, MotivoCancelamento, MotivoSubstituicao, MotivoRejeicao, TipoEmissaoDps
├── Enums/                        # Ambiente, RegimeEspecialTributacao, SituacaoSimplesNacional, AutorManifestacao, CStat (54 cases), TipoTributacaoIssqn, TipoImunidadeIssqn, TipoBeneficioMunicipal, TipoExigibilidadeSuspensa, TipoRetencaoIssqn, RegimeApuracaoSimplesNacional, ListaServicosNacional (335 cases LC 116), ListaNbs (917 cases)
├── Certificate/                  # Carga .pfx + Signer rsa-sha1 + fallback SAN OID 2.16.76.1.3.3
├── Dps/                          # DpsBuilder + EventoBuilder + EventoCancelamento/Substituicao/Confirmacao/Rejeicao/AnulacaoRejeicao
├── Sefin/                        # SefinClient (HTTP + retry + sync DFe), SefinEndpoints, SefinResposta, ItemDfe, RespostaDfe
├── Services/                     # EmissaoService, CancelamentoService, SubstituicaoService, ManifestacaoService, ConsultaService, DownloadService, DanfseService, DfeService
├── Danfse/                       # DanfseGenerator + DanfseXmlParser + DanfseDados + DanfseCustomizacao + DanfseLayout
├── Exceptions/                   # NfseException, ValidationException, CertificateException, SefinException
└── Support/                      # Documento, IbgeMunicipios (5571 mun), TextoSanitizador (com mapping Latin-1 tipográfico)
```

## API Principal

API **achatada** desde v0.5.0 (sem `->subdominio()->acao()`):

```php
$nfse = NFSe::create($config, $cert);

// Emissão
$resp = $nfse->emitir($identificacao, $tomador, $servico, $valores);

// Consulta
$resp = $nfse->consultar($chave);
$resp = $nfse->consultarDps($chave);
$resp = $nfse->consultarEventos($chave, $tipoEvento, $nSeq);

// Eventos
$resp = $nfse->cancelar($chave, MotivoCancelamento::ErroEmissao, $just);
$resp = $nfse->substituir($orig, $subst, MotivoSubstituicao::DesenquadramentoSimples);
$resp = $nfse->confirmar($chave, AutorManifestacao::Tomador);
$resp = $nfse->rejeitar($chave, AutorManifestacao::Tomador, MotivoRejeicao::Duplicidade);
$resp = $nfse->anularRejeicao($chave, $cpf, $idRejeicao, $xMotivo);

// Download (com retry exponencial em 502/503/504)
$xml = $nfse->baixarXml($chave);
$pdf = $nfse->baixarPdf($chave, tentativas: 3);

// Idempotência + auditoria
$existe = $nfse->verificarDps($idDps);            // HEAD /dps/{id}
$eventos = $nfse->listarEventos($chave);          // /contribuintes/NFSe/{chave}/Eventos

// Distribuição DFe (caixa postal CNPJ — quem emitiu contra o emissor)
$resp = $nfse->sincronizarDfe($ultimoNsuConhecido);

// DANFSe local (NT 008)
$pdf = $nfse->danfseLocal($xml, $custom = null);
```

Service classes ficam em `PhpNfseNacional\Services\` — pra DI granular (Symfony/Laravel containers, mock por subdomínio), instancie diretamente.

## Convenções

- **Nomenclatura PT-BR** nos DTOs/enums (`MotivoCancelamento::ErroEmissao`, `AutorManifestacao::Tomador`) — alinha com o leiaute oficial
- Tudo **readonly/imutável** — DTOs validam no construtor e lançam `ValidationException` agregando todos os erros
- **OpenSSL legacy provider obrigatório** (DPS exige `rsa-sha1`, OpenSSL 3.5+ desabilita por default):
  - Prod: `OPENSSL_CONF=/etc/ssl/openssl-sha1.cnf` (env var antes do PHP carregar)
  - Dev: `Signer::habilitarLegacyProviderRuntime()` no bootstrap
- **dhEmi sempre em `America/Sao_Paulo` -60s** (clock drift); SefinNacional rejeita E0008 se for futuro
- **dCompet acompanha tz do dhEmi** — passar `Identificacao::dataEmissao` retroativo + `dataCompetencia` igual ou anterior
- **`pTotTribMun` é declaratório (Lei 12.741)**, não tributário — SEFIN sobrescreve com `pAliqAplic` do cadastro do município

## Achados empíricos importantes (todos validados em homologação SEFIN)

| Achado | Origem |
|---|---|
| `e105102` é o código correto pra Substituição (não `101102`) | Anexo I/Manual pg 56 |
| xDesc dos eventos é enumeração restrita — Manifestação exige prefixo `"Manifestação de NFS-e - "`, Substituição exige `"Cancelamento de NFS-e por Substituição"` | TS_xDesc |
| `MotivoSubstituicao` (TSCodJustSubst: 01-05/99) ≠ `MotivoCancelamento` (TSCodJustCanc: 1/2/9) | leiaute |
| `tpEmit` = QUEM emite (Prestador/Tomador/Intermediário), não modo offline | Anexo IV |
| `<toma>` ordem schema: CPF/CNPJ → IM → xNome → end → fone → email | TSDestinaDps |
| `pTotTrib*` é tipo `TSDec3V2` (2 casas fixas) — NT 03.14 da NF-e NÃO vale | XSD |
| dhEmi retroativo aceito sem limite — limite é convênio do município (cStat=38) e parametrização tributária histórica (cStat=440) | SEFIN homologação |
| Cartório Sinop conveniou ao SefinNacional em 11/mar/2026 | bisect manual |
| IBSCBS é opt-in pelo emissor — sem `<IBSCBS>` no DPS, resposta também não tem | smoke testing |
| Anulação de Rejeição (e205208) e Substituição (e105102 via API Eventos) retornam cStat=999/1861 em homologação Sinop — parametrização do município | smoke testing |
| `pTotTribMun` é declaratório, SEFIN aplica `pAliqAplic` da tabela municipal independente do que enviamos | NFS-es #61–#63 |
| `tribISSQN` mapping oficial: 1=Tributável, **2=Imunidade**, 3=Exportação, 4=NãoIncidência (DanfseLayout legado tinha 2/3/4 invertidos — fix v0.9.1) | Anexo IV V1.00.02 linha 256 |
| `dispensadoIssqn=true` (`<indTotTrib>0</indTotTrib>`) é EXCLUSIVO de Simples Nacional. Não Optante imune deve usar `<pTotTrib>` com `pTotTribMun=0` | cStat=713 cartório homol |
| Exportação exige grupo `<comExt>` no `<serv>` (mdPrestacao, tpMoeda, etc.) — `tribISSQN=3 + cPaisResult` sozinho dá cStat=330 | smoke 138 |
| `nProcesso` do `<exigSusp>` tem pattern XSD `TSNumProcExigSuspensa` restritivo — formato exato pendente de descoberta | smoke 18mai |
| ADN `/danfse/{chave}` instável em homologação (HTTP 502 persistente em ambas tentativas) — usar `danfseLocal()` ou confiar no retry do SDK (v0.11.0) | smoke |
| `<BM>/<nBM>` tem 14 dígitos: 7 IBGE + 2 tipo (01-04) + 5 sequencial — cadastrado pelo município no Sistema Nacional | leiaute linha 259 |
| Cabeçalho DANFSE coluna direita tem apenas 1.76cm úteis (entre x=15.62 e QR Code em x=17.48) — textos curtos obrigatórios | fix v0.10.1 |

## Bug history (cuidado em refactors)

- DANFSe local: 4 inconsistências de grid (cabeçalho, BLOCO 2 altura/largura, Telefone fora da col 4, Regime SN fora das cols 3-4) — corrigido v0.10.1 alinhando à NT 008 página 17
- `DanfseLayout::tipoTributacaoIssqn()`: labels 2/3/4 invertidos (era Exportação/NãoIncid/Imunidade — oficial é Imunidade/Exportação/NãoIncid) — corrigido v0.9.1
- `DpsBuilder::validarCruzado` validava regra fiscal (BC>0 + ISSQN=0) — removido v0.7.0 (escopo é sintaxe XML, não regra fiscal — quem decide é o SEFIN)
- `EventoSubstituicao` usava `MotivoCancelamento` errado (corrigido v0.5.1)
- `xDesc` da substituição estava sem prefixo (corrigido v0.5.1)
- `xDesc` das manifestações idem (corrigido v0.4.1)
- Ordem `<toma>` punha email/fone antes de end (corrigido v0.5.1)
- Código substituição era 101102, oficial é 105102 (corrigido v0.4.0)
- TipoEmissaoDps tinha cases errados copiados do mundo NF-e (corrigido v0.3.7)
- dCompet usava tz default → cStat=15 em servidor UTC noturno (corrigido v0.3.4)

Lições:
1. **Nunca presumir** que SefinNacional segue convenção NF-e — são leiautes diferentes
2. **Sempre validar empiricamente** em homologação antes de confiar no enum/código
3. **Testes mock não pegam** divergência leiaute — só smoke real
4. Quando `cStat=1235` ("Falha no esquema XML"), olhar o `complemento` da resposta SEFIN — quase sempre tem o nome do elemento/atributo problemático

## Como retomar trabalho

```bash
cd /home/alexandre/code/sinop-nfse-nacional

# Suite + lint
vendor/bin/phpunit
composer phpstan

# Smoke real em homologação SEFIN (precisa de cert + OPENSSL_CONF)
OPENSSL_CONF=/home/alexandre/code/SINOP/backend/docs/openssl-sha1.cnf \
  PFX_PATH=/home/alexandre/code/SINOP/certificado_digital_a1_ecnpj_00179028000138.pfx \
  PFX_SENHA=123456 \
  PRESTADOR_CNPJ=00179028000138 PRESTADOR_IM=11408 \
  PRESTADOR_RAZAO='SERVICO REGISTRAL IMOVEIS SINOP' \
  PRESTADOR_CMUN=5107909 PRESTADOR_UF=MT PRESTADOR_CEP=78550200 \
  PRESTADOR_LOGRADOURO='R DAS NOGUEIRAS' PRESTADOR_NUMERO=1108 \
  PRESTADOR_BAIRRO='SETOR COMERCIAL' \
  php examples/emitir-homologacao.php

# Bumpar versão: edita CHANGELOG, git tag -a vX.Y.Z, push tag
# Packagist auto-detecta em ~1 min
```

## Workflow de release

1. Editar `src/...` + adicionar testes
2. Atualizar `CHANGELOG.md` na seção `[Unreleased]`
3. `vendor/bin/phpunit && composer phpstan` (verde)
4. Decidir versão: patch (bug fix), minor (feature), major (breaking — mas pré-1.0 minor pode ter breaking)
5. Mover `[Unreleased]` → `[X.Y.Z]` no CHANGELOG
6. `git commit -m "feat: ..." && git tag -a vX.Y.Z -m "..."`
7. `git push origin main && git push origin vX.Y.Z`
8. Aguardar Packagist (~1 min) ou trigger manual via webhook
9. Bumpar consumidores (SINOP shadow + nfse-monorepo)

## Onde NÃO mexer

- `vendor/` — gerenciado pelo Composer
- Tag/release antiga — não reescrever histórico publicado
- `examples/` em produção (são scripts de smoke/demo)

## Documentação

- `README.md` — quickstart
- `MANUAL.md` — referência completa estilo Swagger (todas as APIs, parâmetros, exceções, exemplos)
- `CHANGELOG.md` — histórico versão a versão (cada release explica o "porquê")
- `docs/nt-008-se-cgnfse-danfse-20260505.pdf` — NT oficial DANFSe NT 008/2026
- `/home/alexandre/Dropbox/nfse/AnexoIV-LeiautesRN_ADN-SNNFSe_V1.00.02-Produção.csv` — Tabela oficial dos campos do DPS/NFS-e com posições, tipos, ocorrências. **Fonte canônica para resolver ambiguidades de schema.** Ver memória `leiaute-oficial-csv`

## Consumidores conhecidos

- `/home/alexandre/code/SINOP/backend` — shadow mode (branch `feat/nfse-shadow-sdk`, PR #14) emite via Hadder em paralelo + compara DPS via SDK
- `/home/alexandre/code/nfse-monorepo` — API multi-tenant Laravel 13 + Quasar usando o SDK pra todo ciclo de vida

## Memórias relacionadas (auto-memory)

Em `~/.claude/projects/-home-alexandre-code-SINOP/memory/`:
- `sdk-php-nfse-nacional.md` — histórico de releases, achados, plano de migração
- `sdk-roadmap-lote.md` — análise sobre lote (concluído: SefinNacional não tem)
- `nfse-bugs-hadder-delphi.md` — bugs do legado Hadder
