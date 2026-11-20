#!/usr/bin/env python3
"""Gera resources/data/indop-nbs.json a partir do repositório oficial da RFB
`ReceitaFederal/calculadora-rtc-regime-regular` (MIT).

Uso:
    git clone --depth 1 https://github.com/ReceitaFederal/calculadora-rtc-regime-regular /tmp/rtc
    python3 tools/gerar-indop-nbs.py /tmp/rtc

Reaplica as migrações Flyway (flyway/sql) num SQLite em memória — o estado final
das tabelas CLASSIF_NBS_INDOP_LC (combinações cTribNac × NBS × cIndOp × cClassTrib
com vigência) e INDICADOR_OPERACAO_IBS_CBS (cIndOp) é exatamente o que a
calculadora oficial usa pra validar a combinação. Rode de novo quando a RFB
publicar uma migração nova (ex.: V0061 troca a vigência em 2026-12-01).
"""
import glob
import json
import re
import sqlite3
import sys
from datetime import date

raiz = sys.argv[1]
db = sqlite3.connect(':memory:')
db.isolation_level = None
arquivos = sorted(glob.glob(f'{raiz}/flyway/sql/criacao/B0001*')) + sorted(
    glob.glob(f'{raiz}/flyway/sql/manutencao/V*.sql'),
    key=lambda f: int(re.search(r'/V(\d+)', f).group(1)),
)
for f in arquivos:
    sql = re.sub(r'(?i)PRAGMA\s+foreign_keys\s*=\s*\w+\s*;', '', open(f, encoding='utf-8').read())
    db.execute('PRAGMA foreign_keys=OFF')
    try:
        db.executescript(sql)
    except Exception as e:  # migrações são re-executáveis; falha = dado novo incompatível
        sys.exit(f'ERRO em {f}: {e}')

versao = max((r[0] for r in db.execute('select VRBD_VERSAO_BASE_DADO from VERSAO_BASE_DADO')),
             key=lambda v: int(re.sub(r'\D', '', v) or 0))

indop = {}
for cd, tipo, local, ini, fim in db.execute(
    'select IOIC_CD, IOIC_TIPO_OPERACAO, IOIC_LOCAL_INCIDENCIA, IOIC_INICIO_VIGENCIA, IOIC_FIM_VIGENCIA '
    'from INDICADOR_OPERACAO_IBS_CBS order by IOIC_CD'
):
    indop[cd] = {'d': tipo, 'l': local, 'i': ini, 'f': fim}

def ctrib(lc116: str) -> str:
    """'1.01' -> '0101' (prefixo do cTribNac); '99.03.01' -> '990301' (exato)."""
    return ''.join(f'{int(p):02d}' for p in lc116.split('.'))

nbs = {}
q = """select c.CNIL_LSLC_CD, c.CNIL_NBS_CD, io.IOIC_CD, ct.CLTR_CD,
              c.CNIL_INICIO_VIGENCIA, c.CNIL_FIM_VIGENCIA, c.CNIL_IN_PS_ONEROSA, c.CNIL_IN_ADQ_EXTERIOR
       from CLASSIF_NBS_INDOP_LC c
       join INDICADOR_OPERACAO_IBS_CBS io on io.IOIC_ID = c.CNIL_IOIC_ID
       join CLASSIFICACAO_TRIBUTARIA ct on ct.CLTR_ID = c.CNIL_CLTR_ID
       order by c.CNIL_NBS_CD, c.CNIL_LSLC_CD, io.IOIC_CD, ct.CLTR_CD, c.CNIL_INICIO_VIGENCIA"""
for lc, n, ind, clt, ini, fim, ps, adq in db.execute(q):
    nbs.setdefault(n, []).append([ctrib(lc), ind, clt, ini, fim, int(ps), int(adq)])

# cClassTrib aplicáveis à NFS-e (TIPO_DFE_CLASSIFICACAO × TIPO_DFE 'NFS-e'), com vigência.
# O SEFIN rejeita (cStat 958) cClassTrib fora desta lista mesmo que apareça em
# CLASSIF_NBS_INDOP_LC — confirmado em homologação (campanha de 20/11/2026).
cltr_nfse = {}
for cd, ini, fim in db.execute("""select ct.CLTR_CD, td.TDCL_INICIO_VIGENCIA, td.TDCL_FIM_VIGENCIA
    from TIPO_DFE_CLASSIFICACAO td
    join CLASSIFICACAO_TRIBUTARIA ct on ct.CLTR_ID = td.TDCL_CLTR_ID
    join TIPO_DFE t on t.TPDF_ID = td.TDCL_TPDF_ID
    where t.TPDF_SIGLA = 'NFS-e' order by ct.CLTR_CD, td.TDCL_INICIO_VIGENCIA"""):
    cltr_nfse.setdefault(cd, []).append([ini, fim])

saida = {
    'fonte': 'ReceitaFederal/calculadora-rtc-regime-regular (MIT) — flyway/sql',
    'versaoBase': versao,
    'geradoEm': date.today().isoformat(),
    'colunas': ['cTribNac(prefixo de 4 ou 6 dígitos)', 'cIndOp', 'cClassTrib', 'inicio', 'fim', 'psOnerosa', 'adqExterior'],
    'indop': indop,
    'classTribNfse': cltr_nfse,
    'nbs': nbs,
}
destino = 'resources/data/indop-nbs.json'
with open(destino, 'w', encoding='utf-8') as fh:
    json.dump(saida, fh, ensure_ascii=False, separators=(',', ':'))
print(f'{destino}: base {versao}, {len(indop)} cIndOp, {len(nbs)} NBS, {len(cltr_nfse)} cClassTrib NFS-e, {sum(map(len, nbs.values()))} combinações')
