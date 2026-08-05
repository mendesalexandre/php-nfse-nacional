# CNPJ alfanumérico

A partir de julho/2026 a Receita Federal passou a emitir CNPJs no novo
formato alfanumérico: os 12 primeiros caracteres (raiz + ordem) podem
conter letras maiúsculas além de dígitos; os 2 dígitos verificadores (DV)
finais continuam sempre numéricos. O primeiro CNPJ alfanumérico emitido de
fato foi do Banco do Brasil: `00.000.000/E08G-12`.

Formato: `AA.AAA.AAA/AAAA-DD` — mesma máscara do CNPJ clássico, só que os
12 primeiros caracteres (antes dos 2 últimos) aceitam `[A-Z0-9]` em vez de
só `[0-9]`.

## O que mudou no SDK

`PhpNfseNacional\Support\Documento` (`src/Support/Documento.php`) é o
único ponto central de validação/formatação de CPF/CNPJ no SDK — CNPJ de
`Prestador`, `Tomador`, `Intermediario` e o CNPJ extraído do certificado
digital (`Certificate`) passam todos por ele.

- **`limpar()`** — antes usava `preg_replace('/\D/', ...)` (remove tudo
  que não é dígito), o que **descartava as letras** do CNPJ alfanumérico
  silenciosamente. Agora remove só os caracteres de máscara (`.`, `/`,
  `-`, espaços) e normaliza pra maiúsculas. CPF continua sempre numérico
  (a Receita não estendeu o formato alfanumérico pra CPF).
- **`isCNPJ()`** (`ehCnpj()` até v0.27.0, mantido como alias `@deprecated`)
  — checa `^[A-Z0-9]{12}\d{2}$` (14 caracteres: 12 alfanuméricos + 2
  dígitos) em vez de só tamanho. Continua sendo uma checagem de
  **formato**, não de dígito verificador — consistente com o resto da
  lib, que valida sintaxe e deixa a regra fiscal pro SEFIN. `isCPF()`
  (`ehCpf()` deprecated) segue exigindo só dígitos.
- **`formatar()`** — mesma máscara `00.000.000/0000-00`, agora com
  wildcard de caractere em vez de `\d` nos grupos que podem ter letra.
- **`calcularDvCnpj(string $raiz): string`** (novo) — calcula os 2 DVs a
  partir da raiz+ordem (12 caracteres). Útil pra gerar/conferir CNPJs de
  teste.
- **`validarDvCnpj(string $cnpj): bool`** (novo) — valida um CNPJ
  completo (14 caracteres) contra o DV calculado. Opt-in — não é chamado
  automaticamente por `isCNPJ()`.

`Certificate::fromPfxContent()` também foi ajustado: a extração de CNPJ
do `CN` do certificado (formato `RAZÃO SOCIAL:CNPJ`) e da extensão SAN
(OID `2.16.76.1.3.3`, padrão ICP-Brasil DOC-ICP-04) agora aceitam letras
nos 12 primeiros caracteres. A extração via SAN é uma generalização
**não validada empiricamente** contra um certificado real com CNPJ
alfanumérico — o ITI ainda não publicou revisão confirmada do DOC-ICP-04
pra esse caso; validar em homologação assim que houver um certificado
desses disponível.

`DpsBuilder`/`EventoBuilder` não precisaram de mudança — gravam o CNPJ
como texto puro no XML (`<CNPJ>{valor}</CNPJ>`), já normalizado pelo DTO.
**Ressalva**: isso depende do XSD do SEFIN aceitar caracteres
alfanuméricos no elemento `<CNPJ>` (`TCnpj` ou equivalente) — se o schema
oficial ainda estiver restrito a `\d{14}` no momento do envio, a nota
será rejeitada independente do que o SDK faz. Não validado em
homologação até a publicação deste documento.

## Algoritmo do dígito verificador

Fonte: SERPRO, *"Cálculo dos dígitos verificadores de CNPJ
alfanumérico"*. Módulo 11, igual ao CNPJ clássico — a diferença é o
"valor" atribuído a cada caractere (dígitos e letras) e o alfabeto
aceito nas 12 primeiras posições.

### Valor de cada caractere

`valor = código ASCII do caractere - 48` (equivalente a olhar a tabela
oficial: `0`-`9` → 0-9, `A`-`Z` → 17-42).

### 1º dígito verificador

1. Atribuir peso a cada um dos 12 caracteres da raiz+ordem, de 2 a 9,
   **da direita pra esquerda**, recomeçando em 2 após o 8º peso.
2. Multiplicar valor × peso de cada posição e somar tudo.
3. `resto = soma % 11`.
4. Se `resto < 2` → DV = `0`. Senão → DV = `11 - resto`.

### 2º dígito verificador

Repetir o mesmo processo, mas sobre os 12 caracteres **+ o 1º DV já
calculado** (13 caracteres), com os pesos recalculados da mesma forma
(2 a 9, direita pra esquerda) sobre essa string maior.

### Exemplo oficial (documento SERPRO)

```
CNPJ  1  2  A  B  C  3  4  5  0  1  D  E
Valor 1  2 17 18 19  3  4  5  0  1 20 21
Peso  5  4  3  2  9  8  7  6  5  4  3  2
```

Soma = 459 → `459 % 11 = 8` → `11 - 8 = 3` (1º DV)

```
CNPJ  1  2  A  B  C  3  4  5  0  1  D  E  3
Peso  6  5  4  3  2  9  8  7  6  5  4  3  2
```

Soma = 424 → `424 % 11 = 6` → `11 - 6 = 5` (2º DV)

**Resultado: `12.ABC.345/01DE-35`**

### Exemplo real (Banco do Brasil)

Raiz `00000000E08G` → DV `12` → `00.000.000/E08G-12`.

Ambos os exemplos estão cobertos em
`tests/Unit/Support/DocumentoTest.php` (`test_calcularDvCnpj_*`,
`test_validarDvCnpj`), junto com um caso de CNPJ clássico só-numérico
(`11.222.333/0001-81`) confirmando que o mesmo algoritmo generaliza o DV
clássico — não é um cálculo novo, é o mesmo módulo 11 com um alfabeto
maior.

## Implementação de referência usada pra validar

O algoritmo foi conferido contra duas implementações de referência
independentes (Python e TypeScript/JavaScript) publicadas junto com a
documentação do SERPRO — ambas batem exatamente com os exemplos acima e
com o CNPJ real do Banco do Brasil.
