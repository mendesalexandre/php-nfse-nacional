# Documentação oficial do leiaute

Material **público** da SE/CGNFS-e (Receita Federal) versionado aqui como
fonte canônica para resolver ambiguidades de schema. Nada neste diretório
contém dado real de contribuinte — não adicione XML/PDF de NFS-e emitida.

Portal de origem: <https://www.gov.br/nfse/pt-br/biblioteca/documentacao-tecnica>
(seções "Documentação atual", "Produção restrita" e "RTC").

## Schemas (`schemas/`)

| Pasta | Origem | Ambiente |
|---|---|---|
| `1.00/` | Leiaute original (jul/2022 a 28/09/2025) | legado |
| `1.01/` | `nfse-esquemas_xsd-v1-01-20260209.zip` | **produção** |
| `1.01-prodrest-20261020/` | `esquemas_prod_restrita_20261020.zip` (publicado 20/10/2026 18h10) | **homologação** (produção restrita) |

`schemas/diff-1.01-vs-prodrest-20261020.diff` traz a diferença entre os dois
`1.01` (só `tiposComplexos` e `tiposSimples` mudam; fim de linha ignorado).

### O que mudou no DPS em `1.01-prodrest-20261020`

Validado empiricamente em 06/11/2026: a homologação rejeita o DPS do leiaute
de produção com `cStat=1235` e a mensagem bate com o XSD novo (a resposta
ainda anuncia `SefinNacional_1.6.0`).

- **`infDPS`**: depois de `tpEmit` vêm `cMotivoEmisTI?` → **`finNFSe`
  (obrigatório, antes ficava dentro de `<IBSCBS>`)** → `chNFSeRej?` → `cLocEmi`.
- **`cServ`**: ordem `cTribNac`, `cTribMun?`, **`cNBS?`**, `cIntContrib?`,
  `xDescServ` (o `cNBS` passa a vir antes do `xDescServ`).
- **`valores`** (NT 009 §2.3): `vDedRed` (`pDR`/`vDR`/`docDedRed`) vira **`vAjusteBC`**
  (`pAjusteBCISSQN`/`vAjusteBCISSQN`/`docAjusteBC`).
- **IBS/CBS**: vários campos ficam opcionais (`cLocalidadeIncid`,
  `xLocalidadeIncid`, `valores`, `gIBS`, `gCBS`, `xNome` do dest);
  `vCalcDR` → `vCalcAjusteBCISSQN`; novos grupos de imóveis
  (`TCEnderImovel`, `gAjusteBCLocImoveis`, `vCalcAjusteBCLocImoveis`).

Status no SDK: o `DpsBuilder` ainda gera o leiaute de **produção**; a
migração para o leiaute novo está pendente. Para validar o XML gerado
localmente (reproduz o erro do SEFIN):

```php
$d = new DOMDocument(); $d->load('dps.xml');
libxml_use_internal_errors(true);
$d->schemaValidate('docs/schemas/1.01-prodrest-20261020/DPS_v1.01.xsd');
```

## Anexos (`anexos/`)

| Arquivo | Conteúdo |
|---|---|
| `anexo-i-dps-nfse-prodrest-v1.01-20261020.xlsx` | Leiaute DPS/NFS-e (produção restrita, 20/10/2026) |
| `anexo-ii-pedregevt-prodrest-v1.01-20261020.xlsx` | Leiaute pedido de registro de evento / evento |
| `anexo-vi-leiaute-rtc-ibscbs-v1.04.01-nt009.xlsx` | Leiaute IBS/CBS (RTC), NT 009 |
| `anexo-vii-indop-ibscbs-v1.03.00-nt009.xlsx` | Indicadores de operação IBS/CBS, NT 009 |
| `anexo-iv-leiautes-rn-v1.00.02-producao.csv` | Leiaute antigo V1.00.02 (linhas citadas no CLAUDE.md/CHANGELOG) |

## Notas técnicas (`nts/`)

| Arquivo | Assunto |
|---|---|
| `nt-007-se-cgnfse-rtc-v1.0.pdf` | Adequações RTC: `piscofins`, domínio `tpRetPisCofins`, retenções agregadas no `vRetCSLL` |
| `nt-008-se-cgnfse-danfse-20260714-v1-02.pdf` | DANFSe (v1.02, 14/07/2026; substitui a v1.0 de 05/05/2026) |
| `nt-009-se-cgnfse-v1.01.pdf` | NT 009 v1.01 (11/09/2026), adequações NFS-e/RTC: CNPJ alfanumérico, notas de ajuste IBS/CBS, **merge `vDedRed`+`gReeRepRes` → `vAjusteBC`**, evoluções Simples Nacional, reinserção do `indFinal`, bens imóveis, `gLocBensMoveis` → `bensMoveis`. É a origem das mudanças do schema de 20/10/2026 |

## Outros guias

- [`cnpj-alfanumerico.md`](cnpj-alfanumerico.md)
- [`consultar-nfse-e-cancelamento.md`](consultar-nfse-e-cancelamento.md)
