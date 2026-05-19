# Contribuindo com `php-nfse-nacional`

Obrigado por considerar contribuir. Esse documento descreve o fluxo esperado pra abrir issues, mandar PRs e o estilo do projeto.

## Stack

- **PHP 8.1+** (CI matrix: 8.1, 8.2, 8.3, 8.4, 8.5)
- **PHPUnit 10** pra testes
- **PHPStan level 8** pra análise estática
- Sem framework — código é framework-agnostic e roda em Laravel, Symfony, Slim ou bootstrap PHP puro

## Antes de mandar PR

1. **Abre issue antes** se a mudança for grande (feature nova, refactor amplo, breaking change). PRs grandes sem discussão prévia podem ser fechados.
2. **Issues pequenas** (bug claro, typo, dep bump) podem ir direto pra PR.
3. **Vulnerabilidades de segurança** seguem fluxo separado — ver `SECURITY.md`.

## Setup local

```bash
git clone https://github.com/mendesalexandre/php-nfse-nacional.git
cd php-nfse-nacional
composer install

# Suite + lint
vendor/bin/phpunit
composer phpstan
```

Ambos têm que passar antes do push. CI roda os dois em todas as versões do PHP.

## Convenções

### Branches & commits

- `feat/<slug>` pra features
- `fix/<slug>` pra bugs
- `chore/<slug>` pra build/docs/infra
- `refactor/<slug>` pra refactor sem mudança de comportamento

Commits seguem [Conventional Commits](https://www.conventionalcommits.org/):

- `feat: ...`, `fix: ...`, `chore: ...`, `docs: ...`, `refactor: ...`, `test: ...`
- Breaking change marca com `!`: `feat!: ...` + corpo explicando a migração

Mensagens em **português ou inglês** — projeto é pt-BR predominante pelo domínio fiscal brasileiro, mas inglês é aceito.

### Código

- **DTOs imutáveis com `readonly`** — validação no construtor, agregando erros via `ValidationException`.
- **Nomenclatura PT-BR** nos DTOs/enums (`MotivoCancelamento::ErroEmissao`, `AutorManifestacao::Tomador`) — alinha com o leiaute oficial SefinNacional.
- **Sem comentário óbvio** — só comente o "por quê" quando não é derivável do código (limitação empírica, constraint XSD, bug histórico).
- **Sem mocks no teste de leiaute** — testes que validam a estrutura do XML usam fixture real ou snapshot. Mocks só pra HTTP/I/O.
- **PHPStan level 8 limpo** — sem `@phpstan-ignore` salvo em caso justificado (a justificativa fica em comentário).

### Testes

- PR sem teste só é aceito pra `docs:` ou `chore:` (CI/build/release).
- **Bug fix** = teste de regressão que falha antes do patch e passa depois.
- **Feature** = pelo menos um teste happy-path + um edge-case relevante (validação que rejeita input inválido, fallback, etc.).
- Use as fixtures em `tests/Fixtures/` quando possível; novas fixtures vão pra subdir descritivo.

### Achados empíricos (homologação SEFIN)

Esse SDK acumula achados de comportamento da SEFIN que **não estão no leiaute oficial** ou divergem dele. Quando você confirmar algo novo em homologação:

1. Adiciona uma linha na tabela "Achados empíricos importantes" do `CLAUDE.md` com origem.
2. No PR, descreve o achado + cStat retornado + como você confirmou (NFS-e #N, data, ambiente).
3. Se for um bug fix decorrente de divergência leiaute-vs-SEFIN, adiciona uma entrada em "Bug history".

## CHANGELOG

**Toda PR que muda comportamento da API pública tem que atualizar `CHANGELOG.md`** na seção `[Unreleased]`. Formato segue [Keep a Changelog](https://keepachangelog.com/):

- `### Adicionado` — features novas
- `### Modificado` — mudanças em comportamento existente
- `### Corrigido` — bug fixes
- `### Removido` — APIs deletadas
- `### Segurança` — fixes de vulnerabilidade

Não bumpe versão no `CHANGELOG.md` — release de fato é responsabilidade do mantenedor (ver "Workflow de release" no `CLAUDE.md`).

## Smoke real em homologação

Mudanças que mexem em construção/parse de XML idealmente são validadas em homologação SEFIN antes do merge. Você não precisa ter certificado próprio — pode descrever o teste no PR + fixture esperada, e o mantenedor reproduz com cert dele.

Se você tem cert próprio, use `examples/emitir-homologacao.php` e variantes — nunca rode contra produção em PR.

## Pull request

- Título no padrão Conventional Commits
- Descrição com: contexto, mudança, como testar
- Link pra issue se houver
- CI verde (PHPUnit + PHPStan + matrix de versões)
- Sem `--no-verify`, sem skip de hooks
- Sem força reescrita de histórico já mergeado

Reviewers podem pedir mudanças, rebase pra cima do `main`, ou squash. Squash é o padrão pra manter histórico limpo no `main`.

## Code of Conduct

Seja respeitoso. Discussão técnica é bem-vinda, ataques pessoais não. Mantenedores podem fechar issues/PRs e bloquear contas que violarem.

## Licença

Ao mandar PR, você concorda que sua contribuição é licenciada sob MIT, como o resto do projeto.
