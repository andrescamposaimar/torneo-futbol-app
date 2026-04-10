"""
alta_jugadores.py
-----------------
Genera SQL para dar de alta nuevos jugadores en WordPress/SportsPress.

Uso:
    python3 alta_jugadores.py nuevos_jugadores.csv

Formato del CSV de entrada (con encabezado):
    Nombre, Fecha Nac, DNI, Mail, Teléfono, Caracter, Estado, Obra Social, Posición

- Nombre:     "Apellido, Nombre"  (obligatorio)
- Fecha Nac:  yyyy-mm-dd          (opcional)
- DNI:        numérico            (obligatorio)
- Mail:       email               (opcional)
- Teléfono:   numérico            (opcional)
- Caracter:   Padre de alumno / Padre de Ex-alumno / Invitado / etc. (opcional)
- Estado:     Activo / Baja / Lesionado  (default: Activo)
- Obra Social: texto              (opcional)
- Posición:   Arquero / Defensor / Delantero / Mediocampista (opcional)

El script asigna automáticamente la temporada 2026 a cada nuevo jugador.
"""

import csv
import re
import sys
import unicodedata
from datetime import datetime

# ── Configuración ──────────────────────────────────────────────────────────────

POST_AUTHOR   = 11           # Usuario admin de WordPress
POST_STATUS   = 'publish'
SEASON_2026   = 356          # term_taxonomy_id de la temporada 2026

ACF_FIELDS = {
    'dni':               'field_56d07878c3851',
    'mail':              'field_56d078acc3852',
    'telefono':          'field_56d078d2c3853',
    'caracter':          'field_56d08d81c11d4',
    'estado':            'field_56f073e5ece8b',
    'obra_social':       'field_56f07420ece8d',
    'id_entreredes':     'field_56d07901c3855',
}

POSICIONES = {
    'Arquero':        5,
    'Defensor':       8,
    'Delantero':      9,
    'Mediocampista': 12,
}

# ── Helpers ────────────────────────────────────────────────────────────────────

def slugify(text):
    """Convierte "Apellido, Nombre" → "nombre-apellido" para post_name."""
    text = unicodedata.normalize('NFKD', text)
    text = ''.join(c for c in text if not unicodedata.combining(c))
    text = text.lower().strip()
    # Si tiene coma: "campos, andres" → "andres-campos"
    if ',' in text:
        parts = [p.strip() for p in text.split(',', 1)]
        text = parts[1] + '-' + parts[0]
    text = re.sub(r'[^a-z0-9]+', '-', text).strip('-')
    return text

def esc(val):
    if val is None or str(val).strip() == '':
        return 'NULL'
    return "'" + str(val).replace("\\", "\\\\").replace("'", "\\'") + "'"

def fix_date(raw):
    if not raw or not raw.strip():
        return None
    raw = raw.strip()
    try:
        year = int(raw[:4])
        if year < 1900:
            raw = str(year + 1900) + raw[4:]
        elif year > datetime.today().year:
            return None  # fecha futura inválida
        return raw
    except:
        return None

# ── Generador SQL ──────────────────────────────────────────────────────────────

