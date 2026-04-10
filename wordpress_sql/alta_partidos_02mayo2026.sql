-- ============================================================
-- ALTA MASIVA DE PARTIDOS — 2 de Mayo de 2026
-- Temporada 2026 (term_taxonomy_id = 356)
-- Incluye campo Mesa en sp_specs (PHP serialized)
-- ============================================================

-- PARTIDO 1: SUECIA (15838) vs COTE DIVOIRE (15796)
-- Liga: Zona 3 (ttid=359) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: POLONIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 13:45:00','2026-05-02 13:45:00','SUECIA vs COTE DIVOIRE','future','sp_event','suecia-vs-cote-divoire-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"POLONIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15838'),(@id,'sp_team','15796');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,20);

-- PARTIDO 2: POLONIA (15898) vs HUNGRIA (21602)
-- Liga: Zona 5 (ttid=361) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: NUEVA ZELANDA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 15:10:00','2026-05-02 15:10:00','POLONIA vs HUNGRIA','future','sp_event','polonia-vs-hungria-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:13:"NUEVA ZELANDA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15898'),(@id,'sp_team','21602');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,20);

-- PARTIDO 3: BELGICA (15867) vs NUEVA ZELANDA (15803)
-- Liga: Zona 3 (ttid=359) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: HUNGRIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 16:25:00','2026-05-02 16:25:00','BELGICA vs NUEVA ZELANDA','future','sp_event','belgica-vs-nueva-zelanda-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"HUNGRIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15867'),(@id,'sp_team','15803');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,20);

-- PARTIDO 4: BRASIL (15934) vs CROACIA (15882)
-- Liga: Zona 4 (ttid=360) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: SENEGAL
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 13:45:00','2026-05-02 13:45:00','BRASIL vs CROACIA','future','sp_event','brasil-vs-croacia-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"SENEGAL";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15934'),(@id,'sp_team','15882');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,21);

-- PARTIDO 5: SENEGAL (21657) vs COLOMBIA (15892)
-- Liga: Zona 2 (ttid=358) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: COSTA RICA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 15:10:00','2026-05-02 15:10:00','SENEGAL vs COLOMBIA','future','sp_event','senegal-vs-colombia-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:10:"COSTA RICA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21657'),(@id,'sp_team','15892');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,21);

-- PARTIDO 6: COSTA RICA (21591) vs SUIZA (15877)
-- Liga: Zona 2 (ttid=358) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: COLOMBIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 16:25:00','2026-05-02 16:25:00','COSTA RICA vs SUIZA','future','sp_event','costa-rica-vs-suiza-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:8:"COLOMBIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21591'),(@id,'sp_team','15877');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,21);

-- PARTIDO 7: KOSOVO (21630) vs JAMAICA (21616)
-- Liga: Zona 2 (ttid=358) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: ESCOCIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 13:45:00','2026-05-02 13:45:00','KOSOVO vs JAMAICA','future','sp_event','kosovo-vs-jamaica-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"ESCOCIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21630'),(@id,'sp_team','21616');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,18);

-- PARTIDO 8: ESCOCIA (15939) vs IRLANDA (15919)
-- Liga: Zona 5 (ttid=361) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: MARRUECOS
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 15:10:00','2026-05-02 15:10:00','ESCOCIA vs IRLANDA','future','sp_event','escocia-vs-irlanda-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:9:"MARRUECOS";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15939'),(@id,'sp_team','15919');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,18);

-- PARTIDO 9: MARRUECOS (21637) vs RUSIA (21653)
-- Liga: Zona 1 (ttid=357) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: IRLANDA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 16:25:00','2026-05-02 16:25:00','MARRUECOS vs RUSIA','future','sp_event','marruecos-vs-rusia-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"IRLANDA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21637'),(@id,'sp_team','21653');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,18);

-- PARTIDO 10: URUGUAY (15844) vs JAPON (21623)
-- Liga: Zona 4 (ttid=360) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: NORUEGA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 13:45:00','2026-05-02 13:45:00','URUGUAY vs JAPON','future','sp_event','uruguay-vs-japon-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"NORUEGA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15844'),(@id,'sp_team','21623');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,19);

-- PARTIDO 11: NORUEGA (15808) vs ALEMANIA (15828)
-- Liga: Zona 1 (ttid=357) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: ARGENTINA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 15:10:00','2026-05-02 15:10:00','NORUEGA vs ALEMANIA','future','sp_event','noruega-vs-alemania-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:9:"ARGENTINA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15808'),(@id,'sp_team','15828');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,19);

-- PARTIDO 12: ESPAÑA (15855) vs ARGENTINA (15799)
-- Liga: Zona 5 (ttid=361) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: ALEMANIA
-- Nota: ESPAÑA = s:7 (Ñ ocupa 2 bytes en UTF-8)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 16:25:00','2026-05-02 16:25:00','ESPAÑA vs ARGENTINA','future','sp_event','espana-vs-argentina-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:8:"ALEMANIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15855'),(@id,'sp_team','15799');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,19);

-- PARTIDO 13: AUSTRALIA (21576) vs CABO VERDE (21584)
-- Liga: Zona 4 (ttid=360) | Cancha: ANEXO I (ttid=16) | Mesa: SIRIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 13:45:00','2026-05-02 13:45:00','AUSTRALIA vs CABO VERDE','future','sp_event','australia-vs-cabo-verde-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:5:"SIRIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21576'),(@id,'sp_team','21584');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,16);

-- PARTIDO 14: SIRIA (21665) vs ITALIA (15817)
-- Liga: Zona 1 (ttid=357) | Cancha: ANEXO I (ttid=16) | Mesa: ALBANIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 15:10:00','2026-05-02 15:10:00','SIRIA vs ITALIA','future','sp_event','siria-vs-italia-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"ALBANIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21665'),(@id,'sp_team','15817');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,16);

-- PARTIDO 15: ALBANIA (21568) vs SAN MARINO (21673)
-- Liga: Zona 3 (ttid=359) | Cancha: ANEXO I (ttid=16) | Mesa: ITALIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-02 16:25:00','2026-05-02 16:25:00','ALBANIA vs SAN MARINO','future','sp_event','albania-vs-san-marino-02-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:6:"ITALIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21568'),(@id,'sp_team','21673');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,16);
