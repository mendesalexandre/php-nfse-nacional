# Guia de revisão técnica

Documento para quem vai revisar o SDK com olhar sênior. Objetivo: dizer **onde
olhar primeiro**, **por que as coisas são como são** e **o que ainda não está
resolvido** — sem maquiar. Estado de referência: `v0.47.0` + commits
posteriores em `main` (tabela NBS→cIndOp, `tpOper`).

## 1. O que é

SDK PHP 8.1+ (framework-agnostic) para o **Sistema Nacional de NFS-e**
(SefinNacional 1.6, Receita Federal): emissão de DPS, consulta, cancelamento,
substituição, manifestação, download, distribuição DFe, parâmetros municipais
e **DANFSe local** (NT 008/2026). ~14 mil linhas em `src/`, ~7 mil em `tests/`.

## 2. Como verificar (5 minutos)

```bash
composer install
vendor/bin/phpunit          # 429 testes
composer phpstan            # level 8, deve dar [OK] No errors
composer audit              # dependências (ver §8)
```

Smoke real em **homologação** (precisa de certificado A1 e `OPENSSL_CONF`
habilitando SHA-1, ver §4): `examples/emitir-homologacao.php`,
`examples/smoke-ciclo-completo-homologacao.php`,
`examples/campanha-indop-homologacao.php`.

## 3. Mapa do código

```
src/NFSe.php            Facade único (entry point). API achatada.
src/Config.php          Imutável: prestador + ambiente + leiaute + flags
src/DTO/                Value objects readonly; validam no construtor
src/Enums/              Domínios do leiaute (CStat, TipoTributacao, LC116, NBS…)
src/Dps/                DpsBuilder (monta o XML do DPS) + EventoBuilder
src/Certificate/        Carga do .pfx + Signer (XML-DSig)
src/Sefin/              SefinClient (HTTP, gzip+b64, retry), endpoints, respostas
src/Services/           Um serviço por caso de uso (Emissao, Cancelamento, …)
src/Danfse/             Parser do XML → DanfseDados → Layout V1/V2 (TCPDF)
src/Support/            Documento (CPF/CNPJ), IbgeMunicipios, TextoSanitizador,
                        IndicadorOperacao (tabela NBS→cIndOp)
resources/data/         Tabelas embarcadas (IBGE, NBS→cIndOp) + gerador em tools/
docs/                   Schemas XSD por versão, NTs, Anexos, guias
```

Fluxo de emissão: `DTO → DpsBuilder (XML) → Signer (assina) → SefinClient
(POST gzip+base64) → SefinResposta` (ou `SefinException` com `cStat`/`xMotivo`).

## 4. Decisões não óbvias (e o porquê)

| Decisão | Motivo |
|---|---|
| **Assinatura `rsa-sha1`** | O leiaute da DPS exige. OpenSSL 3.5+ desabilita SHA-1 por padrão → `OPENSSL_CONF` com legacy provider em produção, ou `Signer::habilitarLegacyProviderRuntime()` em dev. **É a maior fonte de falha de ambiente.** |
| **`dhEmi` sempre em `America/Sao_Paulo` −60 s** | O SEFIN rejeita (E0008) `dhEmi` no futuro; −60 s cobre clock drift. `dCompet` sai do **mesmo** timestamp (senão, entre 00:00:00 e 00:00:59, vira cStat 15). |
| **Escopo da lib = sintaxe, não regra fiscal** | Quem decide validade fiscal é o SEFIN. O `DpsBuilder` não valida regra tributária (já houve um `validarCruzado` removido por isso). Exceção consciente: formato e consistência estrutural (ex.: CST × prefixo do cClassTrib). |
| **DANFSe local** | O endpoint de DANFSe do ADN é instável/fora do ar (inclusive em produção). `danfseLocal()` é o caminho viável. |
| **Layout do DPS por ambiente** (`LeiauteDps`) | A homologação valida o schema de 20/10/2026; a produção ainda o `1.01`. Default por ambiente. |
| **DANFSe: dado do XML, não recálculo** | Ex.: "VALOR LÍQUIDO + IBS/CBS" lê `vTotNF`; `vTotalRet` vem do SEFIN. Recalcular já gerou valores errados (ver CHANGELOG 0.47.0). |
| **`cIndOp` × NBS não é imposto pelo builder** | A tabela existe (`IndicadorOperacao`) mas é consulta. Em homologação **o SEFIN não valida essa combinação** (campanha de 20/11/2026) — ver §6. |

## 5. Onde olhar primeiro (áreas de maior risco)

1. **`Certificate/Signer.php`** — canonicalização e assinatura; qualquer erro aqui
   rejeita tudo. Conferir tratamento de erro e de múltiplas `Reference`.
2. **`Dps/DpsBuilder.php`** — ordem dos elementos é parte do contrato XSD (um
   elemento fora de ordem → cStat 1235). Os testes de mock **não** pegam
   divergência de leiaute; só smoke real.
3. **`Sefin/SefinClient.php`** — retry (502/503/504), timeout de conexão (30 s),
   checagem de status HTTP antes de tratar o corpo como XML.
