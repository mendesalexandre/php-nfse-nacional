# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e
versionamento conforme [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

### Modificado (breaking — pré-1.0)
- **`TipoEmissaoDps` corrigido** — enum estava conceitualmente errado,
  copiado do mundo NF-e (que tem Normal/Contingencia/ContingenciaOffline).
  No SefinNacional 1.6 o `tpEmit` identifica QUEM emite, não o modo
  online/offline. Cases corretos:
  - `Prestador` (1) — antes `Normal` — emissão pelo prestador (default)
  - `Tomador` (2) — antes `Contingencia` — leiaute aceita mas SEFIN
    rejeita com cStat=9996 ("não permitida nesta versão da aplicação")
  - `Intermediario` (3) — antes `ContingenciaOffline` — mesma situação
  Validado empiricamente em homologação 13/05/2026 tentando `tpEmit=2`
  e `tpEmit=3`.
- **Não existe "contingência" como flag dedicada na SefinNacional 1.6**
  (diferente da NF-e). Cenários offline são tratados via `dhEmi`
  retroativo + tpEmit=1 (Prestador). Documentado no docblock do enum.

### Adicionado
- **PHP 8.5 na matriz CI** — workflow agora roda em PHP 8.1, 8.2, 8.3, 8.4, 8.5.
- Tabela de arredondamento ampliada no `MANUAL.md` com casos "acima de 5
  na 3ª casa" validados empiricamente em PHP 8.4 + homologação SEFIN:
  - `3.5995` → `pTotTribMun=3.60` (transborda unidade), `pAliqAplic=4.00`
    (NFS-e #63)
- Achado reforçado: independente do `pTotTribMun` enviado (3.51, 3.56,
  3.60, 4.00, …), SEFIN sempre aplica `pAliqAplic=4.00` pra cartório
  de Sinop — confirmando que o campo é puramente declaratório (Lei
  12.741/2012).

## [0.3.6] — 2026-05-13

### Documentado
- **Achado importante: `pTotTribMun` é declaratório, não tributário.**
  Validado empiricamente em homologação SEFIN: enviando alíquotas 3.51,
  3.56 pro cartório de Sinop (LC 116 item 21.01), SEFIN ignorou o valor
  enviado e usou `pAliqAplic=4.00` (alíquota oficial cadastrada pela
  prefeitura) pra calcular o ISSQN. Ou seja: o `pTotTribMun` no DPS é
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
  dados pela prefeitura, imunidade tributária por IM). Em cartório de RI
  fica null normalmente.

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
