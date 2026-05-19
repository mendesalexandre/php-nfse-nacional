# Política de Segurança

## Versões suportadas

O SDK ainda está pré-1.0. Apenas a **última minor publicada** recebe correções de segurança. Versões anteriores podem ser atualizadas via upgrade pra a minor mais recente.

| Versão | Suportada |
| ------ | --------- |
| 0.16.x | sim       |
| < 0.16 | não       |

Pós-1.0 (planejado), a política de suporte será revisada pra cobrir a major atual + a anterior.

## Reportando uma vulnerabilidade

**Não abra issues públicas pra vulnerabilidades de segurança.** Issues no GitHub são indexadas por crawlers em minutos — qualquer divulgação prematura aumenta o risco pra todos os consumidores do SDK enquanto não há patch.

### Canais

1. **GitHub Security Advisories (preferido).** No repositório, vá em **Security → Report a vulnerability**. Cria advisory privado entre você e os mantenedores, com workflow integrado pra publicar o aviso (CVE inclusive) após o fix.

2. **Email direto.** `alexandre.teixeira.mendes@hotmail.com` — assunto começando com `[php-nfse-nacional security]`. Use se preferir não criar conta no GitHub ou se o canal privado não funcionar.

### Informações úteis no reporte

- Versão afetada (`composer show mendesalexandre/php-nfse-nacional`)
- Componente (Certificate / Dps / Sefin / Danfse / Services / etc.)
- Vetor de ataque + PoC mínimo (se possível)
- Impacto estimado (RCE? leak de cert? bypass de assinatura?)
- Sugestão de mitigação (opcional)

### O que esperar

| Prazo | Ação |
| ----- | ---- |
| 48h   | Confirmação de recebimento |
| 7 dias | Triagem inicial + classificação de severidade |
| 30 dias | Patch publicado ou plano com data se o fix for complexo |

Vulnerabilidades críticas (RCE, comprometimento de chave privada, forjamento de assinatura DPS) são priorizadas e podem ter release fora do ciclo normal.

## Hall of Fame

Reportes responsáveis são creditados no `CHANGELOG.md` da versão que contém o fix, salvo solicitação contrária.

## Escopo

Este SDK depende de:

- **Certificado A1 .pfx + chave privada** — gerenciamento da chave é responsabilidade do consumidor. Vulnerabilidades em como o SDK manipula a chave em memória estão no escopo; problemas em como você armazena o `.pfx` em disco/cofre estão fora.
- **OpenSSL 3.x com legacy provider habilitado** — exigido pelo SefinNacional (RSA-SHA1). Problemas no `Signer::habilitarLegacyProviderRuntime()` estão no escopo; vulnerabilidades do OpenSSL upstream não.
- **Endpoints SefinNacional/ADN** — bugs na construção do XML, parsing de resposta ou validação de leiaute estão no escopo. Problemas na infraestrutura SEFIN estão fora.

Vulnerabilidades em dependências (`guzzlehttp/guzzle`, `tecnickcom/tcpdf`) devem ser reportadas upstream — você pode também avisar aqui se quiser bump coordenado.
