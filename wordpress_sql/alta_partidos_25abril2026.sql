-- ============================================================
-- ALTA MASIVA DE PARTIDOS — 25 de Abril de 2026
-- Temporada 2026 (term_taxonomy_id = 356)
-- Incluye campo Mesa en sp_specs (PHP serialized)
-- ============================================================

-- PARTIDO 1: ALBANIA (21568) vs NUEVA ZELANDA (15803)
-- Liga: Zona 3 (ttid=359) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: MARRUECOS
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 13:45:00','2026-04-25 13:45:00','ALBANIA vs NUEVA ZELANDA','future','sp_event','albania-vs-nueva-zelanda-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:9:"MARRUECOS";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21568'),(@id,'sp_team','15803');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,20);

-- PARTIDO 2: MARRUECOS (21637) vs NORUEGA (15808)
-- Liga: Zona 1 (ttid=357) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: CABO VERDE
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 15:10:00','2026-04-25 15:10:00','MARRUECOS vs NORUEGA','future','sp_event','marruecos-vs-noruega-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:10:"CABO VERDE";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21637'),(@id,'sp_team','15808');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,20);

-- PARTIDO 3: CABO VERDE (21584) vs CROACIA (15882)
-- Liga: Zona 4 (ttid=360) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: NORUEGA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 16:25:00','2026-04-25 16:25:00','CABO VERDE vs CROACIA','future','sp_event','cabo-verde-vs-croacia-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"NORUEGA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21584'),(@id,'sp_team','15882');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,20);

-- PARTIDO 4: AUSTRALIA (21576) vs JAPON (21623)
-- Liga: Zona 4 (ttid=360) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: POLONIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 13:45:00','2026-04-25 13:45:00','AUSTRALIA vs JAPON','future','sp_event','australia-vs-japon-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"POLONIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21576'),(@id,'sp_team','21623');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,21);

-- PARTIDO 5: POLONIA (15898) vs ESPAÑA (15855)
-- Liga: Zona 5 (ttid=361) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: ESCOCIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 15:10:00','2026-04-25 15:10:00','POLONIA vs ESPAÑA','future','sp_event','polonia-vs-espana-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"ESCOCIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15898'),(@id,'sp_team','15855');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,21);

-- PARTIDO 6: ESCOCIA (15939) vs HUNGRIA (21602)
-- Liga: Zona 5 (ttid=361) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: ESPAÑA
-- Nota: ESPAÑA = 7 bytes en UTF-8 (Ñ ocupa 2 bytes)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 16:25:00','2026-04-25 16:25:00','ESCOCIA vs HUNGRIA','future','sp_event','escocia-vs-hungria-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"ESPAÑA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15939'),(@id,'sp_team','21602');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,21);

-- PARTIDO 7: SAN MARINO (21673) vs COTE DIVOIRE (15796)
-- Liga: Zona 3 (ttid=359) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: SIRIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 13:45:00','2026-04-25 13:45:00','SAN MARINO vs COTE DIVOIRE','future','sp_event','san-marino-vs-cote-divoire-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:5:"SIRIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21673'),(@id,'sp_team','15796');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,18);

-- PARTIDO 8: SIRIA (21665) vs RUSIA (21653)
-- Liga: Zona 1 (ttid=357) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: BELGICA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 15:10:00','2026-04-25 15:10:00','SIRIA vs RUSIA','future','sp_event','siria-vs-rusia-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"BELGICA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21665'),(@id,'sp_team','21653');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,18);

-- PARTIDO 9: BELGICA (15867) vs SUECIA (15838)
-- Liga: Zona 3 (ttid=359) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: RUSIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 16:25:00','2026-04-25 16:25:00','BELGICA vs SUECIA','future','sp_event','belgica-vs-suecia-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:5:"RUSIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15867'),(@id,'sp_team','15838');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,18);

-- PARTIDO 10: COSTA RICA (21591) vs COLOMBIA (15892)
-- Liga: Zona 2 (ttid=358) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: ITALIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 13:45:00','2026-04-25 13:45:00','COSTA RICA vs COLOMBIA','future','sp_event','costa-rica-vs-colombia-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:6:"ITALIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21591'),(@id,'sp_team','15892');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,19);

-- PARTIDO 11: ITALIA (15817) vs ALEMANIA (15828)
-- Liga: Zona 1 (ttid=357) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: SUIZA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 15:10:00','2026-04-25 15:10:00','ITALIA vs ALEMANIA','future','sp_event','italia-vs-alemania-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:5:"SUIZA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15817'),(@id,'sp_team','15828');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,19);

-- PARTIDO 12: SUIZA (15877) vs JAMAICA (21616)
-- Liga: Zona 2 (ttid=358) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: ALEMANIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 16:25:00','2026-04-25 16:25:00','SUIZA vs JAMAICA','future','sp_event','suiza-vs-jamaica-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:8:"ALEMANIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15877'),(@id,'sp_team','21616');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,19);

-- PARTIDO 13: IRLANDA (15919) vs ARGENTINA (15799)
-- Liga: Zona 5 (ttid=361) | Cancha: ANEXO I (ttid=16) | Mesa: URUGUAY
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 13:45:00','2026-04-25 13:45:00','IRLANDA vs ARGENTINA','future','sp_event','irlanda-vs-argentina-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"URUGUAY";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15919'),(@id,'sp_team','15799');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,16);

-- PARTIDO 14: URUGUAY (15844) vs BRASIL (15934)
-- Liga: Zona 4 (ttid=360) | Cancha: ANEXO I (ttid=16) | Mesa: SENEGAL
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 15:10:00','2026-04-25 15:10:00','URUGUAY vs BRASIL','future','sp_event','uruguay-vs-brasil-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"SENEGAL";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15844'),(@id,'sp_team','15934');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,16);

-- PARTIDO 15: SENEGAL (21657) vs KOSOVO (21630)
-- Liga: Zona 2 (ttid=358) | Cancha: ANEXO I (ttid=16) | Mesa: BRASIL
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-25 16:25:00','2026-04-25 16:25:00','SENEGAL vs KOSOVO','future','sp_event','senegal-vs-kosovo-25-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:6:"BRASIL";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21657'),(@id,'sp_team','21630');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,16);