4. **`Danfse/Layouts/DanfseLayoutV2.php`** (~1.300 linhas) — layout posicional
   (cm) da NT 008. É o arquivo mais denso e o com mais regras de exibição; o
   candidato natural a refatoração (§7).
5. **`Danfse/DanfseXmlParser.php`** — caminhos XPath; já teve um caminho errado
   por muito tempo (`xTribMun`), invisível porque o campo é opcional.
6. **`Support/IndicadorOperacao.php` + `tools/gerar-indop-nbs.py`** — dados
   gerados a partir de repositório da RFB; ver proveniência em `baseDados()`.

## 6. Como a qualidade foi validada (e o que **não** foi)

Feito:
- **PHPUnit 429 testes**, **PHPStan level 8 limpo**, CI em PHP 8.1–8.5.
- **Smoke em homologação SEFIN** em vários ciclos, incluindo uma campanha de 20
  NFS-e (`docs/indicador-operacao.md`): 18 autorizadas, invariantes de valores
  OK; achou 3 lacunas reais (`tpOper`, aplicabilidade do cClassTrib à NFS-e,
  caminho do `xTribMun`).
- **DANFSe**: comparação por diff de PDF contra o gerador oficial da RFB e
  contra DANFSe reais do portal (`docs/gerador-oficial-rfb.md`).

**Não** feito / limites honestos:
- **Nenhum smoke automatizado em CI** (exige certificado e rede). A garantia de
  aderência ao leiaute é manual e datada.
- **Produção**: validação empírica foi em **homologação**; o comportamento do
  SEFIN de produção pode diferir (já houve diferenças, ex.: schema).
- **PHP 8.1 não foi executado localmente** (ambiente de dev é 8.5); depende do
  CI da matriz.
- Cobertura de testes **não medida** (`coverage: none` no CI).
- O DANFSe local é **replicação** da NT 008; difere do gerador oficial em
  pontos documentados (§ "Divergências conscientes" em `gerador-oficial-rfb.md`).

## 7. Dívida técnica conhecida

- `DanfseLayoutV2` e `V1` são grandes; sem separação clara entre *regra de
  exibição* e *desenho*. Ideia: apresentador puro (como faz o gerador RFB em Go)
  + renderer.
- **TCPDF está marcado "deprecated"** pelo Composer (sucessor: `tc-lib-pdf`).
  Risco estratégico de médio prazo (migração do renderer).
- **"NFS-e MEI"** na situação da DANFSe: regra do portal confirmada com 2
  amostras, **não implementada** (ver `CLAUDE.md`/débito técnico).
- 10 métodos `@deprecated` (aliases `eh*`→`is*`, `estaCancelada`…) a remover
  numa versão futura.
- Sem suporte ao grupo `imovel` (cIndOp 02xxxx) nem a retenções avançadas na
  campanha de homologação.
- PHPStan 1.x (há 2.x) e PHPUnit 10 (há versões mais novas) — atualização
  pendente, sem urgência.

## 8. Segurança

- **Certificado**: senha do `.pfx` não é logada; mensagem de erro traz só o erro
  do OpenSSL. Responsabilidade de guardar o `.pfx` é do chamador.
- **`Config::$debugLogPayload`** (default `false`) loga o **XML do DPS** — contém
  CPF/CNPJ. Não ligar em produção.
- **XXE/billion laughs**: o parser da DANFSe rejeita `DOCTYPE`/`ENTITY`.
- **TLS**: não há `verify => false` em lugar nenhum.
- **Dependências**: o `composer audit` apontava 14 advisories (Guzzle/psr7).
  O mínimo do Guzzle foi elevado para `^7.15.2`, o que os resolve; resta 1 em
  `symfony/filesystem`, **somente dev** (via php-cs-fixer), sem efeito em quem
  consome a lib.
- **Dados reais**: o repositório é **público**; há teste (`SanitizacaoTest`) que
  falha se CNPJ/IM/município do mantenedor reaparecerem no código. Mantenha.

## 9. Perguntas em aberto para o revisor

1. A fronteira "sintaxe × regra fiscal" está no lugar certo? Hoje validamos CST ×
   prefixo do cClassTrib mas **não** cIndOp × NBS, embora o SEFIN também não
   valide — quem emite fica sem rede de proteção.
2. Vale impor `IndicadorOperacao::valida()` por padrão (opt-out) no builder?
3. Estratégia para o renderer de PDF (TCPDF → `tc-lib-pdf` ou outro).
4. Política de versão: pré-1.0 com *breaking* em minor — quando congelar a API?
5. Como automatizar um smoke contra homologação sem expor certificado no CI?

## 10. Para o produto de emissão (fora da lib)

A lib não resolve, e o produto precisará de: **numeração/idempotência de DPS**
(`verificarDps` existe; a política de `nDPS` é do chamador), **armazenamento
durável do XML autorizado**, **gestão e rotação de certificado A1**, **fila e
retry com backoff** em instabilidade do ADN, **observabilidade** (correlacionar
`cStat`/`chave`), e tratamento de **eventual consistency** do ADN após
cancelamento (confiar no `cStat` do `cancelar()`, não em reconsulta imediata).
