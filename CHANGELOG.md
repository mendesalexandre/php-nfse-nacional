# Changelog

Formato baseado em [Keep a Changelog](https://keepachangelog.com/pt-BR/1.1.0/) e
versionamento conforme [SemVer](https://semver.org/lang/pt-BR/).

## [Unreleased]

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
