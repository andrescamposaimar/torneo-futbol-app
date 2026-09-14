# Staging verification — slice 7 (REST endpoints + caching)

`composer test` proves the response shape (`TitleShaper`), the caching
behaviour against a fake transient store, and the batched photo lookup
against a scripted fake (`FakePlayerPhotoProvider`). It CANNOT prove:

- that the routes are actually reachable through real WordPress REST
  routing (the shim's `register_rest_route()` is a no-op);
- that `WpPlayerPhotoProvider`'s `postmeta` query, `_prime_post_caches()`
  and `wp_get_attachment_image_url()` calls return correct URLs against
  real attachments (the shim has neither real posts nor a real object
  cache — design §9 states this explicitly);
- that transients genuinely persist across requests in `wp_options` on the
  real host, or that `CacheInvalidator::flush()` actually clears them there.

All three must be checked by hand, once, after deploying to staging. There
is real production data loaded (17 years, 2009-2025) — these endpoints will
return real content the moment they deploy.

## 1. Full history — `GET /campeones/historia`

```bash
curl -s 'https://entreredespadres.com.ar/wp-json/entre-redes/v1/campeones/historia' | jq .
```

**Correct response looks like:**

- HTTP 200.
- `{"titulos": [ {...}, {...}, ... ]}` — one entry per recorded
  `(anio, zona, posicion)`, ordered `anio` **descending** (2025 first,
  earliest year last).
- Each entry has `anio` (int), `zona` (string), `posicion` (string),
  `equipo_nombre` (string), and `plantel` (array).
- Each `plantel` entry has `nombre`, `es_capitan` (bool), `jugador_id`
  (int or `null`), and `foto_url` (string or `null` — **never** `false`,
  never `""`). There is **no** `estado_vinculo` key — it is internal
  diagnostic state, deliberately excluded from the public payload.
- At least one pre-2016 year (e.g. 2011) is present **in full**, with
  every squad name visible even though most or all of its rows are
  unlinked (`jugador_id: null`) in the underlying data. This is API-3 /
  REC-6: a fully-unlinked year is a valid, complete record, not an error.
- A player known to be linked AND to have a WordPress featured image
  shows a real `https://.../wp-content/uploads/.../*-medium*.jpg`-shaped
  URL in `foto_url`.
- A player known to be linked but with NO featured image shows
  `"foto_url": null` — not `false`, not an empty string, not a missing
  key.
- An unlinked entry (`jugador_id: null`) always shows `"foto_url": null`.

**What a wrong response looks like:**

- HTTP 404 or 500 — the route did not register (check `Plugin::boot()`'s
  `rest_api_init` wiring actually ran; check the PHP error log for a
  fatal on plugin load).
- `anio` values not in descending order.
- A pre-2016 year missing entirely, or present with an empty `plantel`
  array instead of the real (unlinked) names.
- `"foto_url": false` anywhere — means `TitleShaper`'s coercion regressed
  and a raw `get_the_post_thumbnail_url()` `false` leaked onto the wire.
- An `estado_vinculo` key present anywhere in a `plantel` entry — it is
  internal diagnostic state and must never be published (item 7).
- The response takes a noticeably long time (multiple seconds) on a
  cache MISS — would suggest the photo lookup is not actually batched
  (compare against re-running the same curl immediately after: the
  second call should be near-instant, served from the transient).

## 2. Per-player titles — `GET /campeones/jugador/{id}/titulos`

Pick a real linked `jugador_id` from step 1's response (any entry with a
non-null `jugador_id`), then:

```bash
curl -s 'https://entreredespadres.com.ar/wp-json/entre-redes/v1/campeones/jugador/5078/titulos' | jq .
# replace 5078 with a real id from step 1
```

**Correct response looks like:**

- HTTP 200.
- `{"jugador_id": 5078, "total": N, "titulos": [...]}`.
- `titulos` ordered `anio` descending.
- Each entry has `anio`, `equipo_nombre`, `es_capitan` — and **no**
  `foto_url` key at all (API-2's deliberate text-only decision).

Now pick an id that is **not** linked to anything (a large made-up number,
e.g. `999999999`):

```bash
curl -s -w '\n%{http_code}\n' 'https://entreredespadres.com.ar/wp-json/entre-redes/v1/campeones/jugador/999999999/titulos'
```

**Correct response looks like:**

- HTTP **200** (never 404, never an error body) — API-4.
- `{"jugador_id": 999999999, "total": 0, "titulos": []}`.

**What a wrong response looks like:**

- HTTP 404 for a zero-title id — API-4 violation.
- A `foto_url` key present anywhere in a `titulos` entry.
- `total` disagreeing with `count(titulos)`.

## 3. Cache invalidation

1. Note the current `equipo_nombre` for any one title from step 1.
2. In wp-admin, open that title in the editor (`campeones-titulo-edit`)
   and change the team name, save.
3. Re-run the step 1 curl immediately.

**Correct**: the new team name appears immediately (the save invalidated
`campeones_historia_v2`). **Wrong**: the old team name is still served —
either the `CacheInvalidator` wiring regressed, or (per the code comment
in `CacheInvalidator`) the `litespeed-cache` object-cache drop-in has been
enabled since this was written, in which case the raw `wp_options` DELETE
for per-player transients would silently stop working and
`delete_transient()` calls should be checked instead.

## 4. No N+1 on the photo lookup (informal)

There is no direct way to count SQL queries from `curl`, but a rough
signal: time the FIRST call after clearing the cache (e.g. right after
editing any title, per step 3) against a year with a large squad. It
should complete in well under a second. A multi-second response on a
history with ~17 years / ~220 entries would suggest the photo lookup
regressed to one query per entry instead of one batched query for the
whole response (`WpPlayerPhotoProvider::findPhotoUrls()`).

## Out of scope for this checklist

Everything else in slice 7 — response shape, the nullable-slot
`RestController` aggregator, the transient TTL/versioning logic, and the
`CacheInvalidator` LIKE-pattern per-player delete — is fully covered by
`composer test` against the SQLite shim and needs no manual check.
