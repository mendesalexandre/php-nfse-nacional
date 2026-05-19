# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e
versionamento conforme [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Corrigido — `dCompet` saltava pro dia seguinte na virada do dia em SP

- `DpsBuilder` calculava `dhEmi` como `now() - 60s` em `America/Sao_Paulo` e
  `dCompet` via `new DateTimeImmutable()` independente. Na janela
  `00:00:00..00:00:59 SP`, a margem jogava o `dhEmi` pro dia anterior
  enquanto o `dCompet` recém-criado ficava no dia novo — SEFIN rejeitava
  com **E0015** (`dCompet > dhEmi.date`).
- Fix: `dCompet` agora é derivado do mesmo timestamp resolvido do `dhEmi`
  quando `Identificacao::$dataCompetencia` é null. Quando informado
  explicitamente, o override mantém precedência (cenário comum: cobrança
  de competência do mês anterior emitida no início do mês corrente).
- **BC-break (pré-1.0):** `Identificacao::dataCompetenciaResolvida()`
  removido. Era usado só pelo `DpsBuilder` internamente; consumidores que
  chamavam direto precisam ler `Identificacao::$dataCompetencia`.
- Dois testes de regressão novos no `DpsBuilderTest`:
  `test_dCompet_derivado_de_dhEmi_quando_dataCompetencia_nula` simula a
  virada (`dataEmissao=23:59:30 SP`, sem dataCompetencia) e
  `test_dCompet_explicito_independe_do_dhEmi` valida que override toma
  precedência.

### Segurança — Sanitização de dados reais em fixtures e exemplos

- Substituídos por valores fictícios (`12345678000195`, IM `12345`, CPF
  `12345678909`, cMun `3550308`, "Cidade Exemplo", "RUA EXEMPLO", etc.) os
  dados do emissor de smoke real que sobraram em `tests/Unit/**`,
  `tests/fixtures/**` e `examples/**`. Sanitização anterior só cobriu o
  `CHANGELOG.md`.
- Novo `tests/Unit/SanitizacaoTest.php` — guarda que falha o CI se qualquer
  dos padrões sensíveis reaparecer em `src/`, `tests/` ou `examples/`. Lista
  é extensível.

### Corrigido — DANFSe `Red. Alíquota IBS / Red. Alíquota CBS` hardcoded como `- / -`

- **Parser (`DanfseXmlParser`)** — passa a extrair `pRedAliqUF`, `pRedAliqMun`
  e `pRedAliqCBS` de `infNFSe/IBSCBS/valores/{uf,mun,fed}` (XSD V1.01).
- **Gerador (`DanfseGenerator`)** — célula "Red. Alíquota IBS / Red. Alíquota
  CBS" do bloco TRIBUTAÇÃO IBS/CBS agora exibe os valores reais. NFS-e é
  tributo municipal, então o lado IBS prioriza `pRedAliqMun` (fallback
  `pRedAliqUF`); CBS usa `pRedAliqCBS` diretamente. Quando nenhum dos dois
  é informado, mantém `"-"` formatado pra cada lado.
- **Conformidade NT 008/2026 item 2.1.10** — fecha o último gap do checkup
  contra a Nota Técnica que padroniza o DANFSe (API SEFIN de geração será
  descontinuada em 01/jul/2026). Demais requisitos da NT já estavam
  implementados (marca d'água "NFS-e SEM VALIDADE JURÍDICA" em homologação,
  "CANCELADA" e "SUBSTITUÍDA" diagonais, QR Code com texto auxiliar
  apontando pro Portal Nacional, bloco IBS/CBS completo).

## [0.16.0] — 2026-05-18

### Adicionado — Endereço internacional (`<endExt>`)

- **DTO `EnderecoExterior`** — endereço estrangeiro com `codigoPaisIso`
  (2 letras maiúsculas, ex: `'US'`, `'PT'`, `'DE'`), `codigoEnderecamentoPostal`
  (1-11 chars alfanuméricos), `cidade`, `estadoProvinciaRegiao` + os campos
  comuns (logradouro, numero, complemento, bairro). Mapeia para o grupo
  `<endExt>` dentro de `<end>` (XSD V1.01 TCEnderExt linhas 1191-1213).
- **Union type `Endereco|EnderecoExterior`** em `Tomador::$endereco` e
  `Intermediario::$endereco`. O `DpsBuilder` detecta o tipo e emite
  `<endNac>` ou `<endExt>` automaticamente. Caller passa um ou outro.
- **Helper interno `montarEnderecoTcEndereco`** no `DpsBuilder` —
  refatora a montagem de endereço, substituindo a duplicação anterior
  entre tomador e intermediário por uma única função que detecta tipo.

### Casos de uso destravados

- Tomador estrangeiro recebendo serviço (exportação)
- Intermediário internacional (marketplace global)
- Cenários com mistura de partes (prestador BR + tomador exterior + intermediário BR)

### Não incluído (futuro)

- `EnderecoExterior` em `Prestador` — caso raro (prestador estrangeiro
  emitindo NFS-e brasileira); estrutura igual mas requer ajuste no DTO
- `EnderecoExterior` em `obra.endereco` / `atvEvento.endereco` — esses
  usam `TCEnderecoSimples` (sem nação), estrutura diferente do `<endExt>`

### Testes

5 testes novos cobrindo:
- Tomador com endExt + assertions de cada campo
- Tomador continua emitindo endNac quando passa Endereco nacional
- Intermediário com endExt
- Validações de codigoPaisIso (case) e codigoEnderecamentoPostal (length)

Suite total: 286/286 OK. PHPStan level 8 limpo.

## [0.15.0] — 2026-05-18

### Adicionado — Onda 5 (parcial: 3 de 4 grupos do `<serv>`)

Implementa os 3 grupos opcionais de `<serv>` que existem no leiaute
atual (XSD v1.01). Os outros 2 grupos do CSV V1.00.02 (`<explRod>` e
`<lsadppu>`) foram **removidos do leiaute oficial entre v1.00 e v1.01**
— declarados fora-de-escopo.

- **`<comExt>` (Comércio Exterior)** — obrigatório quando
  `tributacaoIssqn = ExportacaoServico` (caso contrário cStat=330):
  - DTO `ComercioExterior` com 8 campos obrigatórios + 2 opcionais
  - 6 enums novos: `ModoPrestacao` (5 cases), `VinculoEntrePartes` (8),
    `MecanismoFomentoPrestador` (9), `MecanismoFomentoTomador` (26),
    `MovimentacaoTemporariaBens` (4), `EnvioMdic` (2)
  - `codigoMoeda` exige 3 dígitos numéricos BACEN (não ISO 4217 alfa):
    USD=220, EUR=978, BRL=790. XSD `TSCodMoeda` pattern `[0-9]{3}`.
  - **Smoke real validou**: NFS-e #142 emitida com cStat=100
    (`tribISSQN=3 + cPaisResult=US + comExt completo`)
- **`<obra>` (Informação de Obra)** — para serviços de construção civil:
  - DTO `InformacaoObra` com `inscImobFisc?` (IPTU) + choice obrigatório
    `cObra` (CNO/CEI) | `cCIB` | `endereco`
- **`<atvEvento>` (Atividade de Evento)** — para shows, conferências:
  - DTO `AtividadeEvento` com nome + período (dtIni/dtFim) + choice
    `idAtividadeEvento` (código municipal) | `endereco`
- **`Servico` ganha 3 parâmetros opcionais**: `$comExt`, `$obra`,
  `$atvEvento`. Ordem oficial no XML: `locPrest → cServ → comExt?
  → obra? → atvEvento? → infoCompl?` (TCServ XSD V1.01).
- **Helper interno `montarEnderecoSimples`** no `DpsBuilder` —
  endereço sem nação, usado em `<obra>/<end>` e `<atvEvento>/<end>`.

### Não incluído

- `<explRod>` (Exploração Rodoviária) — **removido do leiaute v1.01**,
  fora-de-escopo
- `<lsadppu>` (Locação/Sublocação para Particulares) — idem
- Endereço internacional (`endExt`) em `obra/end` e `atvEvento/end` —
  hoje só `endNac`. Onda 4 abrirá isso para todos os endereços.

### Cobertura

9 testes novos cobrindo:
- comExt: emissão completa, opcionais nDI/nRE, validação de codigoMoeda
- obra: cObra, endereço (choice), validação sem choice
- atvEvento: idAtvEvt, validação dataFim < dataInicio
- Ordem dos grupos em `<serv>` segue TCServ

Suite total: 281/281 OK. PHPStan level 8 limpo.

## [0.14.0] — 2026-05-18

### Alterado (BC-break) — caminho para v1.0.0

**Pré-1.0 SemVer permite breaking changes em minor bumps.** Esta release
substitui dois booleans do `Valores` por enums que cobrem todos os
estados reais do leiaute.

- **`Valores::$issqnRetido` (bool) → `Valores::$tipoRetencaoIssqn`
  (`TipoRetencaoIssqn`)**. O campo agora cobre os 3 estados do leiaute
  (XSD `tpRetISSQN`):
  - `TipoRetencaoIssqn::NaoRetido` (1) — default, equivale a `false` antigo
  - `TipoRetencaoIssqn::RetidoPeloTomador` (2) — equivale a `true` antigo
  - `TipoRetencaoIssqn::RetidoPeloIntermediario` (3) — caso novo
- **`Valores::$dispensadoIssqn` (bool) → `Valores::$motivoDispensaIssqn`
  (`?MotivoDispensaIssqn`)**. O bool antigo só dizia "dispensado ou não"
  — o novo enum captura o **motivo** semanticamente (auditoria + log):
  - `null` — default, sem dispensa (emite `<pTotTrib>`)
  - `MotivoDispensaIssqn::OptanteSimplesNacional` — MEI/ME/EPP
  - `MotivoDispensaIssqn::OperacaoImune` — CF 150 VI
  - `MotivoDispensaIssqn::OperacaoIsenta` — isenção municipal
  - `MotivoDispensaIssqn::Outros` — não-incidência, suspensão, etc.

  O valor emitido no XML continua `<indTotTrib>0</indTotTrib>` para
  qualquer case — o motivo é metadado/contexto pra rastreabilidade.

### Migração (consumidores)

```diff
+use PhpNfseNacional\Enums\TipoRetencaoIssqn;
+use PhpNfseNacional\Enums\MotivoDispensaIssqn;

 $valores = new Valores(
     valorServicos: 100.00,
     deducoesReducoes: 20.00,
     aliquotaIssqnPercentual: 4.00,
-    issqnRetido: false,
-    dispensadoIssqn: true,
+    tipoRetencaoIssqn: TipoRetencaoIssqn::NaoRetido,            // ou omitir (default)
+    motivoDispensaIssqn: MotivoDispensaIssqn::OptanteSimplesNacional, // MEI dispensado
 );
```

Mapeamento mecânico:
- `issqnRetido: false` → omitir (default) ou `tipoRetencaoIssqn: NaoRetido`
- `issqnRetido: true` → `tipoRetencaoIssqn: RetidoPeloTomador`
- `dispensadoIssqn: false` → omitir (default null)
- `dispensadoIssqn: true` (cenário típico MEI) → `motivoDispensaIssqn: OptanteSimplesNacional`

### Adicionado
- **Enum `MotivoDispensaIssqn`** (4 cases) — documenta o motivo da
  emissão de `<indTotTrib>0</indTotTrib>` para auditoria.

### Testes
- 5 testes novos cobrindo os 3 estados de `tipoRetencaoIssqn` + os 4
  cases de `motivoDispensaIssqn`. Suite total: 272/272 OK.

## [0.13.0] — 2026-05-18

### Adicionado — Onda 2 (parte 2/2): infoCompl, deduções, PIS/COFINS

- **`<infoCompl>` (Informações Complementares)** — grupo opcional dentro
  de `<serv>`. DTO `InformacoesComplementares` com 3 campos opcionais:
  `xInfComp` (texto livre até 2000 chars), `idDocTec`, `docRef`. Adicionado
  como parâmetro opcional em `Servico::__construct(...)`. Posicionado como
  ÚLTIMO filho de `<serv>` conforme TCServ no XSD.
- **`<documentos>/<docDedRed>` (Deduções com documentos referenciados)** —
  DTO `DocumentoDeducao` cobre o caso comum: choice de identificador
  (`chNFSe` 50 dig | `chNFe` 44 dig | `nDoc` texto livre) + `tpDedRed`
  (enum `TipoDeducaoReducao` com 9 cases) + `dtEmiDoc` + `vDedutivelRedutivel`
  + `vDeducaoReducao`. Enum `Outras` exige `descricaoOutraDeducao`.
  Campo `Valores::$documentosDeducao` (array) — quando preenchido, emite
  `<documentos>` no lugar de `<vDR>` (são choice no schema; validado XOR
  no construtor de `Valores`). Grupo `<fornec>` (fornecedor do documento)
  reservado para iteração futura.
- **`<tribFed>` (Tributação Federal)** — grupo opcional dentro de `<trib>`,
  posicionado entre `<tribMun>` e `<totTrib>`. Inclui:
  - **`<piscofins>`** — DTO `TributacaoPisCofins` + enum `CstPisCofins`
    (10 cases 00-09) + enum `TipoRetencaoPisCofins` (Retido / NaoRetido).
    Cobre CST, BC, alíquotas, valores apurados e indicação de retenção.
  - **Retenções federais flat** — `Valores::$valorRetidoCp`,
    `$valorRetidoIrrf`, `$valorRetidoCsll`. Vão em `<vRetCP>`, `<vRetIRRF>`,
    `<vRetCSLL>`.
- **19 testes novos** cobrindo todos os DTOs, validações XOR/range, ordem
  dos filhos e fluxo emissão. Suite total: 267/267.

## [0.12.0] — 2026-05-18

### Adicionado
- **Grupo `<interm>` (Intermediário da operação)** — DTO `Intermediario`
  + emissão opcional via `$nfse->emitir(..., intermediario: $i)`. Posicionado
  entre `<toma>` e `<serv>` conforme leiaute V1.00.02 (linhas 295-325).
  Suporta PJ (CNPJ) ou PF (CPF), com IM, endereço, fone e email opcionais.
  Útil pra marketplaces/plataformas (delivery, agências de turismo).
  7 testes novos.
- **`NFSe::estaCancelada($chave): bool`** — detecta cancelamento via
  `/contribuintes/NFSe/{chave}/Eventos`. **Forma canônica de detectar
  cancelamento** — `consultar($chave)->cancelada()` NÃO funciona porque
  `consultar()` retorna cStat=100 mesmo após cancelar (cancelamento é
  evento separado, não muda o cStat). 4 testes novos.
- **`docs/schemas/{1.00,1.01}/`** — XSDs oficiais do leiaute SefinNacional
  copiados pra referência local. Cobre DPS, NFSe, evento, pedRegEvento,
  tiposSimples/Complexos/Eventos/Cnc. Fonte canônica para resolver
  patterns, tipos, ocorrências.

### Corrigido
- **`SefinClient::listarEventosNfse` parser** — esperava chave `Eventos`
  mas o endpoint ADN `/contribuintes/NFSe/{chave}/Eventos` retorna na
  verdade o envelope `LoteDFe[]` (mesma estrutura do sync DFe).
  Confirmado empiricamente; aceita o nome canônico + fallbacks compat.
- **`ExigibilidadeSuspensa::$numeroProcesso` valida pattern oficial.**
  XSD `TSNumProcExigSuspensa` (em `tiposSimples_v1.01.xsd`) é
  `[0-9]{30}` — exatamente 30 dígitos numéricos. Antes aceitava qualquer
  string até 30 chars; agora valida estritamente. Empiricamente
  confirmado: NFS-e #141 emitida com sucesso (cStat=100) com formato
  correto, após CNJ formatado/só dígitos serem rejeitados com cStat=1235.

## [0.11.2] — 2026-05-18

### Adicionado
- **`RespostaDfe` ganha métodos derivados** pra responder consultas comuns
  sobre o lote sem novas chamadas HTTP. Listas por status (`chavesCanceladas`,
  `chavesConfirmadas`, `chavesRejeitadas`, `chavesSubstituidas`), filtros de
  itens (`itensNfse`, `itensEvento`), lookup por chave (`foiCancelada`,
  `eventosDaChave`, `statusPorChave`) e agregação (`agruparPorChave`).
  Hierarquia de status: SUBSTITUIDA → CANCELADA → REJEITADA → CONFIRMADA →
  EMITIDA. Filtragem case-insensitive por substring (`CONFIRMACAO_PRESTADOR`,
  `CONFIRMACAO_TOMADOR`, etc. mapeiam todos para "Confirmada"). 15 testes
  novos.

## [0.11.1] — 2026-05-18

### Corrigido
- **`DfeService` parser** — capturava só dois dos três campos importantes
  da resposta do ADN. Smoke real (162 DFes em homologação)
  revelou:
  - SEFIN devolve `DataHoraGeracao` (não `DataHoraRegistro` como presumido).
    Antes do fix, `ItemDfe::$dataHora` ficava sempre `null`.
  - Cada DFe traz `ArquivoXml` embutido (gzip+base64) — XML completo do
    documento já na resposta. Economiza N round-trips de `baixarXml()`
    ao processar um lote.

### Adicionado
- **`ItemDfe::$arquivoXmlGzipB64`** + helper **`arquivoXmlDecodificado(): ?string`**
  pra descompressão sob demanda. Acesso direto ao XML completo de cada
  NFS-e/evento na caixa postal sem chamadas HTTP extras.
- **`examples/sincronizar-dfe-homologacao.php`** — smoke da operação,
  validado em homologação (162 itens em ~1s).

### Notas empíricas
- `tipoEvento` na resposta ADN vem como **string descritiva**
  (`"CANCELAMENTO"`, `"CONFIRMACAO_PRESTADOR"`, `"REJEICAO_PRESTADOR"`),
  não código numérico (`101101`, etc.). PHPDoc do `ItemDfe::$tipoEvento`
  atualizado com valores empíricos.
- Caixa postal do prestador validado tem DFes desde 2023 — **sem limite
  temporal aparente**. Primeira sincronização (`NSU=0`) puxa todo o
  histórico; chamadas subsequentes com `ultimoNsu` persistido são
  incrementais.

## [0.11.0] — 2026-05-18

### Adicionado

- **Retry automático com backoff exponencial em `baixarPdf()`.** O endpoint
  ADN `/danfse/{chave}` é conhecidamente instável (confirmado empiricamente:
  HTTP 502 persistente em homologação). `SefinClient::baixarDanfse()` agora
  retenta em 502/503/504 e erros de conexão, com backoff `1.5s × tentativa`.
  Default 3 tentativas; customizável: `baixarPdf($chave, tentativas: 5)`.
  Códigos 4xx (404, etc.) continuam lançando na primeira (não-transientes).

- **`NFSe::verificarDps($idDps): bool`** — verifica se um DPS já foi enviado
  ao SEFIN sem baixar o corpo (usa `HEAD /dps/{id}`). Útil pra evitar dupla
  emissão (cliente que retenta agressivamente, sequencial reutilizado,
  recovery de crash). Retorna true em 200, false em 404, lança em outros.

- **`NFSe::listarEventos($chave): array`** — lista todos os eventos
  vinculados a uma NFS-e (cancelamento, substituição, manifestações) via
  `GET /contribuintes/NFSe/{chave}/Eventos` no ADN. Útil pra auditoria.

- **`NFSe::sincronizarDfe($ultimoNsu, $maxPaginas): RespostaDfe`** —
  Distribuição de DFe (Documentos Fiscais Eletrônicos). O SEFIN mantém
  uma "caixa postal" por CNPJ onde guarda eventos vinculados — NFS-es
  emitidas contra o CNPJ, cancelamentos recebidos, etc.

  Wire format: `GET /contribuintes/DFe/{NSU}?cnpjConsulta=...&lote=true`.
  Itera paginadamente até esgotar lotes (status `NenhumDocumentoLocalizado`)
  ou atingir `$maxPaginas` (default 20). Retorna `RespostaDfe` com
  `itens`, `ultimoNsu` e `temMais`. O caller persiste `ultimoNsu` pra
  sincronização incremental.

  Novos DTOs em `PhpNfseNacional\Sefin\`: `ItemDfe` e `RespostaDfe`.
  Novo serviço `Services\DfeService` (DI granular).

- **Fallback de extração de CNPJ pelo SAN ICP-Brasil.** `Certificate::fromPfxFile()`
  agora busca o CNPJ na extensão SAN com OID `2.16.76.1.3.3` quando não
  encontra no CN do subject. Resolve certs antigos ou de modelos exóticos
  que têm o CNPJ só no SAN (padrão DOC-ICP-04 da Receita Federal).

- **`TextoSanitizador` com mapping Latin-1 tipográfico.** Substitui en/em-dash,
  aspas curvas, ellipsis Unicode, NBSP e zero-width space pelos equivalentes
  ASCII. Evita E1235 quando o cliente cola texto do Word/Google Docs no
  `xInfComp` ou `discriminacao`. Inspirado no `SUBSTITUICOES_LATIN1` da
  `badbrans/brans-nfe` (MIT).

### Cobertura

32 testes novos (suite total: 219, 587 asserts). PHPStan level 8 limpo.

## [0.10.0] — 2026-05-18

### Adicionado
- **Cobertura completa do `<tribMun>` conforme spec V1.00.02.** O grupo
  passa a aceitar todos os elementos opcionais entre `<tribISSQN>` e
  `<tpRetISSQN>`:
  - **`Enums\TipoTributacaoIssqn`** (4 cases) — substitui o `tribISSQN`
    hardcoded em `1`. Default permanece Operação Tributável.
  - **`Enums\TipoExigibilidadeSuspensa`** (Judicial=1 / Administrativa=2)
    — campo `<tpSusp>` do `<exigSusp>`.
  - **`DTO\BeneficioMunicipal`** — grupo `<BM>` com `nBM` (14 dig)
    + *choice* `vRedBCBM | pRedBCBM`. Validação XOR no construtor.
  - **`DTO\ExigibilidadeSuspensa`** — grupo `<exigSusp>` com
    `tpSusp` + `nProcesso` (até 30 chars).
  - **`Enums\TipoImunidadeIssqn::NaoInformado = 0`** adicionado para
    casar com a spec completa (0–5).
- **Novos campos opcionais em `Valores`** (defaults null, sem BC-break):
  - `?TipoTributacaoIssqn $tributacaoIssqn` → `<tribISSQN>`
  - `?string $codigoPaisResultado` → `<cPaisResult>` (2 chars ISO, p/
    exportação)
  - `?BeneficioMunicipal $beneficioMunicipal` → `<BM>`
  - `?ExigibilidadeSuspensa $exigibilidadeSuspensa` → `<exigSusp>`
  - `?TipoImunidadeIssqn $imunidade` → `<tpImunidade>` (use junto com
    `tributacaoIssqn = Imunidade`)
  - `?float $aliquotaMunicipal` → `<pAliq>` (necessário só p/ município
    NÃO conveniado ao Sistema Nacional NFS-e — não confundir com
    `aliquotaIssqnPercentual` que vai pra `<pTotTribMun>`)
- **`DpsBuilder::appendValores`** emite todos os elementos novos na
  ordem oficial do schema: `tribISSQN → cPaisResult? → BM? → exigSusp?
  → tpImunidade? → pAliq? → tpRetISSQN`. Validado contra spec V1.00.02
  (Anexo IV, linhas 256-267).

### Corrigido
- **`DanfseLayout::tipoTributacaoIssqn()` — labels `tribISSQN` 2/3/4 invertidos.**
  Spec oficial (Anexo IV V1.00.02, linha 256) define:
  `1 = Tributável, 2 = Imunidade, 3 = Exportação, 4 = Não Incidência`.
  Tínhamos `2 = Exportação`, `3 = Não Incidência`, `4 = Imunidade` —
  inversão herdada de presunção sem fonte. Bug latente até hoje porque
  todas as emissões reais até v0.9.0 usavam `tribISSQN=1` (Tributável);
  qualquer NFS-e com imunidade/exportação no DANFSe mostrava texto
  errado. Docblock do `TipoImunidadeIssqn` também corrigido (aplicável
  quando `tribISSQN=2`, não `=4`).

## [0.9.0] — 2026-05-18

### Adicionado
- **`Servico::cTribNac` e `Servico::cNBS` aceitam enum.** O construtor
  agora aceita `ListaServicosNacional|string` e `ListaNbs|string` —
  passar `ListaServicosNacional::S010101` ou a string `'010101'` produz
  o mesmo resultado. As propriedades públicas continuam `string` (BC
  preservado). Quem já passava strings cruas segue funcionando sem
  alterações.
- **`Prestador::$regimeApuracaoSN` (`?RegimeApuracaoSimplesNacional`).**
  Quando setado, o `DpsBuilder` emite `<regApTribSN>` no `<regTrib>` do
  `<prest>`, entre `<opSimpNac>` e `<regEspTrib>`. Default `null` (campo
  é opcional pelo schema — emitido só quando o caller decide). Necessário
  quando o município/SEFIN exige discriminação de regime para ME/EPP.
  Diferente do `gildonei/nfse-nacional`, **não emitimos default mágico**
  (`"1=Caixa"`) quando ausente: deixamos o cStat do portal sinalizar caso
  seja obrigatório no contexto, evitando declarações implícitas erradas.

### Notas
- A flag `Valores::$dispensadoIssqn` (v0.7.0) continua a forma canônica
  pra MEI dispensado — `regimeApuracaoSN` cobre outro plano (regime de
  apuração federal/municipal), são complementares.

## [0.8.0] — 2026-05-18

### Adicionado
- **Enums de tabelas oficiais e cobertura tributária.** Seis novos enums em
  `PhpNfseNacional\Enums\`, sem alterações no `DpsBuilder` ainda (uso
  opcional pelo caller; o wiring no XML virá em PR separado para evitar
  BC-break):
  - `ListaServicosNacional` (335 cases) — códigos `cTribNac` da LC 116/2003
    com `descricao()` íntegra + parsers `item()` / `subitem()` / `desdobro()`.
    Tabela adaptada do projeto MIT [gildonei/nfse-nacional] (atribuição no
    cabeçalho do arquivo).
  - `ListaNbs` (917 cases) — códigos `cNBS` da Nomenclatura Brasileira de
    Serviços, com `descricao()` + parsers `secao()` / `divisao()` / `grupo()`
    / `classe()` / `subclasse()`. Mesma origem MIT.
  - `TipoBeneficioMunicipal` (4 cases: Isenção, RedBC%, RedBCValor,
    AlíquotaDiferenciada) — campo `tpBM` do grupo `<benef>` no DPS.
  - `TipoImunidadeIssqn` (5 cases referentes a CF 150 VI a/b/c/d/e) —
    campo `tpImunidade` do grupo `<tribImunidade>`.
  - `RegimeApuracaoSimplesNacional` (3 cases) — campo `regApTribSN`,
    obrigatório quando `opSimpNac ∈ {2,3}` (MEI/ME/EPP).
  - `TipoRetencaoIssqn` (3 cases: NaoRetido, RetidoTomador,
    RetidoIntermediario) — substituirá o atual `bool $issqnRetido` em
    futura versão maior (BC-break).

  10 testes novos validando `from()`, `descricao()`, parsers e contagem
  mínima. Suite total: 172 testes / 491 asserts.

  [gildonei/nfse-nacional]: https://github.com/gildonei/nfse-nacional

## [0.7.0] — 2026-05-18

### Removido
- **Validação cruzada `"ISSQN apurado = 0 com BC > 0"` no `DpsBuilder`.**
  Validar regra fiscal é responsabilidade do SEFIN, não da lib —
  cenários válidos (MEI dispensado, isenções, novos cTribNac) variam
  por município e mudam com o tempo. A lib agora aceita o cenário e
  monta o XML; quem decide se a operação é fiscalmente válida é o
  portal. O método privado `validarCruzado` foi removido inteiro.

### Adicionado
- **`Valores::$dispensadoIssqn` (default `false`).** Quando `true`, o
  grupo `<totTrib>` é emitido como `<indTotTrib>0</indTotTrib>` em vez
  de `<pTotTrib>` — mesmo padrão que o emissor web do SEFIN utiliza
  para CNPJ MEI. Ambos são opções válidas do *choice* TSTotTrib no
  leiaute SefinNacional 1.6. `pTotTrib` declara as alíquotas
  aproximadas (Lei 12.741/2012); `indTotTrib=0` indica que o valor
  total dos tributos NÃO foi informado — posição correta para
  prestador dispensado.

  Não é BC-break: o default mantém o comportamento atual (pTotTrib).
  3 testes novos em `DpsBuilderTest` cobrem o switch.

- **`<fone>` e `<email>` no grupo `<prest>`.** Quando `Prestador::$telefone`
  ou `Prestador::$email` estão preenchidos, o `DpsBuilder` os emite entre
  `<IM>` e `<regTrib>` — ordem confirmada contra XML real do emissor web
  do SEFIN (NFS-e MEI, abril 2026). Telefone é normalizado (só dígitos),
  email vai como informado. Os campos do DTO existiam desde antes mas não
  iam para o XML, omissão sistemática que ficou evidente no smoke MEI.
  3 testes novos cobrem presença, ausência e ordem dos filhos.

## [0.6.0] — 2026-05-13

### Alterado (BC-break)
- **`Prestador::$inscricaoMunicipal` agora é `?string` (nullable).** Passe
  `null` ou string vazia quando o prestador não deve declarar IM. Quando
  null/vazio, o SDK omite o nó `<IM>` do prestador no DPS. Resolve o
  caso **MEI emitindo em município sem cadastro no CNC NFS-e**, que era
  rejeitado pelo SEFIN com **cStat=120** ("IM do prestador não deve ser
  informada, pois não existem informações complementares registradas no
  CNC NFS-e do município emissor").
- O check `Inscrição municipal vazia` foi removido da validação do
  `Prestador`. String vazia / com espaços agora normaliza para `null`
  em vez de lançar `ValidationException`. Quem sempre passou IM
  preenchida continua funcionando sem mudança.

### Validação empírica
Confirmado em homologação SEFIN com PFX de prestador que TEM informações
complementares registradas no CNC NFS-e do município: DPS construído com
`inscricaoMunicipal: null` resulta em XML SEM `<IM>` dentro do `<prest>`
— SEFIN devolve `cStat=116` "A IM deve ser informada". Inverso simétrico
do `cStat=120` (caso de prestador sem CNC), provando que o mecanismo do
SDK está correto: o caller decide se a IM é enviada, com base no que o
CNC do município espera para o prestador.

## [0.5.2] — 2026-05-13

### Adicionado
- **`CStat::AdnSubstNaoAceitaViaEventos = 1861`** — código do SEFIN/ADN
  que indica que o evento e105102 (Cancelamento por Substituição) não
  pode ser enviado via `POST /nfse/{chave}/eventos`. Pode requerer
  endpoint dedicado ou parametrização específica do município.
  Validado em homologação SEFIN 13/05/2026 — parametrização do
  município ainda não habilita esse evento por essa rota.

## [0.5.1] — 2026-05-13

### Corrigido (3 bugs descobertos por agent durante smoke do nfse-monorepo)

- **Ordem dos elementos `<toma>` no DPS** — schema `TSDestinaDps` exige
  CPF/CNPJ → IM → xNome → **end** → **fone** → **email**. Anterior tinha
  fone/email ANTES do end → cStat=1235 ("invalid child element"). Cenários
  PF/PJ com email/telefone falhavam. Validado em homologação SEFIN.

- **`xDesc` da Substituição (e105102)** — texto exato exigido pela
  enumeração `TS_xDesc` é `"Cancelamento de NFS-e por Substituição"`.
  SDK estava enviando `"Cancelamento por substituição"` → cStat=1235
  ("Enumeration constraint failed"). Mesmo padrão dos eventos de
  Manifestação que já tinham sido corrigidos.

### Modificado (BREAKING — pré-1.0)

- **`EventoSubstituicao` agora usa `MotivoSubstituicao`, não
  `MotivoCancelamento`.** O leiaute SefinNacional 1.6 define dois enums
  distintos pra justificativa de evento:
  - `TSCodJustCanc` (cancelamento simples e101101): 1, 2, 9
  - `TSCodJustSubst` (substituição e105102): 01, 02, 03, 04, 05, 99

  Confundir os dois → cStat=1235. Novo enum `MotivoSubstituicao` com cases:
  - `DesenquadramentoSimples` (01)
  - `EnquadramentoSimples` (02)
  - `InclusaoImunidade` (03)
  - `ExclusaoImunidade` (04)
  - `RejeicaoTomador` (05)
  - `Outros` (99 — exige `xMotivo`)

- **Assinatura de `$nfse->substituir()` mudou:**
  - Antes: `(string $orig, string $subst, MotivoCancelamento, string $just)`
  - Agora: `(string $orig, string $subst, MotivoSubstituicao, string $just = '')`
  - `xMotivo` agora opcional (só obrigatório se `motivo=Outros`)

  Mesma mudança em `SubstituicaoService::substituir()` e
  `EventoSubstituicao::__construct()`.

### Notas

- Suite continua com 152 testes verdes (testes do EventoSubstituicao /
  SubstituicaoService atualizados pra usar `MotivoSubstituicao`).
- PHPStan level 8 limpo.
- Esses bugs nunca foram pegos antes porque não tínhamos testado
  substituição real em homologação SEFIN (só via mock). Smoke do agent
  no `nfse-monorepo` revelou ambos.

## [0.5.0] — 2026-05-13

### Modificado (BREAKING — pré-1.0)

- **API achatada no facade `NFSe`** — removidos os getters de subdomínio
  (`emissao()`, `consulta()`, `cancelamento()`, `substituicao()`,
  `manifestacao()`, `download()`, `danfse()`). As ações agora são métodos
  diretos:

  | Antes (v0.4.x)                                   | Agora (v0.5.0+)              |
  |--------------------------------------------------|------------------------------|
  | `$nfse->emissao()->emitir(...)`                  | `$nfse->emitir(...)`         |
  | `$nfse->consulta()->consultarNfse($chave)`       | `$nfse->consultar($chave)`   |
  | `$nfse->consulta()->consultarDps($chave)`        | `$nfse->consultarDps($chave)`|
  | `$nfse->consulta()->consultarEventos(...)`       | `$nfse->consultarEventos(...)`|
  | `$nfse->cancelamento()->cancelar(...)`           | `$nfse->cancelar(...)`       |
  | `$nfse->substituicao()->substituir(...)`         | `$nfse->substituir(...)`     |
  | `$nfse->manifestacao()->confirmar(...)`          | `$nfse->confirmar(...)`      |
  | `$nfse->manifestacao()->rejeitar(...)`           | `$nfse->rejeitar(...)`       |
  | `$nfse->manifestacao()->anularRejeicao(...)`     | `$nfse->anularRejeicao(...)` |
  | `$nfse->download()->xmlNfse($chave)`             | `$nfse->baixarXml($chave)`   |
  | `$nfse->download()->pdfDanfse($chave)`           | `$nfse->baixarPdf($chave)`   |
  | `$nfse->danfse()->gerarDoXml($xml, $custom)`     | `$nfse->danfseLocal($xml, $custom)` |
  | `$nfse->danfse()->gerarDeDados($dados, $custom)` | `$nfse->danfseLocalDeDados($dados, $custom)` |

  Motivo: a redundância "subdomínio→ação" (`->emissao()->emitir`) era
  ruído visual. API achatada lê melhor pro caso comum.

- **Service classes continuam públicos** em `PhpNfseNacional\Services\` —
  quem usa DI granular (Symfony/Laravel containers, mock por subdomínio)
  pode instanciar `EmissaoService`, `CancelamentoService`, etc. diretamente.
  Só a facade mudou.

### Migração

```php
// v0.4.x
$resp = $nfse->emissao()->emitir($id, $tomador, $servico, $valores);
$resp = $nfse->cancelamento()->cancelar($chave, $motivo, $just);

// v0.5.0
$resp = $nfse->emitir($id, $tomador, $servico, $valores);
$resp = $nfse->cancelar($chave, $motivo, $just);
```

`sed` pra atualizar projeto inteiro:
```bash
sed -i 's/\$nfse->emissao()->emitir/\$nfse->emitir/g; s/\$nfse->cancelamento()->cancelar/\$nfse->cancelar/g; s/\$nfse->substituicao()->substituir/\$nfse->substituir/g; s/\$nfse->manifestacao()->confirmar/\$nfse->confirmar/g; s/\$nfse->manifestacao()->rejeitar/\$nfse->rejeitar/g; s/\$nfse->manifestacao()->anularRejeicao/\$nfse->anularRejeicao/g; s/\$nfse->consulta()->consultarNfse/\$nfse->consultar/g; s/\$nfse->consulta()->consultarDps/\$nfse->consultarDps/g; s/\$nfse->consulta()->consultarEventos/\$nfse->consultarEventos/g; s/\$nfse->download()->xmlNfse/\$nfse->baixarXml/g; s/\$nfse->download()->pdfDanfse/\$nfse->baixarPdf/g; s/\$nfse->danfse()->gerarDoXml/\$nfse->danfseLocal/g; s/\$nfse->danfse()->gerarDeDados/\$nfse->danfseLocalDeDados/g' src/**/*.php
```

### Suite
- 152 testes verdes (sem mudança — testes usam Service direto, não facade).
- PHPStan level 8 limpo.
- Examples e MANUAL.md atualizados.

## [0.4.2] — 2026-05-13

### Adicionado
- **Cobertura de testes ampliada** — 152 testes verdes (era 137):
  - **`ManifestacaoServiceTest`** (7 testes) — Confirmação, Rejeição,
    Anulação Rejeição (PRE+56dig E 59 dígitos puros), idempotência (E0840),
    rejeição (cStat ≠ aceito), validação pré-HTTP de motivo=Outros sem
    xMotivo. Usa Guzzle MockHandler — não depende de SEFIN real.
  - **`DanfseCustomizacaoTest`** ganha `test_pdf_com_logo_prestador_renderiza_sem_quebrar`
    (gera PNG inline via GD pra cobrir o caminho do logo) +
    `test_temLogoPrestador_e_temObservacoesAdicionais_helpers`.
  - **`CertificateTest`** novo (6 testes) — gera PFX self-signed em runtime
    (openssl_pkey_new + openssl_csr_sign + openssl_pkcs12_export). Cobre
    `fromPfxContent`, `fromPfxFile`, senha errada, arquivo inexistente,
    extração de CNPJ do subjectCN, construtor direto com PEMs.
- Cobertura agora atinge ~todas as superfícies públicas do SDK.

## [0.4.1] — 2026-05-13

### Adicionado
- **`DanfseCustomizacao` DTO** — customizações opcionais do DANFSe local:
  - `logoPrestadorPath` — caminho pra logo do prestador (PNG/JPG).
    Renderizado no canto superior direito do bloco PRESTADOR (4cm × 1.26cm).
    NÃO substitui o logo institucional NFSe do cabeçalho (regra NT 008/2026).
  - `observacoesAdicionais` — texto livre concatenado às informações
    complementares vindas do XML (`<xOutInf>`). Max 2000 chars.
- `DanfseService::gerarDoXml($xml, ?$custom)` e
  `DanfseService::gerarDeDados($dados, ?$custom)` aceitam o segundo
  parâmetro opcional.
- `enum CStat`: novo case `ErroFalhaConfiguracao = 999` — erro genérico
  do SEFIN/ADN ("Falha de configuração", geralmente evento não
  habilitado pra município/cenário).

### Validado em homologação SEFIN
- ✅ Confirmação do Prestador (e202201) — cStat=100
- ✅ Rejeição do Prestador (e202205, motivo Duplicidade) — cStat=100
- ⚠️ Anulação da Rejeição (e205208) — cStat=999 ("Falha de configuração").
  Provavelmente parametrização do município ainda não habilita
  esse evento em homologação. Outros municípios podem ter habilitado.

### Corrigido (descoberto durante teste de manifestação)
- **xDesc das manifestações exige prefixo "Manifestação de NFS-e - "** —
  o leiaute SefinNacional 1.6 restringe `TS_xDesc` a uma enumeração
  fechada. Antes: `'Confirmação do Prestador'` (inválido). Agora:
  `'Manifestação de NFS-e - Confirmação do Prestador'` (válido).
  Aplicado em `EventoConfirmacao`, `EventoRejeicao`,
  `EventoAnulacaoRejeicao`.
- **`EventoAnulacaoRejeicao` ganha campo `cpfAgente` obrigatório** —
  schema XSD do `<infAnRej>` exige a ordem `CPFAgTrib → idEvManifRej →
  xMotivo`. Sem o CPF, schema falha com E1235.
- **`EventoAnulacaoRejeicao::idEvManifRej` agora aceita 2 formatos** —
  o leiaute usa `TSIdNumEvento` (59 dígitos puros: chave50 +
  tipoEvento6 + nSeq3), mas o `Id` da Rejeição original vem como
  `PRE` + 56 dígitos. SDK aceita ambos e converte internamente
  pro formato esperado.

### Suite
- 137 testes verdes (+5: 4 do `DanfseCustomizacaoTest` + 1 case novo no enum).
- PHPStan level 8 limpo.

## [0.4.0] — 2026-05-13

### Adicionado
- **Manifestação de NFS-e** — eventos pelo Prestador, Tomador ou
  Intermediário pra confirmar ou rejeitar uma NFS-e emitida. Novo
  service exposto via `$nfse->manifestacao()`:
  - `confirmar(chave, AutorManifestacao)` — gera evento e202201/203202/204203
  - `rejeitar(chave, AutorManifestacao, MotivoRejeicao, ?xMotivo)` — gera
    e202205/203206/204207. xMotivo obrigatório quando motivo=Outros.
  - `anularRejeicao(chave, idRejeicaoOriginal, xMotivo)` — gera e205208,
    desfaz uma Rejeição anterior referenciando o Id dela.
- **3 DTOs de evento novos:** `EventoConfirmacao`, `EventoRejeicao`,
  `EventoAnulacaoRejeicao` (`src/Dps/`).
- **Enum `AutorManifestacao`** com helpers `codigoConfirmacao()` e
  `codigoRejeicao()` que devolvem o código do evento conforme o autor.
- **Enum `MotivoRejeicao`** com 6 cases (Duplicidade, JaEmitidaPeloTomador,
  SemFatoGerador, ErroResponsabilidade, ErroValorOuData, Outros) +
  `label()` e `exigeXMotivo()`.

### Corrigido (breaking)
- **Código do evento de Substituição corrigido** — era `101102`, deveria
  ser **`105102`** conforme o leiaute oficial SefinNacional 1.6
  (Anexo I/Manual de Integração página 56). Bug nunca foi pego porque
  ninguém testou substituição em homologação real (só cancelamento).
  Atualizado:
  - `EventoSubstituicao::codigoTipoEvento()` → `'105102'`
  - Docblocks em `EventoSubstituicao`, `SubstituicaoService`, `EventoNfse`,
    `CStat::CanceladaPorSubstituicao`
  - `MANUAL.md`
  - Teste `EventoSubstituicaoTest`

### Documentado
- `EventoNfse` (interface) lista todos os 16 códigos de evento conhecidos
  do leiaute (cancelamento, substituição, análise fiscal, manifestação,
  ofício, etc.) com descrição. Útil pra quem quiser implementar evento
  customizado fora do SDK.
- `MANUAL.md` ganha seção "Manifestação de NFS-e" com tabela de códigos
  por autor, restrições do leiaute (E1833, E1835) e exemplos de uso.

### Suite
- 131 testes verdes (+12 novos: 4 EventoConfirmacao, 5 EventoRejeicao,
  3 EventoAnulacaoRejeicao).
- PHPStan level 8 limpo.

## [0.3.8] — 2026-05-13

### Adicionado
- **`enum CStat: int`** — códigos de status do SEFIN/ADN tipados.
  53 cases cobrindo:
  - **6 sucessos:** `Emitida` (100), `Cancelada` (101),
    `CanceladaPorSubstituicao` (102), `EventoRegistrado` (135),
    `CancelamentoHomologado` (155), `EventoVinculado` (840 idempotente).
  - **7 erros comuns SEFIN:** `ErroDhEmiPosteriorAoProc` (8),
    `ErroCompetPosteriorAoEmi` (15), `ErroConvenioInativo` (38),
    `ErroRegEspTribComDeducao` (438), `ErroDeducaoNaoPermitida` (440),
    `ErroSchemaXml` (1235), `ErroEmitenteNaoHabilitado` (9996).
  - **40 erros ADN** (1800-2032 — manifestação, análise fiscal,
    bloqueio, compartilhamento) extraídos do Anexo IV oficial do
    leiaute SefinNacional 1.6 (CSV produção).
  - Helpers: `descricao()` retorna mensagem humana; `ehSucesso()`,
    `ehErroSefin()`, `ehErroAdn()`, `ehErroSchema()` pra classificação.
  - Constants estáticos: `aceitosEvento()` ([100, 135, 155, 840]),
    `estadosCancelada()` ([101, 102, 135, 155]).
- **`SefinResposta::eventoIdempotente()`** — true quando cStat=840
  (evento já estava vinculado à NFS-e antes desta tentativa).
  Substitui comparação direta `$resp->cStat === 840` em código cliente.
- **`SefinResposta::cStatTipado()`** — devolve `?CStat`. Use pra
  comparação tipada: `if ($resp->cStatTipado()?->ehErroSchema()) {…}`.

### Modificado
- `CancelamentoService` e `SubstituicaoService` agora usam
  `CStat::aceitosEvento()` em vez de array hardcoded `[100, 135, 155, 840]`.
- Logs `'ja_existia' => $cStat === 840` viraram `=== CStat::EventoVinculado->value`.
- Examples `cancelar.php` e `substituir.php` usam `$resp->eventoIdempotente()`.

### Suite
- 119 testes verdes (+9 novos: 8 do `CStatTest` + 1 ajustado).
- PHPStan level 8 limpo.

## [0.3.7] — 2026-05-13

### Adicionado
- **`Identificacao::$dataEmissao`** opcional — override de `dhEmi`
  pra cenários "tipo contingência" (DPS gerada offline e enviada
  retroativa). Default null = `DpsBuilder` gera com `now()` SP -60s
  como sempre. Quando preenchido, usa o valor exato (sem margem de
  60s), convertido pra `America/Sao_Paulo`.
- **PHP 8.5 na matriz CI** — workflow agora roda em PHP 8.1, 8.2,
  8.3, 8.4, 8.5.
- **Seção "Emissão retroativa (~contingência)" no `MANUAL.md`** com
  tabela de limites empíricos, erros comuns, e exemplos de uso. Validado
  emitindo 6 NFS-es (#64–#66) em homologação SEFIN com `dhEmi` recuado
  -1d, -7d, -30d, -45d, -60d, -61d, -62d, -63d (todas ✅) e `-64d` em
  diante (rejeitadas por motivos diferentes — ver tabela na doc).
- Tabela de arredondamento ampliada no `MANUAL.md` com casos "acima de
  5 na 3ª casa" validados empiricamente em PHP 8.4 + homologação SEFIN:
  - `3.5995` → `pTotTribMun=3.60` (transborda unidade), `pAliqAplic=4.00`
    (NFS-e #63)

### Modificado (breaking — pré-1.0)
- **`TipoEmissaoDps` corrigido** — enum estava conceitualmente errado,
  copiado do mundo NF-e (que tem Normal/Contingencia/ContingenciaOffline).
  No SefinNacional 1.6 o `tpEmit` identifica QUEM emite, não o modo
  online/offline. Cases corretos (alinhado com Anexo IV do leiaute oficial):
  - `Prestador` (1) — antes `Normal` — emissão pelo prestador (default)
  - `Tomador` (2) — antes `Contingencia` — leiaute aceita mas SEFIN
    rejeita com cStat=9996 ("não permitida nesta versão da aplicação")
  - `Intermediario` (3) — antes `ContingenciaOffline` — mesma situação
  Validado empiricamente em homologação 13/05/2026 tentando `tpEmit=2`
  e `tpEmit=3`.
- **Não existe "contingência" como flag dedicada na SefinNacional 1.6**
  (diferente da NF-e). Cenários offline são tratados via `dhEmi`
  retroativo + tpEmit=1 (Prestador). Ver seção dedicada no MANUAL.

### Achados documentados
- Achado reforçado: independente do `pTotTribMun` enviado (3.51, 3.56,
  3.60, 4.00, …), SEFIN sempre aplica o `pAliqAplic` cadastrado pelo
  município — confirmando que o campo é puramente declaratório (Lei
  12.741/2012).
- **Limite de retroatividade do convênio:** SEFIN aceita `dhEmi`
  retroativo sem limite fixo de dias. O que limita é (a) **vigência do
  convênio do município** (cStat=38 quando convênio inativo na data) e
  (b) **parametrização tributária histórica** (cStat=440 quando regra de
  dedução/regime mudou). Pro município validado: convênio ativo
  há aproximadamente 63 dias na data do teste.

### Suite
- 110 testes verdes (+1 novo:
  `test_dhEmi_aceita_override_via_Identificacao_dataEmissao`).
- PHPStan level 8 limpo.

## [0.3.6] — 2026-05-13

### Documentado
- **Achado importante: `pTotTribMun` é declaratório, não tributário.**
  Validado empiricamente em homologação SEFIN: enviando alíquotas 3.51,
  3.56 pro prestador testado (LC 116 item 21.01), SEFIN ignorou o valor
  enviado e usou o `pAliqAplic` oficial cadastrado pela prefeitura
  pra calcular o ISSQN. Ou seja: o `pTotTribMun` no DPS é
  apenas a "alíquota aproximada de tributos municipais" pra Lei
  12.741/2012 (Transparência Fiscal) — NÃO define a alíquota efetiva
  do ISSQN. A alíquota real vem do cadastro tributário do município
  e aparece no `<pAliqAplic>` da resposta autorizada.
- **Tabela de comportamento de arredondamento** validada empiricamente
  em PHP 8.4 (NFS-es #61 e #62 emitidas em homologação) — input vs
  output no DPS vs `pAliqAplic` na resposta SEFIN.
- **Caveat float-point esclarecido:** PHP 8+ corrigiu o caso clássico
  (`round(0.115, 2)` agora retorna `0.12` corretamente em PHP 8.1+).
  Como o SDK requer PHP 8.1+, esse pega não atinge mais.
- **Tabela de `cTribNac` por segmento** adicionada ao `MANUAL.md` —
  cobre cartório, advocacia, medicina, engenharia, contabilidade,
  educação, informática, construção civil, transporte. Cada segmento
  tem seus códigos LC 116/NBS específicos; SDK aceita qualquer um via
  parâmetros do `Servico`.

## [0.3.5] — 2026-05-13

### Adicionado
- **`Tomador::$inscricaoMunicipal`** opcional — quando preenchido, emite
  `<toma><IM>` no DPS conforme leiaute SefinNacional 1.6 (schema TSDestinaDps).
  Útil quando o tomador é PJ no mesmo município do prestador (cruzamento de
  dados pela prefeitura, imunidade tributária por IM). Em cartórios de
  registro de imóveis fica null normalmente.

### Removido (breaking — pré-1.0)
- **`Config::ehProducao()`** — método helper redundante. Use comparação direta
  com o enum: `$config->ambiente === Ambiente::Producao`. Segue convenção do
  `nfephp-org/sped-nfe` que também não tem helper.

### Documentado
- **Precisão e arredondamento** detalhado em `MANUAL.md` e no docblock de
  `Valores`:
  - Valores monetários: 2 casas decimais.
  - Alíquotas (`pTotTribMun`): **2 casas decimais fixas no DPS**, leiaute
    SefinNacional 1.6 restringe ao tipo `TSDec3V2`. **Diferente da NF-e**
    (NT 03.14, que ampliou pra 4 casas) — enviar 4 casas resulta em E1235
    ("Pattern constraint failed"). Confirmado empiricamente em homologação
    SEFIN: tentamos `pTotTribMun=4.0000` e foi rejeitado; `4.00` é aceito.
  - Modo `HALF_UP` do PHP `round()`: 0.125 → 0.13, 0.005 → 0.01.
  - Caveat float-point: `round(0.115, 2) = 0.11` (não `0.12`) porque
    `0.115` em binário é `0.114999…`.

### Modificado
- `MANUAL.md` reflete remoção de `ehProducao` e adição de IM no Tomador.

### Validado
- NFS-e #60 emitida em homologação SEFIN com `pTotTribMun=4.00` (2 casas)
  após confirmar que 4 casas são rejeitadas.
- Suite: 109 testes verdes (+4: `pTotTribMun_2_casas`,
  `pTotTribMun_arredonda`, `tomador_IM_omitida_quando_null`,
  `tomador_IM_emitida_quando_preenchida`).

## [0.3.4] — 2026-05-13

### Adicionado
- **Toggle `Config::incluirIbsCbs`** (default `false`) — controla se o bloco
  `<IBSCBS>` é incluído no DPS de envio. Validado empiricamente em homologação:
  SEFIN aceita DPS com OU sem o bloco — quando ausente, o IBSCBS também não
  aparece na resposta autorizada (opt-in pelo emissor).
  - NFS-e #58 emitida com toggle on → resposta tem IBSCBS completo
    (IBS UF 0.10%, IBS Mun 0%, CBS 0.90%, total R$ 0.97 sobre R$ 100)
  - NFS-e #59 emitida com toggle off → resposta sem IBSCBS, idêntico ao
    comportamento atual do `hadder/nfse-nacional`
- Example `emitir-homologacao.php` aceita env `INCLUIR_IBSCBS=1` pra ligar
  o toggle.

### Modificado (breaking — pré-1.0)
- `DpsBuilder` deixa de incluir `<IBSCBS>` por padrão. Pra manter o
  comportamento da v0.3.3 (sempre incluir): passar `incluirIbsCbs: true` no
  `Config`.

### Corrigido
- **`dCompet` agora respeita timezone do `dhEmi`** — gerava DPS com
  `dCompet > dhEmi.date` em servidores com PHP em UTC durante horário noturno
  (00:00–03:00 UTC = ainda dia anterior em SP). SEFIN rejeitava com cStat=15
  E0015 ("data de competência posterior à data de emissão"). Fix: formatar
  `dCompet` em `America/Sao_Paulo` (mesma tz do `dhEmi`).
- **`Total do IBS/CBS` na DANFSe local** — usava `<vTotNF>` (= valor total
  da nota) em vez de somar `<vIBSTot> + <vCBS>`. Bug visível: NFS-e de R$
  100,00 com IBS/CBS de R$ 0,97 mostrava "Total IBS/CBS = R$ 100,00" e
  "Líquido + IBS/CBS = R$ 200,00". Após fix: R$ 0,97 e R$ 100,97.
- 105 testes verdes (era 103); novos testes regressivos pro dCompet em UTC,
  pro toggle IBSCBS (on/off), e pro cálculo do total IBS/CBS no parser.

## [0.3.3] — 2026-05-12

### Adicionado
- **`MANUAL.md`** — referência completa da API estilo Swagger em Markdown
  (670 linhas). Cobre toda a superfície pública por operação: bootstrap
  (Certificate, Prestador, Endereco, Config, NFSe::create), 6 operações
  com assinatura/parâmetros/retorno/exceções/exemplo (emissão, consulta,
  cancelamento, substituição, download, DANFSe local), tipo de retorno
  (SefinResposta), DTOs de entrada, enums, hierarquia de exceções,
  eventos customizados (extensibilidade), apêndice OpenSSL legacy provider.
- README aponta pro MANUAL no topo.

### Corrigido
- **CI: PHPUnit em PHP 8.3** falhava porque o Composer instalado pelo
  `setup-php@v2` rejeitava o GHS token JWT-formatado e printava ele
  inteiro na mensagem de erro (vazamento em log público — token já
  expirado). Mitigações:
  - `permissions: contents: read` no workflow (limita escopo do GITHUB_TOKEN)
  - `COMPOSER_AUTH: '{}'` no step Install (deps são todas públicas no
    Packagist, não precisa autenticar contra GitHub)
  - `actions/checkout@v4` → `@v5` (Node.js 20 deprecation jun/2026)

## [0.3.2] — 2026-05-12

### Adicionado
- **Substituição de NFS-e (evento e101102)** — `EventoSubstituicao` +
  `SubstituicaoService`, exposto como `$nfse->substituicao()->substituir(
  $chaveOriginal, $chaveSubstituta, $motivo, $justificativa)`. Reusa o
  `EventoBuilder` genérico — só adiciona `chSubstituta` no grupo
  `<e101102>`. Mesma regra de aceitação de cStat do cancelamento (100,
  135, 155 → ok; 840 → idempotente; demais → SefinException).
- **5 examples novos** cobrindo o ciclo de vida completo: `cancelar.php`,
  `substituir.php`, `consultar.php`, `download.php` e `danfse-local.php`,
  mais um `_bootstrap.php` compartilhado (env vars do prestador →
  `NFSe::create()`).
- **Workflow CI no GitHub Actions** (`.github/workflows/ci.yml`) — PHPUnit
  matriz PHP 8.1/8.2/8.3/8.4 + PHPStan level 8 em job separado. Cache do
  Composer.
- **`CancelamentoServiceTest`** (3 testes) — cobre regra de cStat aceito
  ({100, 135, 155, 840} → ok; demais → SefinException).
- **`SubstituicaoServiceTest`** (3 testes) — espelha o cancelamento.
- **`EventoSubstituicaoTest`** (5 testes) — validações da DTO (chaves de 50
  dígitos, chaves diferentes, justificativa 15-200 chars, máscaras).
- **`DownloadServiceTest`** (5 testes) — valida chave de acesso, cobre
  pdfDanfse (sucesso, conteúdo não-PDF, HTTP ≠ 200) e xmlNfse (extração do
  envelope gzip+base64).
- **DTO tests** — `EnderecoTest` (7), `IdentificacaoTest` (7),
  `ServicoTest` (5), `PrestadorTest` (5). Total da suíte: **102 testes**
  (era 62).

### Modificado
- `Signer` deixou de ser `final` pra permitir extensão em testes (mock do
  fluxo de assinatura sem precisar gerar PFX). Sem impacto em código de
  produção.
- `composer.json` — script `phpstan` passa `--memory-limit=512M` (a análise
  estourava o default de 128 MB).

### Documentação
- README — removido "Em desenvolvimento, falta bateria de testes" (já são
  102). Adicionado bloco "Substituição" + roadmap atualizado. Badges CI /
  Packagist / PHP version / License.

## [0.3.1] — 2026-05-12

### Adicionado
- **Lookup IBGE de municípios** (`Support/IbgeMunicipios`) — 5.571 municípios
  brasileiros embarcados em `resources/data/ibge-municipios.json` (240 KB),
  permite resolver "Município / Sigla UF" a partir do código IBGE de 7 dígitos
  quando o XML da NFS-e só traz o código (caso comum em DPS).
- Subclasse `TcpdfSemLink` que neutraliza o link "Powered by TCPDF" injetado
  pelo TCPDF no rodapé.

### Corrigido
- **Texto de autenticidade do QR Code invadindo o bloco PRESTADOR** —
  largura aumentada de 2,2cm pra 4,78cm (toda a coluna 4), o que faz o
  MultiCell respeitar a quebra de 3 linhas prevista na NT 008 item 2.4.3
  em vez de quebrar em 9+ linhas estreitas.
- **Blocos suprimidos (Destinatário/Tomador/Intermediário não identificados)**
  agora ocupam uma linha única (altura 0,40 cm) com o texto centralizado,
  no estilo do ADN, em vez de ocupar título + caixa separados (item 2.3.1
  e 2.3.2 da NT 008).
- **Tomador/Destinatário/Intermediário** mostravam "-/-" no campo
  "Município / Sigla UF" quando o XML do DPS só trazia o código IBGE (sem
  o nome do município) — agora faz lookup automático na base IBGE.
- "Powered by TCPDF (www.tcpdf.org)" removido do rodapé do DANFSe.

### Refatorado
- Extraídas constantes `Config::NFSE_NAMESPACE` e `Config::LEIAUTE_VERSAO`
  pra remover hardcoding de `'http://www.sped.fazenda.gov.br/nfse'` e
  `'1.01'` em `DpsBuilder`, `EventoBuilder` e `DanfseXmlParser`.

## [0.3.0] — 2026-05-12

### Adicionado
- **DANFSe NT 008/2026 completo** — refatoração total do `DanfseGenerator`
  seguindo a Nota Técnica nº 008/2026 da SE/CGNFS-e (v1.0). Implementa
  todos os 13 blocos obrigatórios do Anexo I:
  1. Cabeçalho (logo NFSe + DANFSe v2.0 + município + ambiente + QR Code)
  2. Dados da NFS-e (chave, número, datas, situação, finalidade)
  3. Prestador / Fornecedor (com Simples Nacional e Regime de Apuração)
  4. Tomador / Adquirente
  5. Destinatário da Operação (com supressão automática item 2.3.2)
  6. Intermediário da Operação (com supressão automática item 2.3.1)
  7. Serviço Prestado (códigos + descrição tributação + descrição serviço)
  8. Tributação Municipal (ISSQN)
  9. Tributação Federal (exceto CBS) — IRRF/INSS/CSLL/PIS/COFINS
  10. Tributação IBS/CBS (Reforma Tributária)
  11. Valor Total da NFS-e (com VALOR LÍQUIDO + IBS/CBS sombreado)
  12. Informações Complementares (com Totais Aproximados Lei 12.741/2012)
- **`docs/nt-008-se-cgnfse-danfse-20260505.pdf`** — NT oficial incluída no
  pacote pra referência (sistema deve gerar DANFSe local após 01/07/2026
  quando o ADN/Portal Nacional será desativado).
- **`resources/assets/logo-nfse-horizontal.png`** — logo oficial NFSe
  pra renderização no cabeçalho.
- **Marca d'água "CANCELADA"/"SUBSTITUÍDA"** — diagonal -45°, cinza K35,
  50pt, conforme item 2.5.1/2.5.2 da NT 008.
- **Tarja "NFS-e SEM VALIDADE JURÍDICA"** — vermelha M100/Y100 no
  cabeçalho quando `tpAmb=2` (homologação/produção restrita).
- **Suporte completo a tipos de bloco supressível** (item 2.3 da NT):
  - "TOMADOR/ADQUIRENTE DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e"
  - "O DESTINATÁRIO É O PRÓPRIO TOMADOR/ADQUIRENTE DA OPERAÇÃO"
  - "INTERMEDIÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e"
  - "TRIBUTAÇÃO MUNICIPAL (ISSQN) - OPERAÇÃO NÃO SUJEITA AO ISSQN"
- **2 testes novos** validando estrutura NT 008 (16 blocos/labels obrigatórios
  + tarja homologação) — 61 testes totais.

### Modificado (breaking)
- **`DanfseDados`**: novo esquema com arrays por bloco
  (`prestador`, `tomador`, `destinatario`, `intermediario`, `servico`,
  `tributacaoMunicipal`, `tributacaoFederal`, `tributacaoIbsCbs`,
  `valorTotal`, `informacoesComplementares`). Versão 0.2.x tinha
  `valores` e `tributos` planos — quem consumir diretamente o DTO
  precisa migrar.
- **`DanfseXmlParser`**: extração reorganizada por bloco, com helpers
  pra parsing de IBS/CBS, Tributação Federal e Informações Complementares.
- **`DanfseLayout`**: constantes alinhadas com a tabela 2.4.5 da NT 008
  (coordenadas em cm, fontes Arial/Microsoft Sans Serif, sombreamento
  5%, etc).

### Corrigido
- Sobreposição visual entre blocos (era causada por posicionamento Y
  absoluto). Agora layout é sequencial via cursor `$this->cursorY`.
- QR Code não renderizava por falta de autoload do `TCPDF2DBarcode`
  (não é PSR-4). Adicionado require manual em `DanfseGenerator`.

## [0.2.0] — 2026-05-12

### Adicionado
- **`DanfseService`** (`$nfse->danfse()`) — fachada que orquestra
  `DanfseXmlParser` + `DanfseGenerator` pra gerar PDF DANFSE local a partir
  do XML autorizado (NT 008/2026). Alternativa ao download via ADN.
- Testes pra `EventoBuilder`, `DanfseXmlParser`, `DanfseService` e
  `SefinClient::parsearResposta` (23 testes novos, 58 totais).

### Corrigido
- **`EventoBuilder`** — formato do atributo `Id` do `<infPedReg>` ajustado pra
  `PRE+chave(50)+tpEvento(6)` (59 chars), alinhando com o schema
  `TSIdPedRegEvt` do SEFIN Nacional 1.6. Versão anterior usava `EVT` + 2
  dígitos de `nSequencial`, o que disparava E1235 ("Pattern constraint
  failed").
- **`EventoBuilder`** — ordem dos elementos filhos do `<infPedReg>` corrigida
  pra `tpAmb → verAplic → dhEvento → CNPJAutor → chNFSe → grupo`, conforme
  exigido pelo schema. Versão anterior emitia `chNFSe` primeiro e falhava
  com E1235.
- **`SefinClient::parsearResposta`** — agora reconhece também o formato de
  erro do endpoint de eventos, que usa a chave singular `erro` (em vez de
  `erros`) e subcampos `codigo/descricao/complemento` (lowercase, em vez de
  Capitalizado). Cobertura ampliada pra qualquer campo `*XmlGZipB64` no
  payload de sucesso (não só `nfseXmlGZipB64`).
- **`SefinClient::extrairXmlDoEnvelope`** (renomeado de
  `descomprimirSeNecessario`) — agora usa `json_decode` em vez de regex
  pra extrair o campo `*XmlGZipB64`. O regex anterior falhava em JSON
  pretty-printed por causa do espaço em `": "`, fazendo
  `DownloadService::xmlNfse()` devolver o JSON cru em vez do XML.
- **`CancelamentoService`** — aceita cStat ∈ `{100, 135, 155}` como
  sucesso de evento e cStat `840` (E0840) como idempotente (cancelamento
  já registrado previamente).

### Refatorado
- `SefinClient::descomprimirSeNecessario` → `extrairXmlDoEnvelope` (nome
  descreve o que faz: extrai o XML cru do envelope JSON gzip+base64).

## [0.1.0] — 2026-05-12

### Adicionado
- Primeira versão pública. Suporta emissão DPS, consulta, cancelamento,
  download (XML + DANFSE) e geração local do DANFSE (NT 008/2026).
- Framework-agnostic — só depende de PSR-3 (Logger), PSR-18 (HTTP),
  Guzzle e TCPDF.
