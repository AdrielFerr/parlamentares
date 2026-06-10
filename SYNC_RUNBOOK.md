# Runbook de Sincronização Semanal

Guia operacional para atualizar os dados das fontes legislativas. Frequência recomendada: **1x por semana**.

---

## Fontes e scripts por tipo

### Fontes SAPL (pipeline completo)
Rodar na ordem abaixo. Cada script pode ser executado com `php database/<script>.php [source_key]`.

| Passo | Script | O que faz |
|---|---|---|
| 1 | `sync.php` | Parlamentares, legislaturas, partidos, mandatos |
| 2 | `sync_detalhes.php` | Popula `sapl_cache` com todos os endpoints por parlamentar |
| 3 | `sync_estruturado.php` | Extrai `sapl_cache` → 7 tabelas estruturadas |
| 4 | `sync_fotos_sapl.php` | Baixa fotos dos parlamentares |
| 5 | `sync_comissoes_nomes.php [source]` | Busca nomes completos de comissões |
| 6 | `sync_mandatos_votos.php [source]` | Popula votos_recebidos/coligacao |

**Fontes SAPL ativas:** `alpb`, `cmjp`, `campina`, `bayeux`, `cabedelo`, `santarita`, `camara_federal`, `senado`

Exemplo de execução completa para ALPB:
```bash
php database/sync.php alpb
php database/sync_detalhes.php alpb
php database/sync_estruturado.php alpb
php database/sync_fotos_sapl.php alpb
php database/sync_comissoes_nomes.php alpb
php database/sync_mandatos_votos.php alpb
```

---

### Fontes via scraping/CSV (scripts próprios)

| Script | Fonte | Dados |
|---|---|---|
| `sync_almg.php` | ALMG (MG) | Deputados estaduais via CSV público |
| `sync_alerj.php` | ALERJ (RJ) | Deputados estaduais via scraping do site |
| `sync_materias_alerj.php` | ALERJ (RJ) | Matérias e relatórias via Lotus Notes |
| `sync_comissoes_alerj.php` | ALERJ (RJ) | Comissões via Lotus Notes |
| `sync_votos_alerj.php` | ALERJ (RJ) | Votos 2022 via CSV TSE |
| `sync_governadores.php` | Governadores | Governadores via CSV TSE + fotos Wikidata |
| `sync_prefeitos.php` | Prefeitos | Prefeitos da região metropolitana |
| `sync_emendas_camara.php` | Câmara Federal | Emendas parlamentares federais |
| `sync_camara_csvs.php` | Câmara Federal | Dados extras via CSV da Câmara |

---

### Scripts de dados legislativos ALERJ (Lotus Notes Domino)

Rodar após o `sync_alerj.php` (que popula `parl_parlamentares`).

| Script | O que faz | Tabelas |
|---|---|---|
| `sync_materias_alerj.php [N]` | Matérias e relatórias de todos os 70 dep. ALERJ | `parl_materias`, `parl_relatorias` |
| `sync_comissoes_alerj.php` | Membros de comissões permanentes e temporárias | `parl_comissoes` |
| `sync_votos_alerj.php` | Votos recebidos nas eleições de 2022 (via CSV TSE) | `parl_mandatos.votos_recebidos` |

```bash
php database/sync_materias_alerj.php        # todos os deputados (~10-15 min)
php database/sync_materias_alerj.php 10     # só os 10 primeiros (teste)
php database/sync_comissoes_alerj.php       # comissões (~2-3 min)
php database/sync_votos_alerj.php           # votos (baixa ~12MB do TSE na 1ª vez, usa cache depois)
```

**Fontes Lotus Notes:**
- `scpro2327.nsf` — proposições da 13ª Legislatura (2023-2027)
- `compcom.nsf` — comissões permanentes
- `comtemp.nsf` — comissões temporárias (CPIs, especiais)

