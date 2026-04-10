# /alta-partidos-sportspress

Genera una query SQL completa para dar de alta partidos masivamente en SportsPress (WordPress) a partir de un CSV de fixtures.

## Uso

Proporcionar el CSV con los partidos a cargar. El asistente generará el SQL listo para ejecutar en phpMyAdmin o MySQL CLI.

---

## Proceso completo

### Paso 1 — Leer el CSV de input

El CSV de partidos debe tener al menos estas columnas:
- `Fecha` (DD/MM/YYYY o similar)
- `Hora`
- `Equipo Local`
- `Equipo Visitante`
- `Liga` (nombre de la liga)
- `Cancha` (nombre de la cancha)
- `Temporada` (nombre de la temporada)
- `Mesa` (nombre del equipo que oficia de mesa controladora)

### Paso 2 — Obtener IDs de referencia

Todos los archivos de referencia están en `wordpress_files/`:

| Archivo | Columnas | Uso |
|---------|----------|-----|
| `BD - Equipos.csv` | `ID, equipo` | Obtener `post_id` de cada equipo |
| `BD - Ligas.csv` | `term_id, term_taxonomy_id, liga` | Obtener `term_taxonomy_id` de la liga |
| `BD - Temporadas.csv` | `term_id, term_taxonomy_id, temporada` | Obtener `term_taxonomy_id` de la temporada |
| `BD - Canchas.csv` | `term_id, term_taxonomy_id, cancha` | Obtener `term_taxonomy_id` de la cancha |

**CRÍTICO**: Usar siempre `term_taxonomy_id` (NO `term_id`) en `wp_term_relationships`.

Temporada 2026: `term_id=359, term_taxonomy_id=356`

### Paso 3 — Generar el SQL

Por cada partido, generar este bloque exacto:

```sql
-- ============================================================
-- PARTIDO N: EQUIPO LOCAL vs EQUIPO VISITANTE — DD/MM/YYYY HH:MM
-- ============================================================
INSERT INTO wp_posts (
    post_author, post_date, post_date_gmt,
    post_title, post_status, post_type, post_name,
    comment_status, ping_status,
    post_content, post_excerpt, to_ping, pinged, post_content_filtered
) VALUES (
    1,
    'YYYY-MM-DD HH:MM:SS',
    'YYYY-MM-DD HH:MM:SS',
    'EQUIPO LOCAL vs EQUIPO VISITANTE',
    'future',
    'sp_event',
    'equipo-local-vs-equipo-visitante-dd-mm-yyyy',
    'closed', 'closed',
    '', '', '', '', ''
);
SET @id = LAST_INSERT_ID();

INSERT INTO wp_postmeta (post_id, meta_key, meta_value) VALUES
(@id, 'sp_format',         'league'),
(@id, 'sp_mode',           'team'),
(@id, 'sp_status',         'ok'),
(@id, 'sp_day',            ''),
(@id, 'sp_minutes',        ''),
(@id, 'sp_video',          ''),
(@id, 'sp_order',          'a:0:{}'),
(@id, 'sp_players',        'a:0:{}'),
(@id, 'sp_result_columns', 'a:0:{}'),
(@id, 'sp_results',        'a:0:{}'),
(@id, 'sp_specs',          'a:1:{s:4:"mesa";s:N:"NOMBRE_EQUIPO_MESA";}'),  -- N = CHAR_LENGTH(nombre)
(@id, 'sp_stars',          'a:0:{}'),
(@id, 'sp_timeline',       'a:0:{}'),
(@id, 'sp_player',         '0'),
(@id, 'sp_player',         '0'),
(@id, 'sp_staff',          '0'),
(@id, 'sp_staff',          '0'),
(@id, 'sp_team',           'ID_LOCAL'),
(@id, 'sp_team',           'ID_VISITANTE');

INSERT INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) VALUES
(@id, LIGA_TTID,      0),
(@id, TEMPORADA_TTID, 0),
(@id, CANCHA_TTID,    0);

UPDATE wp_term_taxonomy SET count = count + 1
WHERE term_taxonomy_id IN (LIGA_TTID, TEMPORADA_TTID, CANCHA_TTID);
```

---

## Errores críticos a evitar

### sp_team — SIEMPRE filas separadas

