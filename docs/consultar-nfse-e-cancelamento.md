# Como consultar uma NFS-e e verificar se ela está cancelada

Guia prático — consolida `consultar()`, `verificarCancelamento()`,
`listarEventos()` e a geração da DANFSe com a tarja "CANCELADA". A
armadilha central deste fluxo (e o motivo de existir um guia dedicado):
**cancelamento é um evento, não muda o status da NFS-e original.**

## 1. Consultar o status "básico" da NFS-e

```php
$resp = $nfse->consultar($chaveAcesso); // SefinResposta

$resp->cStat;       // 100 = autorizada — fica 100 pra sempre, mesmo cancelada
$resp->numeroNfse;
$resp->xmlRetorno;  // XML autorizado bruto
```

`ValidationException` se `$chaveAcesso` não tiver 50 dígitos.

Variações:

```php
$nfse->consultarDps(string $chaveAcesso): SefinResposta       // status do DPS
$nfse->consultarEventos(string $chaveAcesso, ?string $tipoEvento = null, ?int $nSequencial = null): SefinResposta
```

## 2. Por que `$resp->cancelada()` quase sempre mente

`SefinResposta::cancelada()` verifica `cStat ∈ {101, 102, 135, 155}`. O
problema: **cancelamento no Sistema Nacional de NFS-e é um evento
vinculado** (`e101101`), não uma alteração no registro da NFS-e emitida.
`consultar()`/`baixarXml()` continuam devolvendo `cStat=100` **para
sempre**, mesmo depois de cancelada. `$resp->cancelada()` só faria
sentido pra ler `cStat` de uma consulta de EVENTO específica
(`consultarEventos()` com o `tipoEvento` certo) — não pra decidir se a
NFS-e como um todo está cancelada.

**Não use `consultar($chave)->cancelada()` pra essa decisão.**

## 3. A forma correta: `verificarCancelamento()`

```php
$cancelada = $nfse->verificarCancelamento($chaveAcesso): bool;
```

Faz uma chamada de rede real — consulta `listarEventos($chaveAcesso)` e
procura um evento cujo `TipoEvento` contenha `CANCELAMENTO` **ou**
`SUBSTITUICAO` (`DownloadService::verificarCancelamentoNfse()`). Não é
um getter barato — não chame em loop apertado.

`estaCancelada()` é um alias `@deprecated` do mesmo método (nome antigo
sugeria getter barato demais).

Pra auditoria completa (todos os eventos, não só cancelamento):

```php
$eventos = $nfse->listarEventos($chaveAcesso); // array cru do ADN
foreach ($eventos as $ev) {
    echo "{$ev['TipoEvento']} em {$ev['DataHoraGeracao']}\n";
}
```

## 4. Cuidado: eventual consistency do ADN

Confirmado empiricamente (smoke 01/08/2026, NFS-e #188 homologação):
chamar `verificarCancelamento($chave)` **logo em seguida** de um
`cancelar()` bem-sucedido pode devolver `false` — o evento demora
minutos pra propagar no ADN, mesmo com `cStat=100` confirmado na
resposta do próprio `cancelar()`.

**Não trate esse atraso como bug do SDK nem fique repetindo a consulta
num loop apertado.** Duas estratégias válidas:

- **Confiar no retorno do `cancelar()`** — se `$resp->cStat` veio
  `100`/`135`/`155` (ou `840` idempotente), o cancelamento foi aceito.
  Não precisa reconsultar pra saber se deu certo.
- **Polling com espaçamento** — se seu fluxo depende de confirmar via
  `verificarCancelamento()` (ex: processo assíncrono separado da
  emissão), espere alguns minutos entre tentativas.

## 5. Gerar a DANFSe com a tarja "CANCELADA"

O PDF (local, via `danfseLocal()`) não sabe sozinho que a nota foi
cancelada — o XML autorizado que ele lê tem `cStat=100` de qualquer
jeito. Passe o resultado da checagem de cancelamento via
`DanfseCustomizacao`:

```php
use PhpNfseNacional\Danfse\DanfseCustomizacao;

// Caminho canônico: reconsulta o estado real via eventos
$custom = new DanfseCustomizacao(
    cancelada: $nfse->verificarCancelamento($chaveAcesso),
);
$pdf = $nfse->danfseLocal($xmlAutorizado, $custom);

// Caminho imediato: você acabou de cancelar com sucesso nesta mesma
// requisição — não precisa esperar o ADN propagar pra saber que está
// cancelada.
$respCancelamento = $nfse->cancelar($chaveAcesso, $motivo, $justificativa);
if ($respCancelamento->emitida() || $respCancelamento->eventoIdempotente()) {
    $pdf = $nfse->danfseLocal($xmlAutorizado, new DanfseCustomizacao(cancelada: true));
}
```

`null` (default) usa o que veio do XML (`cStat` 101/102 — na prática,
quase nunca dispara sozinho, pelo motivo do item 2). `true`/`false`
força a marca d'água independente do XML.

### Onde a tarja aparece no PDF

Texto diagonal **"CANCELADA"**, cinza (`DanfseLayout::COR_MARCA_AGUA`,
K35), 50pt, rotação **-45°**, 50% de opacidade, centralizado no meio da
página — cruza visualmente os blocos SERVIÇO PRESTADO / TRIBUTAÇÃO
MUNICIPAL / TRIBUTAÇÃO FEDERAL (item 2.5.1 do Anexo I, NT 008/2026).

**Não confie em `pdftotext` pra checar programaticamente se a tarja
saiu** — por ser texto rotacionado, a extração costuma falhar ou vir
embaralhada. Pra conferência visual, renderize a página como imagem:

```bash
pdftoppm -png -r 100 -f 1 -l 1 danfse.pdf preview
```

## Resumo — qual método usar

| Pergunta | Método |
|---|---|
| "A NFS-e existe e está autorizada?" | `consultar($chave)` |
| "O DPS já foi processado?" | `consultarDps($chave)` |
| "Essa NFS-e tem cancelamento ou substituição vinculado?" | `verificarCancelamento($chave)` |
| "Quais eventos essa NFS-e já teve (auditoria completa)?" | `listarEventos($chave)` |
| "Como faço o cancelamento aparecer no PDF gerado localmente?" | `DanfseCustomizacao(cancelada: ...)` — ver item 5 |

Ver também o aviso em `MANUAL.md`, seção `SefinResposta`.