**Limitações conhecidas:**
- O servidor Domino bloqueia paginação (`&Start=N` retorna HTTP=0). Cada execução do `sync_comissoes_alerj.php` captura apenas o lote inicial de comissões. Re-executar mensalmente para capturar novas comissões instaladas.
- 7 deputados não possuem perfil no `scpro2327.nsf` (provávelmente sem matérias protocoladas ou suplentes recentemente empossados): Carlinhos BNH, Fred Pacheco, Guilherme Delaroli, Lilian Behring, Renan Jordy, Sarah Poncio, Wellington José.
- Frentes parlamentares: não há endpoint acessível na ALERJ Domino — dado indisponível.

**Slugs especiais** (mapeados em `$SLUG_OVERRIDES` no script):
- Dr. Pedro Ricardo → `pedroricardo` (o "Dr." é omitido no NSF)
- Yuri Moura → `yuri` (NSF usa só o primeiro nome)

**Diagnóstico rápido:**
```sql
SELECT COUNT(*) FROM parl_materias   WHERE source_key = 'alrj';  -- ~220+
SELECT COUNT(*) FROM parl_relatorias WHERE source_key = 'alrj';  -- ~60+
SELECT COUNT(*) FROM parl_comissoes  WHERE source_key = 'alrj';  -- ~80+
```

---

### sync_alerj.php — detalhes e riscos

```bash
# Execução padrão (dados + fotos + biografias)
php database/sync_alerj.php

# Apenas dados, sem baixar fotos
php database/sync_alerj.php --skip-fotos

# Apenas dados, sem buscar biografia nos perfis
php database/sync_alerj.php --skip-bio

# Rebaixa fotos já existentes
php database/sync_alerj.php --force
```

**Tempo estimado:** ~15-30 min (70 deputados × ~250ms de delay entre perfis)

**Riscos conhecidos:**
- O scraper depende da estrutura HTML do site `alerj.rj.gov.br`. Se o site mudar o layout, o parser pode quebrar. O script emite erro claro: `"Nenhum deputado parseado — HTML da ALERJ pode ter mudado"`.
- Biografias usam heurística de parsing — textos muito curtos ou sem parágrafo identificável ficam sem bio (normal, ~10-20% dos casos).
- Fotos retornam o que o site disponibiliza. Deputados sem foto no site ficam sem imagem local.

**Diagnóstico rápido:**
```sql
-- Verifica quantos deputados foram importados
SELECT COUNT(*), MAX(sincronizado_em) FROM parl_parlamentares WHERE source_key = 'alrj';

-- Verifica quantas biografias foram salvas
SELECT COUNT(*) FROM parl_perfil_detalhe WHERE source_key = 'alrj' AND biografia IS NOT NULL;
```

---

## Ordem recomendada para execução semanal completa

```bash
# 1. Fontes SAPL (pesadas — rode à noite)
php database/sync.php
php database/sync_detalhes.php
php database/sync_estruturado.php
php database/sync_fotos_sapl.php

# 2. Scraping / CSV (mais rápido)
php database/sync_almg.php --skip-fotos   # fotos raramente mudam
php database/sync_alerj.php --skip-fotos  # fotos raramente mudam
php database/sync_materias_alerj.php      # matérias ALERJ
php database/sync_comissoes_alerj.php     # comissões ALERJ
php database/sync_governadores.php --skip-fotos
php database/sync_prefeitos.php

# 3. Emendas (só Câmara Federal por ora)
php database/sync_emendas_camara.php
```

---

## Quando re-baixar fotos

Fotos mudam raramente (quando o deputado tira nova foto oficial). Re-baixar todo mês é suficiente:

```bash
php database/sync_alerj.php --force --skip-bio
php database/sync_almg.php --force
```

---

## Adicionando nova fonte de scraping (ex: ALEPE)

1. Criar `database/sync_alepe.php` seguindo o padrão de `sync_alerj.php`
2. Adicionar `'PE'` em `config/estados.php` com `source_key => 'alpe'`
3. Verificar que `alpe` já está em `fontes_legislativas` (está no seed de `migrate.php`)
4. Rodar o script e verificar com a query de diagnóstico acima
