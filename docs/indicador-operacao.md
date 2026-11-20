# Tabela NBS → cIndOp (indicador de operação IBS/CBS)

`Support\IndicadorOperacao` consulta a tabela oficial que liga
**cTribNac × NBS × cIndOp × cClassTrib** com vigência — a mesma que a
calculadora oficial da RFB (`calculadora-rtc-regime-regular`) usa em
`validarCombinacaoExiste`. A lib **não** impõe essa tabela no `DpsBuilder`
(escopo = sintaxe; quem decide é o SEFIN): ela serve pra escolher e conferir
`Servico::$cIndOp` / `Valores::$cClassTrib` antes de emitir.

```php
$data = new DateTimeImmutable('2026-12-15');          // competência da DPS
IndicadorOperacao::indicadores('113040000', $data, '210101');
// ['030101', '030102', '100301']  → ambíguo, decide o caso concreto
IndicadorOperacao::sugerido('113040000', '2026-11-20', '210101');  // '100301'
IndicadorOperacao::valida('113040000', '100301', '000001', '210101', $data); // true
IndicadorOperacao::descricao('030101');  // ['descricao' => …, 'local' => …]
```

## A tabela muda em 2026-12-01

Combinações de 2026 valem até 2026-11-30 (1.677); uma tabela nova vale a partir
de 2026-12-01 (1.603, 731 NBS). Sempre passe a data (competência), nunca "hoje".

## Exemplo: serviço notarial e de registro (NBS 113040000 / cTribNac 210101)

| Período | cIndOp admitidos |
|---|---|
| até 2026-11-30 | `100301` (único) |
| a partir de 2026-12-01 | `030101`/`030102` (serviço fruído **presencialmente** → local da prestação) ou `100301` (**não presencial** → endereço do tomador) |

O Anexo VII (nota do indicador) cita o Decreto 12.955/2026 e a Resolução
CGIBS 6/2026, art. 12, §11. É a escolha que define **pra qual ente vai o IBS**,
logo o valor correto do tributo.

## Dados e atualização

`resources/data/indop-nbs.json` é gerado por `tools/gerar-indop-nbs.py`
(reaplica as migrações Flyway do repositório da RFB, licença MIT, num SQLite
em memória). Procedência em `IndicadorOperacao::baseDados()`. Quando a RFB
publicar migração nova (`flyway/sql/manutencao/V00NN__*cindop*`/`*classif_nbs*`),
rode:

```bash
git clone --depth 1 https://github.com/ReceitaFederal/calculadora-rtc-regime-regular /tmp/rtc
python3 tools/gerar-indop-nbs.py /tmp/rtc
```
