# Gerador oficial de DANFSe da Receita Federal (comparação)

Em out/2026 a RFB publicou o repositório `ReceitaFederal/calculadora-rtc-gerador-danfse`
(Go, release 1.0.0 de 22/10/2026) — a implementação de **referência** da NT 008 v1.02
(o PDF em `docs/nts/` é byte a byte o mesmo da NT 008 versionada aqui). Existem
também `calculadora-rtc-regime-regular` e `calculadora-rtc-simples-nacional`
(calculadoras do RTC, fora do escopo do DANFSe).

## Como comparar (diff real, não leitura de código)

```bash
git clone --depth 1 https://github.com/ReceitaFederal/calculadora-rtc-gerador-danfse
cd calculadora-rtc-gerador-danfse && GOTOOLCHAIN=auto go build -o danfse-bin ./cmd/danfse
SERVER_PORT=18080 ./danfse-bin &
curl -s -X POST -H 'Content-Type: application/xml' --data-binary @nfse.xml \
  'localhost:18080/api/danfse?stc=0' -o ref.pdf      # stc: 0 válida, 1 substituída, 2 cancelada
pdftotext -layout ref.pdf ref.txt   # idem pro nosso PDF e `diff`
```

Cuidado: é uma implementação **nova**; não é pixel-idêntica ao DANFSe do portal gov.br
que usamos como verdade empírica (ver CLAUDE.md). Em conflito entre os dois,
**decide a NT + o que o portal real mostra**, não o gerador novo.

## Medidas, fontes e cores — conferido

| Item | NT 008 | Gerador RFB | Nosso SDK |
|---|---|---|---|
| Grade de colunas (cm) | 0,30 / 5,41 / 10,51 / 15,62 / 20,70 | idem | idem |
| Fontes | rótulo 6pt, valor 7pt, título de bloco 7pt, cabeçalho 9pt/8pt/6pt | idem | idem (`TAM_*`) |
| Sombreamento | cinza 5% | 0,949 (= 242) | `[242,242,242]` |
| Tarja homologação | vermelho M100/Y100 | `#FF0000` | `[255,0,0]` |
| Marca d'água | ≥ 50pt, K35, diagonal | 60pt, 45°, opac. 0,35 | 100pt (pedido do mantenedor), 165, 45° |
| Fonte | Arial / MS Sans Serif | Helvetica | Helvetica (TCPDF) |

## Correções aplicadas a partir da comparação

1. **"VALOR LÍQUIDO + IBS/CBS" lê `vTotNF`** (antes: `vLiq + vIBSTot + vCBS`).
   NT tabela 2.4.5 + anotação do XSD (`vTotNF = vLiq` em 2026; `vLiq + CBS + IBS`
   a partir de 2027) + gerador RFB concordam. Sem `<IBSCBS>` → `R$ 0,00`
   (bate com o portal). Somar por conta própria mostrava valor a mais em 2026.
2. **Linha "Totais Aproximados dos Tributos (Lei 12.741)"** agora segue a NT
   nota 10: `Federais: …; Estaduais: …; Municipais: …;` — monetário (`vTotTrib*`)
   se algum > 0, senão percentual (`pTotTrib*`), senão zeros. Antes inventávamos R$
   a partir da tributação apurada, sem os dois-pontos.
3. **"Exclusões e Reduções da BC"** soma também `vCalcDR` (leiaute novo:
   `vCalcAjusteBCISSQN`) e `vCalcReeRepRes`. Fórmula completa:
   `vDescIncond + vISSQN + vCalcDR + vCalcReeRepRes + vPIS + vCOFINS`.
   (Confirma a reversão da v0.34.0: ISSQN entra mesmo sem `gIBSCBS`.)
4. **"Red. Alíquota IBS / CBS"** mostra os 3 valores `UF / Mun / CBS` (NT: `%/%/%`).
5. **"Local da Prestação / UF / País"** resolve a UF pelo `cLocPrestacao` e o país
   por `cPaisPrestacao` (antes: UF ausente e `BR` fixo).
