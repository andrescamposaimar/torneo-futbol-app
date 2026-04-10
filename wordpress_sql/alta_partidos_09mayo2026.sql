-- ============================================================
-- ALTA MASIVA DE PARTIDOS — 9 de Mayo de 2026
-- Temporada 2026 (term_taxonomy_id = 356)
-- Incluye campo Mesa en sp_specs (PHP serialized)
-- ============================================================

-- PARTIDO 1: RUSIA (21653) vs ITALIA (15817)
-- Liga: Zona 1 (ttid=357) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: MARRUECOS
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 13:45:00','2026-05-09 13:45:00','RUSIA vs ITALIA','future','sp_event','rusia-vs-italia-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:9:"MARRUECOS";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21653'),(@id,'sp_team','15817');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,20);

-- PARTIDO 2: MARRUECOS (21637) vs ALEMANIA (15828)
-- Liga: Zona 1 (ttid=357) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: JAPON
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 15:10:00','2026-05-09 15:10:00','MARRUECOS vs ALEMANIA','future','sp_event','marruecos-vs-alemania-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:5:"JAPON";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21637'),(@id,'sp_team','15828');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,20);

-- PARTIDO 3: JAPON (21623) vs CABO VERDE (21584)
-- Liga: Zona 4 (ttid=360) | Cancha: CENTRAL FRENTE (ttid=20) | Mesa: ALEMANIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 16:25:00','2026-05-09 16:25:00','JAPON vs CABO VERDE','future','sp_event','japon-vs-cabo-verde-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:8:"ALEMANIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21623'),(@id,'sp_team','21584');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,20);

-- PARTIDO 4: HUNGRIA (21602) vs IRLANDA (15919)
-- Liga: Zona 5 (ttid=361) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: SUECIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 13:45:00','2026-05-09 13:45:00','HUNGRIA vs IRLANDA','future','sp_event','hungria-vs-irlanda-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:6:"SUECIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21602'),(@id,'sp_team','15919');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,21);

-- PARTIDO 5: SUECIA (15838) vs ALBANIA (21568)
-- Liga: Zona 3 (ttid=359) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: JAMAICA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 15:10:00','2026-05-09 15:10:00','SUECIA vs ALBANIA','future','sp_event','suecia-vs-albania-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"JAMAICA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15838'),(@id,'sp_team','21568');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,21);

-- PARTIDO 6: SENEGAL (21657) vs JAMAICA (21616)
-- Liga: Zona 2 (ttid=358) | Cancha: CENTRAL FONDO (ttid=21) | Mesa: ALBANIA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 16:25:00','2026-05-09 16:25:00','SENEGAL vs JAMAICA','future','sp_event','senegal-vs-jamaica-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"ALBANIA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21657'),(@id,'sp_team','21616');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,21);

-- PARTIDO 7: COLOMBIA (15892) vs SUIZA (15877)
-- Liga: Zona 2 (ttid=358) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: NUEVA ZELANDA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 13:45:00','2026-05-09 13:45:00','COLOMBIA vs SUIZA','future','sp_event','colombia-vs-suiza-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:13:"NUEVA ZELANDA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15892'),(@id,'sp_team','15877');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,18);

-- PARTIDO 8: NUEVA ZELANDA (15803) vs SAN MARINO (21673)
-- Liga: Zona 3 (ttid=359) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: URUGUAY
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 15:10:00','2026-05-09 15:10:00','NUEVA ZELANDA vs SAN MARINO','future','sp_event','nueva-zelanda-vs-san-marino-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"URUGUAY";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15803'),(@id,'sp_team','21673');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,18);

-- PARTIDO 9: URUGUAY (15844) vs CROACIA (15882)
-- Liga: Zona 4 (ttid=360) | Cancha: SIBERIA IZQUIERDA (ttid=18) | Mesa: SAN MARINO
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 16:25:00','2026-05-09 16:25:00','URUGUAY vs CROACIA','future','sp_event','uruguay-vs-croacia-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:10:"SAN MARINO";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15844'),(@id,'sp_team','15882');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,18);

-- PARTIDO 10: NORUEGA (15808) vs SIRIA (21665)
-- Liga: Zona 1 (ttid=357) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: BELGICA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 13:45:00','2026-05-09 13:45:00','NORUEGA vs SIRIA','future','sp_event','noruega-vs-siria-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"BELGICA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15808'),(@id,'sp_team','21665');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,19);

-- PARTIDO 11: BELGICA (15867) vs COTE DIVOIRE (15796)
-- Liga: Zona 3 (ttid=359) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: BRASIL
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 15:10:00','2026-05-09 15:10:00','BELGICA vs COTE DIVOIRE','future','sp_event','belgica-vs-cote-divoire-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:6:"BRASIL";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15867'),(@id,'sp_team','15796');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,19);

-- PARTIDO 12: BRASIL (15934) vs AUSTRALIA (21576)
-- Liga: Zona 4 (ttid=360) | Cancha: SIBERIA DERECHA (ttid=19) | Mesa: COTE DIVOIRE
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 16:25:00','2026-05-09 16:25:00','BRASIL vs AUSTRALIA','future','sp_event','brasil-vs-australia-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:12:"COTE DIVOIRE";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15934'),(@id,'sp_team','21576');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,19);

-- PARTIDO 13: POLONIA (15898) vs ARGENTINA (15799)
-- Liga: Zona 5 (ttid=361) | Cancha: ANEXO I (ttid=16) | Mesa: KOSOVO
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 13:45:00','2026-05-09 13:45:00','POLONIA vs ARGENTINA','future','sp_event','polonia-vs-argentina-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:6:"KOSOVO";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15898'),(@id,'sp_team','15799');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,16);

-- PARTIDO 14: KOSOVO (21630) vs COSTA RICA (21591)
-- Liga: Zona 2 (ttid=358) | Cancha: ANEXO I (ttid=16) | Mesa: ESPAÑA (s:7, Ñ=2 bytes UTF-8)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 15:10:00','2026-05-09 15:10:00','KOSOVO vs COSTA RICA','future','sp_event','kosovo-vs-costa-rica-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:7:"ESPAÑA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21630'),(@id,'sp_team','21591');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,16);

-- PARTIDO 15: ESPAÑA (15855) vs ESCOCIA (15939)
-- Liga: Zona 5 (ttid=361) | Cancha: ANEXO I (ttid=16) | Mesa: COSTA RICA
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-05-09 16:25:00','2026-05-09 16:25:00','ESPAÑA vs ESCOCIA','future','sp_event','espana-vs-escocia-09-05-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:1:{s:4:"mesa";s:10:"COSTA RICA";}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15855'),(@id,'sp_team','15939');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,16);