```sql
-- ✅ CORRECTO: dos filas separadas con el ID plano
(@id, 'sp_team', '12345'),
(@id, 'sp_team', '67890');

-- ❌ INCORRECTO: array serializado en una sola fila
(@id, 'sp_team', 'a:2:{i:0;s:5:"12345";i:1;s:5:"67890";}');
-- → Causa "Uncaught Error: Illegal offset type" en SportsPress
```

### sp_results — SIEMPRE vacío para partidos no disputados

```sql
-- ✅ CORRECTO
(@id, 'sp_results', 'a:0:{}');

-- ❌ INCORRECTO: poner IDs de equipos como keys
(@id, 'sp_results', 'a:2:{s:5:"12345";a:0:{}s:5:"67890";a:0:{}}');
```

### sp_specs — campo Mesa (equipo controlador)

`sp_specs` almacena un array PHP serializado. Para el campo "Mesa", el valor es el **nombre del equipo** (no el ID), y N es `CHAR_LENGTH(nombre)`:

```sql
-- Ejemplo: Mesa = "SAN MARINO" (10 caracteres)
(@id, 'sp_specs', 'a:1:{s:4:"mesa";s:10:"SAN MARINO";}');

-- Ejemplo: Mesa = "ALEMANIA" (8 caracteres)
(@id, 'sp_specs', 'a:1:{s:4:"mesa";s:8:"ALEMANIA";}');
```

Fórmula: `CONCAT('a:1:{s:4:"mesa";s:', CHAR_LENGTH(nombre), ':"', nombre, '";}')` — se puede calcular en MySQL o Python al generar el SQL.

### Campos obligatorios — los 13 meta fields base + sp_player×2 + sp_staff×2 + sp_team×2

Nunca omitir ninguno de los 19 registros de postmeta. Omitir cualquiera causa errores en el editor de WordPress.

### NO incluir `sp_home`

El campo `sp_home` no existe en partidos reales de SportsPress. No agregarlo.

---

## Verificación post-carga

Después de ejecutar el SQL:

1. Abrir el partido en el editor de WordPress → verificar que los equipos aparecen
2. Verificar que el partido aparece en el calendario de SportsPress
3. Si los jugadores no aparecen en el editor del partido:

```sql
-- Verificar listas con sp_number en '0' (debe estar vacío '')
SELECT pm.post_id, p.post_title, pm.meta_value
FROM wp_postmeta pm
JOIN wp_posts p ON p.ID = pm.post_id
WHERE pm.meta_key = 'sp_number'
  AND pm.meta_value = '0'
  AND p.post_type = 'sp_list';

-- Fix: setear a vacío
UPDATE wp_postmeta SET meta_value = ''
WHERE meta_key = 'sp_number' AND meta_value = '0'
AND post_id IN (SELECT ID FROM wp_posts WHERE post_type = 'sp_list');
```

4. Verificar que las listas (`sp_list`) de cada equipo tienen asociada la temporada correcta en `wp_term_relationships`.

---

## Diagnóstico de errores comunes

| Error en WordPress | Causa probable | Fix |
|-------------------|---------------|-----|
| "Illegal offset type" en sp-core-functions.php:439 | `sp_team` guardado como array serializado | Usar filas separadas |
| Clubes no aparecen en editor del partido | Faltan meta fields obligatorios | Verificar los 19 campos |
| Jugadores no aparecen al seleccionar liga | Opción "Filter by league" activa, players sin sp_league | `UPDATE wp_options SET option_value='no' WHERE option_name='sportspress_event_filter_teams_by_league'` |
| Jugadores no aparecen sin liga seleccionada | `sp_number='0'` en sp_list ó lista sin temporada | Ver verificación paso 3/4 |
| Partido no aparece en calendario | Falta `wp_term_relationships` para temporada/liga | Verificar INSERT + UPDATE count |

---

## Referencia: cómo obtener term_taxonomy_id de una liga/temporada/cancha

```sql
-- Ligas disponibles
SELECT t.term_id, tt.term_taxonomy_id, t.name
FROM wp_terms t
JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id
WHERE tt.taxonomy = 'sp_league'
ORDER BY t.name;

-- Temporadas disponibles
SELECT t.term_id, tt.term_taxonomy_id, t.name
FROM wp_terms t
JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id
WHERE tt.taxonomy = 'sp_season'
ORDER BY t.name;

-- Canchas/venues disponibles
SELECT t.term_id, tt.term_taxonomy_id, t.name
FROM wp_terms t
JOIN wp_term_taxonomy tt ON tt.term_id = t.term_id
WHERE tt.taxonomy = 'sp_venue'
ORDER BY t.name;
```
