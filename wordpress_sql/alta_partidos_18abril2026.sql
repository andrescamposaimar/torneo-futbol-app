-- ============================================================
-- ALTA MASIVA DE PARTIDOS — 18 de Abril de 2026
-- Temporada 2026 (term_taxonomy_id = 356)
-- Estructura replicada exactamente de evento manual existente
-- ============================================================

-- PARTIDO 1: ALEMANIA (15828) vs RUSIA (21653)
-- Liga: Zona 1 (ttid=357) | Cancha: CENTRAL FRENTE (ttid=20)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 13:45:00','2026-04-18 13:45:00','ALEMANIA vs RUSIA','future','sp_event','alemania-vs-rusia-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),
(@id,'sp_mode','team'),
(@id,'sp_status','ok'),
(@id,'sp_day',''),
(@id,'sp_minutes',''),
(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),
(@id,'sp_players','a:0:{}'),
(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),
(@id,'sp_specs','a:0:{}'),
(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),
(@id,'sp_player','0'),
(@id,'sp_player','0'),
(@id,'sp_staff','0'),
(@id,'sp_staff','0'),
(@id,'sp_team','15828'),
(@id,'sp_team','21653');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,20);

-- PARTIDO 2: SUIZA (15877) vs KOSOVO (21630)
-- Liga: Zona 2 (ttid=358) | Cancha: CENTRAL FRENTE (ttid=20)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 15:10:00','2026-04-18 15:10:00','SUIZA vs KOSOVO','future','sp_event','suiza-vs-kosovo-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15877'),(@id,'sp_team','21630');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,20);

-- PARTIDO 3: COTE DIVOIRE (15796) vs NUEVA ZELANDA (15803)
-- Liga: Zona 3 (ttid=359) | Cancha: CENTRAL FRENTE (ttid=20)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 16:25:00','2026-04-18 16:25:00','COTE DIVOIRE vs NUEVA ZELANDA','future','sp_event','cote-divoire-vs-nueva-zelanda-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15796'),(@id,'sp_team','15803');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,20,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,20);

-- PARTIDO 4: ITALIA (15817) vs NORUEGA (15808)
-- Liga: Zona 1 (ttid=357) | Cancha: CENTRAL FONDO (ttid=21)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 13:45:00','2026-04-18 13:45:00','ITALIA vs NORUEGA','future','sp_event','italia-vs-noruega-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15817'),(@id,'sp_team','15808');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,21);

-- PARTIDO 5: IRLANDA (15919) vs ESPAÑA (15855)
-- Liga: Zona 5 (ttid=361) | Cancha: CENTRAL FONDO (ttid=21)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 15:10:00','2026-04-18 15:10:00','IRLANDA vs ESPAÑA','future','sp_event','irlanda-vs-espana-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15919'),(@id,'sp_team','15855');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,21);

-- PARTIDO 6: URUGUAY (15844) vs AUSTRALIA (21576)
-- Liga: Zona 4 (ttid=360) | Cancha: CENTRAL FONDO (ttid=21)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 16:25:00','2026-04-18 16:25:00','URUGUAY vs AUSTRALIA','future','sp_event','uruguay-vs-australia-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15844'),(@id,'sp_team','21576');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,21,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,21);

-- PARTIDO 7: CABO VERDE (21584) vs BRASIL (15934)
-- Liga: Zona 4 (ttid=360) | Cancha: SIBERIA IZQUIERDA (ttid=18)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 13:45:00','2026-04-18 13:45:00','CABO VERDE vs BRASIL','future','sp_event','cabo-verde-vs-brasil-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21584'),(@id,'sp_team','15934');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,18);

-- PARTIDO 8: JAMAICA (21616) vs COLOMBIA (15892)
-- Liga: Zona 2 (ttid=358) | Cancha: SIBERIA IZQUIERDA (ttid=18)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 15:10:00','2026-04-18 15:10:00','JAMAICA vs COLOMBIA','future','sp_event','jamaica-vs-colombia-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21616'),(@id,'sp_team','15892');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,18);

