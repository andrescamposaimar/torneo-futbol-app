-- ============================================================
-- Exportar listado completo de jugadores
-- Columnas: ID, Nombre, DNI, Puntaje
-- Compatible MySQL 5.7+ y MySQL 8+
-- ============================================================

SELECT
    p.ID,
    p.post_title AS Nombre,
    MAX( CASE WHEN pm.meta_key = 'dni' THEN pm.meta_value END ) AS DNI,
    MAX( CASE WHEN pm.meta_key = 'sp_metrics'
         -- 1. Toma lo que viene DESPUES de "puntaje";s:N:"  -> "3.5";}
         -- 2. Corta en la primera "  -> queda   3.5";}
         -- 3. Corta en la primera "  -> queda   3.5
         THEN SUBSTRING_INDEX(
                  SUBSTRING_INDEX(
                      SUBSTRING_INDEX( pm.meta_value, '"puntaje";', -1 ),
                  '"', 2 ),
              '"', -1 )
         END ) AS Puntaje

FROM wp_posts p
LEFT JOIN wp_postmeta pm ON pm.post_id = p.ID

WHERE p.post_type   = 'sp_player'
  AND p.post_status = 'publish'

GROUP BY p.ID, p.post_title
ORDER BY p.post_title ASC;