def generate_sql(rows):
    lines = []
    lines.append("-- ============================================================")
    lines.append(f"-- ALTA nuevos jugadores — generado {datetime.now().strftime('%Y-%m-%d %H:%M')}")
    lines.append(f"-- Total: {len(rows)} jugadores")
    lines.append("-- ============================================================")
    lines.append("")
    lines.append("START TRANSACTION;")
    lines.append("")

    for i, row in enumerate(rows, 1):
        nombre    = row.get('Nombre', '').strip()
        fecha_raw = row.get('Fecha Nac', '').strip()
        dni       = row.get('DNI', '').strip()
        mail      = row.get('Mail', '').strip()
        telefono  = row.get('Teléfono', '').strip()
        caracter  = row.get('Caracter', '').strip()
        estado    = row.get('Estado', 'Activo').strip() or 'Activo'
        obra_soc  = row.get('Obra Social', '').strip()
        posicion  = row.get('Posición', '').strip()

        if not nombre or not dni:
            lines.append(f"-- ⚠️  Fila {i} ignorada: Nombre o DNI vacío")
            continue

        slug      = slugify(nombre)
        fecha     = fix_date(fecha_raw)
        post_date = f"{fecha} 00:00:00" if fecha else None
        post_date_gmt = f"{fecha} 03:00:00" if fecha else None

        lines.append(f"-- [{i}] {nombre}")

        # ── wp_posts ──
        lines.append("INSERT INTO wp_posts (")
        lines.append("    post_author, post_date, post_date_gmt, post_content, post_title,")
        lines.append("    post_excerpt, post_status, comment_status, ping_status, post_password,")
        lines.append("    post_name, to_ping, pinged, post_modified, post_modified_gmt,")
        lines.append("    post_content_filtered, post_parent, guid, menu_order, post_type,")
        lines.append("    post_mime_type, comment_count")
        lines.append(") VALUES (")
        lines.append(f"    {POST_AUTHOR},")
        lines.append(f"    {esc(post_date) if post_date else 'NOW()'},")
        lines.append(f"    {esc(post_date_gmt) if post_date_gmt else 'UTC_TIMESTAMP()'},")
        lines.append(f"    '', {esc(nombre)},")
        lines.append(f"    '', {esc(POST_STATUS)}, 'closed', 'closed', '',")
        lines.append(f"    {esc(slug)}, '', '', NOW(), UTC_TIMESTAMP(),")
        lines.append(f"    '', 0, {esc('http://' + slug)}, 0, 'sp_player',")
        lines.append(f"    '', 0")
        lines.append(");")
        lines.append("SET @pid = LAST_INSERT_ID();")

        # ── wp_postmeta — campos SportsPress internos (requeridos para mostrar el jugador) ──
        sp_internal = [
            ('sp_number',     ''),
            ('sp_current_team', '0'),
            ('sp_team',       '0'),
            ('sp_leagues',    'a:0:{}'),
            ('sp_statistics', 'a:0:{}'),
            ('sp_metrics',    'a:0:{}'),
            ('sp_columns',    'a:0:{}'),
            ('_sp_import',    '1'),
        ]
        for meta_key, meta_val in sp_internal:
            lines.append(
                f"INSERT INTO wp_postmeta (post_id, meta_key, meta_value) "
                f"VALUES (@pid, '{meta_key}', '{meta_val}');"
            )

        # ── wp_postmeta — campos custom con valor + referencia ACF ──
        meta_fields = [
            ('dni',         dni),
            ('mail',        mail),
            ('telefono',    telefono),
            ('caracter',    caracter),
            ('estado',      estado),
            ('obra_social', obra_soc),
        ]
        for meta_key, meta_val in meta_fields:
            lines.append(
                f"INSERT INTO wp_postmeta (post_id, meta_key, meta_value) "
                f"VALUES (@pid, '{meta_key}', {esc(meta_val)});"
            )
            if meta_key in ACF_FIELDS:
                lines.append(
                    f"INSERT INTO wp_postmeta (post_id, meta_key, meta_value) "
                    f"VALUES (@pid, '_{meta_key}', '{ACF_FIELDS[meta_key]}');"
                )

        # ── wp_term_relationships — Temporada 2026 ──
        lines.append(
            f"INSERT IGNORE INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) "
            f"VALUES (@pid, {SEASON_2026}, 0);"
        )

        # ── wp_term_relationships — Posición ──
        pos_ttid = POSICIONES.get(posicion)
        if pos_ttid:
            lines.append(
                f"INSERT IGNORE INTO wp_term_relationships (object_id, term_taxonomy_id, term_order) "
                f"VALUES (@pid, {pos_ttid}, 0);"
            )
        elif posicion:
            lines.append(f"-- ⚠️  Posición desconocida '{posicion}' — no asignada")

        lines.append("")

    # Actualizar contadores de taxonomías
    lines.append("-- Actualizar contadores de temporada y posiciones")
    for ttid in [SEASON_2026] + list(POSICIONES.values()):
        lines.append(
            f"UPDATE wp_term_taxonomy SET count = "
            f"(SELECT COUNT(*) FROM wp_term_relationships WHERE term_taxonomy_id = {ttid}) "
            f"WHERE term_taxonomy_id = {ttid};"
        )

    lines.append("")
    lines.append("COMMIT;")
    return '\n'.join(lines)

# ── Main ───────────────────────────────────────────────────────────────────────

if __name__ == '__main__':
    if len(sys.argv) < 2:
        print("Uso: python3 alta_jugadores.py <archivo.csv>")
        print("El archivo de salida se guarda como <archivo>_INSERT.sql")
        sys.exit(1)

    input_file = sys.argv[1]
    output_file = input_file.replace('.csv', '_INSERT.sql')

    rows = []
    with open(input_file, encoding='utf-8-sig') as f:
        reader = csv.DictReader(f)
        for row in reader:
            rows.append(row)

    print(f"Jugadores leídos: {len(rows)}")
    sql = generate_sql(rows)

    with open(output_file, 'w', encoding='utf-8') as f:
        f.write(sql)

    print(f"SQL generado: {output_file}")