-- PARTIDO 9: ARGENTINA (15799) vs HUNGRIA (21602)
-- Liga: Zona 5 (ttid=361) | Cancha: SIBERIA IZQUIERDA (ttid=18)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 16:25:00','2026-04-18 16:25:00','ARGENTINA vs HUNGRIA','future','sp_event','argentina-vs-hungria-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15799'),(@id,'sp_team','21602');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,18,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,18);

-- PARTIDO 10: POLONIA (15898) vs ESCOCIA (15939)
-- Liga: Zona 5 (ttid=361) | Cancha: SIBERIA DERECHA (ttid=19)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 13:45:00','2026-04-18 13:45:00','POLONIA vs ESCOCIA','future','sp_event','polonia-vs-escocia-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15898'),(@id,'sp_team','15939');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,361,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (361,356,19);

-- PARTIDO 11: SENEGAL (21657) vs COSTA RICA (21591)
-- Liga: Zona 2 (ttid=358) | Cancha: SIBERIA DERECHA (ttid=19)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 15:10:00','2026-04-18 15:10:00','SENEGAL vs COSTA RICA','future','sp_event','senegal-vs-costa-rica-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21657'),(@id,'sp_team','21591');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,358,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (358,356,19);

-- PARTIDO 12: CROACIA (15882) vs JAPON (21623)
-- Liga: Zona 4 (ttid=360) | Cancha: SIBERIA DERECHA (ttid=19)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 16:25:00','2026-04-18 16:25:00','CROACIA vs JAPON','future','sp_event','croacia-vs-japon-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15882'),(@id,'sp_team','21623');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,360,0),(@id,356,0),(@id,19,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (360,356,19);

-- PARTIDO 13: MARRUECOS (21637) vs SIRIA (21665)
-- Liga: Zona 1 (ttid=357) | Cancha: ANEXO I (ttid=16)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 13:45:00','2026-04-18 13:45:00','MARRUECOS vs SIRIA','future','sp_event','marruecos-vs-siria-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21637'),(@id,'sp_team','21665');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,357,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (357,356,16);

-- PARTIDO 14: SAN MARINO (21673) vs SUECIA (15838)
-- Liga: Zona 3 (ttid=359) | Cancha: ANEXO I (ttid=16)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 15:10:00','2026-04-18 15:10:00','SAN MARINO vs SUECIA','future','sp_event','san-marino-vs-suecia-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','21673'),(@id,'sp_team','15838');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,16);

-- PARTIDO 15: BELGICA (15867) vs ALBANIA (21568)
-- Liga: Zona 3 (ttid=359) | Cancha: ANEXO I (ttid=16)
INSERT INTO wp_posts (post_author,post_date,post_date_gmt,post_title,post_status,post_type,post_name,comment_status,ping_status,post_content,post_excerpt,to_ping,pinged,post_content_filtered)
VALUES (1,'2026-04-18 16:25:00','2026-04-18 16:25:00','BELGICA vs ALBANIA','future','sp_event','belgica-vs-albania-18-04-2026','closed','closed','','','','','');
SET @id = LAST_INSERT_ID();
INSERT INTO wp_postmeta (post_id,meta_key,meta_value) VALUES
(@id,'sp_format','league'),(@id,'sp_mode','team'),(@id,'sp_status','ok'),
(@id,'sp_day',''),(@id,'sp_minutes',''),(@id,'sp_video',''),
(@id,'sp_order','a:0:{}'),(@id,'sp_players','a:0:{}'),(@id,'sp_result_columns','a:0:{}'),
(@id,'sp_results','a:0:{}'),(@id,'sp_specs','a:0:{}'),(@id,'sp_stars','a:0:{}'),
(@id,'sp_timeline','a:0:{}'),(@id,'sp_player','0'),(@id,'sp_player','0'),
(@id,'sp_staff','0'),(@id,'sp_staff','0'),
(@id,'sp_team','15867'),(@id,'sp_team','21568');
INSERT INTO wp_term_relationships (object_id,term_taxonomy_id,term_order) VALUES (@id,359,0),(@id,356,0),(@id,16,0);
UPDATE wp_term_taxonomy SET count=count+1 WHERE term_taxonomy_id IN (359,356,16);
