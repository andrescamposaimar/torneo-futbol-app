/// Whether [posicion] makes a plaza "the goalkeeper's plaza" for the
/// grouped goalkeeper-reassignment feature — mirrors
/// `Plazas\PosicionResolver::esPosicionDelArqueroTitular()` on the backend
/// EXACTLY: `true` ONLY for a titular goalkeeper (`'Arquero'`), never for
/// `'Arquero Sup.'` — see that method's own docblock for why these two must
/// stay distinct (a plaza whose titular is "Arquero Sup." is an ordinary
/// FIELD plaza, `es_arco = 0`, even though that same player counts as a
/// goalkeeper for OTHER purposes).
///
/// `GET /cambios/plazas` does not expose `es_arco` directly (see
/// `Rest\PlazasController::listar()`'s own response shape on the backend),
/// so the app derives the SAME fact from the titular's own resolved
/// position instead — the one piece of data every "Mi Plantel" card already
/// has (`jugadoresById[plaza.titularPlayerId]?.posicion`).
///
/// `null` (position not yet resolved, or the fetch failed) returns `false`
/// — fails OPEN, never closed: there is exactly one `'Arquero'` plaza per
/// team, and the one place this predicate decides whether to SHOW "Cambiar
/// por Titular" only ever reaches that decision once that specific plaza's
/// own data has already resolved (see `cambios_plantel_screen.dart`'s
/// `_TitularCard`). Every OTHER titular whose position is still pending is
/// therefore never the one plaza this predicate exists to find, so treating
/// him as "not the goalkeeper" while his data loads is the honest, and
/// harmless, default.
bool esPosicionArqueroTitular(String? posicion) => posicion == 'Arquero';
