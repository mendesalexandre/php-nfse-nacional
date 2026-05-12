# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e
versionamento conforme [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

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