6. **Parser rejeita `DOCTYPE`/`ENTITY`** (hardening do ADR-006 do gerador RFB).

## Divergências conscientes (NÃO alinhadas ao gerador RFB)

Onde o gerador novo difere do portal real, mantivemos o portal:

- `Ambiente Gerador`/`Tipo de Ambiente`: dígito cru (RFB traduz "Ambiente de Homologação").
- Código IBGE `UF.MMMMM` (RFB imprime só dígitos).
- `Simples Nacional`: texto completo do domínio (RFB: "1 - Não Optante").
- "NFS-e" nunca vira "NFS-E" em rótulos (RFB usa `NFS-E` em alguns).
- Situação: `NFS-e Gerada` (RFB: `NFS-e Regular`; mapeia 104/105 que o leiaute V1.00.02 não tem).
- `Alíquota Aplicada`/percentuais com ` %` (RFB sem espaço/símbolo na alíquota).
- Canhoto preenchido automaticamente (RFB deixa em branco; bloco é opcional na NT).
- Município/UF do emitente vêm do XML (RFB resolve pelo IBGE em tabela própria).

## Pendências investigadas (v0.47.0)

| Item | Resultado |
|---|---|
| `tpRetPisCofins=1` (`vRetCSLL + vPis + vCofins`, débitos 0,00) | **Já idêntico** ao gerador RFB — nada a fazer. |
| Destinatário sem `<dest>` no DPS | **Corrigido.** NT nota 2: sem dados → "DESTINATÁRIO DA OPERAÇÃO NÃO IDENTIFICADO NA NFS-e". "É o próprio tomador" (nota 3) só quando `<dest>` tem o mesmo documento do tomador. Antes assumíamos o tomador como destinatário. **Sem DANFSe real de portal pra confirmar** — se o portal divergir, reverter em `DanfseXmlParser::extrairDestinatario`. |
| Truncamento | **Implementado** (`DanfseLayout::truncarComReticencias`): descrição do serviço 1300 (corta em 1297 + `...`), descrição do código de tributação 170 (167), informações complementares 2000 (1997) **preservando** a linha de totais da Lei 12.741. |
| `xTribMun` | **Bug achado:** o parser lia `cServ/xTribMun` (inexistente); o campo vive em `infNFSe/xTribMun`. NT: usar `xTribMun` se houver, senão `xTribNac`. |
| `21.01.01 / -` (sem `cTribMun`) | **Mantido.** NT nota 12 manda traço em campo sem informação; formato `nn.nn.nn / nnn`. O gerador RFB que omite a barra diverge da nota 12. |
| Linha Regime Especial/Imunidade/Suspensão | **Mantido suprimida** quando todos vazios — a NT nota 5 permite. O gerador RFB imprime sempre ("Nenhum"). |

## As calculadoras RTC (`regime-regular`, `simples-nacional`)

Java 25 / Spring, MIT, **sem host público** (só `localhost`, ou o perfil `offline`
deles). Não servem como dependência de runtime do SDK PHP. Valor real:

- **Dados oficiais como oráculo** (`flyway/sql/manutencao/`): `V0062` = nova tabela
  cClassTrib de 01/10/2026 (9 códigos novos: 000006, 200055/56, 400003/04,
  550026–29) — nossa validação RN 627 é estrutural (prefixo = CST), então esses
  códigos já passam; `V0072` = atualização de NBS; `V0024` = cIndOp.
- **`GET /calculadora/nfse/indicador-operacao`** deriva o `cIndOp` a partir do NBS
  + data. Nosso `Servico::$cIndOp` tem default `'100301'` pra qualquer serviço;
  o certo depende do NBS (Anexo VII, já em `docs/anexos/`). Candidato a feature
  futura: tabela NBS→cIndOp local, sem chamar a API.
- `POST /xml/validate` valida XML contra o XSD — redundante com nosso
  `docs/schemas/` + validação local.

**Decisão:** não integrar; usar como fonte de dados/oráculo (ex.: conferir
`ListaNbs` e cIndOp contra o `flyway/`) quando houver atualização.
