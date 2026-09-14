<?php

declare(strict_types=1);

/**
 * Curated fixture of real, verified sp_player id/title pairs used across
 * NameParserTest and LinkResolverTest (design §9, task 2.8).
 *
 * Every id/title pair is verified against wordpress_sql/entrered_wp257.sql
 * (post_type = 'sp_player', post_status = 'publish'). `seasons` is curated
 * per test scenario, not scraped from wp_terms — LinkResolver only needs
 * season coverage per year, which this file supplies directly.
 *
 * Corrected: id 4739 ("Mazzara, Mauro", key {MAZZARA, M}) was previously
 * excluded from this fixture specifically so a "MAZZARA, M." entry would
 * resolve `sin_candidato` in LinkResolverTest. That was wrong: 4739 is a
 * real, published `sp_player` row, and omitting a real registered player
 * to manufacture a desired test outcome is exactly the defect this fixture
 * must never contain — a fixture is supposed to describe the directory,
 * not be shaped to fit a claim about it. 4739 is included below with its
 * real seasons (2016-2019, verified against wordpress_sql/entrered_wp257.sql).
 * `ZUBIZARRETA, F.` is used as the genuine zero-candidate example instead —
 * see LinkResolverTest's docblock for how that name was verified.
 *
 * @return array<int, array{id: int, title: string, seasons: string[]}>
 */
return [
    [ 'id' => 4739, 'title' => 'Mazzara, Mauro', 'seasons' => [ '2016', '2017', '2018', '2019' ] ],
    [ 'id' => 5078, 'title' => 'Basso, Alejandro', 'seasons' => [ '2016' ] ],
    [ 'id' => 2225, 'title' => 'Garcia, Miguel Luis', 'seasons' => [ '2016' ] ],
    [ 'id' => 2461, 'title' => 'Garcia, Marcelo Daniel', 'seasons' => [ '2016' ] ],
    [ 'id' => 2494, 'title' => 'Garcia, Ariel', 'seasons' => [ '2017' ] ],
    [ 'id' => 10422, 'title' => 'Garcia, Antonio', 'seasons' => [ '2017' ] ],
    [ 'id' => 21517, 'title' => 'Garcia, Gaston', 'seasons' => [ '2016' ] ],
    [ 'id' => 4697, 'title' => 'Garcia, Emilio Nestor', 'seasons' => [ '2016' ] ],
    [ 'id' => 4698, 'title' => 'Gabriel Garcia Conejero', 'seasons' => [ '2016' ] ],
    [ 'id' => 4770, 'title' => 'Gustavo Eduardo Pardo', 'seasons' => [ '2018' ] ],
    [ 'id' => 13677, 'title' => 'Pardo, Guido', 'seasons' => [ '2019' ] ],
    [ 'id' => 22934, 'title' => 'Pardo, Gonzalo Ezequiel', 'seasons' => [ '2020' ] ],
    [ 'id' => 2323, 'title' => 'Calello, Gonzalo', 'seasons' => [ '2018' ] ],
    [ 'id' => 11599, 'title' => 'Calello, Jose', 'seasons' => [ '2018' ] ],
    [ 'id' => 4814, 'title' => 'Andres Dos Santos', 'seasons' => [ '2018' ] ],
    [ 'id' => 14674, 'title' => 'ESTEBAN NICOLAS RAMON D´AGOSTINO', 'seasons' => [ '2018' ] ],
    [ 'id' => 4753, 'title' => 'Reynaldo A. Muscari', 'seasons' => [ '2018' ] ],
    [ 'id' => 4886, 'title' => 'Juan Santos', 'seasons' => [ '2016' ] ],
    [ 'id' => 2274, 'title' => 'Santostefano, Pablo', 'seasons' => [ '2016' ] ],
    [ 'id' => 2492, 'title' => 'De La Fuente, Javier', 'seasons' => [ '2016' ] ],
    [ 'id' => 14819, 'title' => 'Palou De Comasema, Adrian', 'seasons' => [ '2016' ] ],
    [ 'id' => 2253, 'title' => 'Andres Olalla De Labra', 'seasons' => [ '2016' ] ],
    [ 'id' => 4659, 'title' => 'Pablo D Arezzo', 'seasons' => [ '2016' ] ],
    [ 'id' => 14877, 'title' => 'PABLO D´ELIA', 'seasons' => [ '2016' ] ],
];
