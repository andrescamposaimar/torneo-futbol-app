# Auditoría Técnica — Entre Redes v2
**Fecha:** Marzo 2026
**Versión auditada:** 1.0.8+12 (iOS) / 1.1.10 build 14 (Android)
**Tipo:** Auditoría completa post-refactoring

---

## Resumen ejecutivo

Entre Redes es una app Flutter para gestión del torneo de fútbol escolar del Colegio Marianista (Liga Escolar "Torneo Chami"). Consume datos de un backend WordPress REST API custom, con datos estructurados mediante el plugin SportsPress.

Desde la primera auditoría se completaron mejoras significativas: migración a Riverpod, Repository Pattern, extracción de lógica de negocio a servicios puros, modelos tipados, y corrección de múltiples bugs. La arquitectura es correcta y el código se mantiene bien. Esta segunda auditoría se enfoca en los issues que subsisten y en la alineación app ↔ backend.

**Estado general:** Sólido — con 4 issues a resolver antes del próximo ciclo

---

## 1. Arquitectura

### 1.1 Stack tecnológico
- **Flutter** 3.x · Dart (null-safe)
- **Riverpod** 2.6.0 — inyección de dependencias
- **SharedPreferences** — caché local (7 días TTL)
- **http** 1.3.0 — requests HTTP
- **webview_flutter** 4.2.2 — pantalla de solicitud de cambio
- **WordPress REST API** — backend (SportsPress + plugin custom)

### 1.2 Capas de la aplicación

```
UI (screens/)
  └─ Riverpod providers (providers/)
       └─ Repositories (repositories/)
            └─ IApiService / ICacheService
                  └─ ApiService → WordPress REST API
                  └─ CacheService → SharedPreferences
```

La separación de capas es correcta. Cada capa tiene responsabilidades claras:

| Capa | Responsabilidad |
|------|----------------|
| `screens/` | UI, estado local, paginación, filtros |
| `repositories/` | Patrón fetch-cache integrado por entidad |
| `services/` | HTTP puro (ApiService), caché pura (CacheService), lógica de negocio pura (PlayerFilterService, StandingsService) |
| `providers/` | DI via Riverpod (lazy singletons) |
| `models/` | Tipos fuertemente tipados con fromJson/toJson |
| `utils/` | Funciones puras de dominio (fechas, posiciones, puntajes, ligas) |
| `widgets/` | Componentes reutilizables (PlayerPod, MatchCard, etc.) |

### 1.3 Patrón de estado

- **Riverpod** solo para inyección de dependencias y datos compartidos entre pantallas (`temporadasProvider`, `temporadaActualProvider`)
- **setState() local** para todo el estado de UI dentro de cada pantalla
- Sin state management global — decisión pragmática correcta para el scope del proyecto

---

## 2. Issues Críticos

### C-1 — `is_current` ausente del endpoint `/temporadas`

**Archivo:** `wordpress_files/endpoints_wordpress.txt` vs `lib/models/temporada.dart`

El endpoint de temporadas documentado retorna únicamente:
```php
return array_values(array_map(fn($term) => [
    'id'   => $term->term_id,
    'name' => $term->name,
], $filtered));
```

Sin embargo, `Temporada.fromJson()` espera `is_current`:
```dart
isCurrent: json['is_current'] == true,
```

Y `MainNavigation._initScreens()` llama a `temporadaActualProvider` que lanza `StateError` si ninguna temporada tiene `isCurrent == true`. Si el backend no incluye `is_current`, la app queda en loading infinito al inicio.

**Hipótesis:** El backend en producción sí incluye `is_current` (fue añadido post-archivo-txt) pero el archivo de documentación no está actualizado. Esto crea un riesgo de desincronización si el backend se modifica.

**Acciones recomendadas:**
1. Verificar que el endpoint live retorna `is_current`
2. Actualizar `endpoints_wordpress.txt` con el código actual del `functions.php`
3. Agregar fallback en `temporadaActualProvider`: si ninguna tiene `isCurrent`, usar la de `id` más alto como último recurso

---

### C-2 — Sin timeout en requests HTTP

**Archivo:** `lib/services/api_service.dart` (todo el archivo)

Todos los `http.get(uri)` se hacen sin timeout. Si el servidor WordPress no responde, la app espera indefinidamente. En conexiones móviles lentas esto genera una UX inaceptable.

```dart
// Actual (sin timeout):
final res = await http.get(uri);

// Recomendado:
final res = await http.get(uri).timeout(
  const Duration(seconds: 15),
  onTimeout: () => throw TimeoutException('Timeout: $uri'),
);
```

**Prioridad:** Alta. Afecta a todas las pantallas.

---

### C-3 — Versión Android desincronizada del pubspec.yaml

**Archivo:** `android/app/build.gradle.kts` líneas 35-36

```kotlin
versionCode = 14
versionName = "1.1.10"
```

El `pubspec.yaml` dice `version: 1.0.8+12`. Android usa valores hardcodeados en lugar de los del pubspec (`flutter.versionCode` / `flutter.versionName`). Esto genera confusión en los changelogs y hace que cada release requiera actualizar dos archivos.

**Recomendado:**
```kotlin
versionCode = flutter.versionCode
versionName = flutter.versionName
```

---

### C-4 — Loading infinito si `temporadaActualProvider` falla

**Archivo:** `lib/main.dart`, líneas 106-118

`_initScreens()` espera la temporada actual antes de mostrar cualquier pantalla. Si la API falla (sin red, timeout, etc.), `_screens` nunca se inicializa y el usuario ve un spinner indefinidamente sin ningún mensaje de error ni opción de reintentar.

```dart
// Actual:
if (_screens == null) {
  return Scaffold(
    body: Center(child: CircularProgressIndicator(color: Colors.white)),
  );
}
// No hay manejo de error ni botón de reintentar
```

**Prioridad:** Alta. Afecta la primera carga con mala red o servidor caído.

---

## 3. Issues de Calidad (no críticos)

### Q-1 — Repository Pattern incompleto

El patrón repositorio se implementó para scorers, imbatibles, temporadas, standings y caché. Pero las pantallas de partidos, jugadores y equipos siguen leyendo directamente de `apiServiceProvider`/`cacheServiceProvider`. No es un bug, pero rompe la consistencia arquitectónica.

Pantallas que aún usan servicios directamente:
- `matches_screen.dart` — `apiServiceProvider`, `cacheServiceProvider`
- `players_screen.dart` — `apiServiceProvider`, `cacheServiceProvider`
- `teams_screen.dart` — `apiServiceProvider`
- `team_detail_screen.dart` — `apiServiceProvider`
- `player_detail_screen.dart` — `apiServiceProvider`

**Recomendado (largo plazo):** Completar con `MatchesRepository`, `PlayersRepository`, `TeamsRepository` cuando se necesiten más pantallas o tests.

---

### Q-2 — Métodos legacy en CacheService potencialmente muertos

**Archivo:** `lib/services/cache_service.dart`, líneas 47-125

Los métodos `cachePlayers()`, `getCachedPlayers()`, `cachePlayersTemporada()`, `getCachedPlayersTemporada()`, `cachePlayersHistoricos()`, `getCachedPlayersHistoricos()` usan claves genéricas sin temporadaId. La nueva implementación usa `cached_players_current_{temporadaId}`. Verificar si estos métodos tienen callers activos; si no, pueden eliminarse de la interfaz y la implementación.

---

### Q-3 — Inconsistencia en nombres de campos de caché

Los distintos métodos de caché en `CacheService` usan nombres de campo distintos para el dato almacenado:
- `cacheTemporadas` → key `'data'`
- `cachePlayers` → key `'players'`
- `cacheScorers` → key `'scorers'`
- `cacheImbatibles` → key `'arqueros'`
- `cachePartidosJugados` → key `'partidos'`

No causa bugs (cada método lee la misma key que escribe), pero dificulta la legibilidad y el debugging. Unificar a un campo común como `'data'` sería más limpio.

---

### Q-4 — `getEquipos()` duplica la lógica de `_fetchAllPages()`

**Archivo:** `lib/services/api_service.dart`, líneas 132-168

`getEquipos()` implementa manualmente su propia paginación con un while loop. `_fetchAllPages()` existe exactamente para eso, pero no puede usarse aquí porque el endpoint retorna `{items: [...], total_pages: N}` en lugar de un array plano. Podría refactorizarse para que `_fetchAllPages()` acepte ambos formatos. De momento es funcional pero inconsistente.

---

### Q-5 — `SplashToMain` no cumple función real

**Archivo:** `lib/main.dart`, líneas 57-86

`SplashToMain` muestra un `SizedBox.shrink()` (vacío) y en el primer frame navega a `MainNavigation`. No muestra logo ni contenido. El splash real viene del sistema operativo (LaunchScreen.storyboard / launch_background.xml). Este widget existe por razones históricas pero no aporta valor; podría inicializar directamente desde `home: const MainNavigation()`.

---

### Q-6 — Log de caché semanal con hora incorrecta

**Archivo:** `lib/services/cache_service.dart`, línea 366

```dart
debugPrint('🧹 Caché eliminada en la ventana semanal post sábado 19h');
```

El código actualmente limpia cuando la hora es >= 21:00 (línea 335: `21,`), pero el mensaje dice `19h`. Detalle menor pero confuso para debugging.

---

### Q-7 — IndexedStack mantiene todas las pantallas vivas simultáneamente

**Archivo:** `lib/main.dart`, línea 135

```dart
body: IndexedStack(index: _selectedIndex, children: _screens!),
```

`IndexedStack` crea y mantiene en memoria todos los widgets hijos desde el inicio. Con 5 pantallas que cargan datos en `initState`, esto puede causar múltiples requests paralelos al abrir la app. El comportamiento actual (preservar estado al cambiar tabs) es una feature deseable; evaluar si el uso de memoria justifica un cambio.

---

### Q-8 — `IApiService.getTablas()` vs `ApiRepository.getTablas()` — firma inconsistente

`IApiService` define:
```dart
Future<Map<String, dynamic>> getTablas({String? temporada, String? zona, ...})
```

`ApiRepository` expone:
```dart
Future<Map<String, dynamic>> getTablas({int? temporada, int? zona, ...})
```

El repositorio convierte internamente `int → String`. Los callers deben recordar qué capa acepta qué tipo. La interfaz debería unificarse usando `int?` también.

---

## 4. Análisis del Backend WordPress

### 4.1 Estructura del backend

El backend usa **WordPress + SportsPress** (plugin de gestión deportiva) con un plugin custom que agrega la REST API `entre-redes/v1`. Todos los endpoints son funciones registradas en `functions.php`.

### 4.2 Caché de doble nivel (positivo)

El backend implementa `cachear_respuesta_rest()` con transients de WordPress:
- Temporadas / Ligas: **30 días** de caché en servidor
- La app cachea en cliente: **7 días** (SharedPreferences)
- Limpieza semanal automática en cliente: **sábados 21h**

**Riesgo:** Si se actualizan datos en WordPress (nueva temporada, nuevo equipo), el transient del servidor tarda hasta 30 días en expirar. Para datos estructurales estables (temporadas, ligas) esto es aceptable. Para datos frecuentes habría que reducir el TTL del transient.

### 4.3 Endpoints no autenticados

Todos los endpoints son públicos. No hay API key, OAuth, ni rate limiting visible. Para un torneo escolar con datos públicos esto es aceptable, pero considerar:
- **Rate limiting** (plugin o .htaccess) para evitar scraping o abuso accidental
- Al menos un header `User-Agent` identificador desde la app

### 4.4 Endpoint `/tabla-goleadores` — naming de parámetros inconsistente

La app llama a este endpoint con `id_temporada`:
```dart
if (temporada != null) queryParams['id_temporada'] = temporada.toString();
```

Todos los demás endpoints usan `temporada` (sin prefijo `id_`). Verificar que el backend acepta `id_temporada` y documentarlo explícitamente para evitar confusión en el mantenimiento.

### 4.5 Endpoint `/partidos-equipo` — dos variantes de parámetro

La app tiene dos métodos distintos que llaman al mismo endpoint:
```dart
// Variante 1: por nombre de equipo (string)
getHistorialDePartidosPorEquipo(String equipo) → ?equipo=nombre

// Variante 2: por ID de equipo
getPartidosPorEquipoId(int equipoId) → ?equipo_id=123
```

Ambas variantes deberían estar documentadas y testeadas en el backend. Si el endpoint solo soporta una, la otra falla silenciosamente.

### 4.6 Filtro `term_id >= 149` hardcodeado en temporadas

```php
$filtered = array_filter($terms, fn($term) => $term->term_id >= 149);
```

Este filtro excluye temporadas antiguas (IDs menores a 149). Es funcional pero frágil: si WordPress reasigna IDs o se importan temporadas con IDs menores, el filtro falla silenciosamente. Más robusto sería filtrar por año o por un campo custom de WordPress.

---

## 5. Análisis por pantalla

| Pantalla | Estado | Notas |
|----------|--------|-------|
| MatchesScreen | ✅ | Fix de DropdownButton aplicado. Sin repositorio. |
| MatchDetailScreen | ✅ | Goleadores async, PlayerPod bien extraído. |
| StandingsScreen | ✅ | Usa apiRepositoryProvider. Desempate correcto. |
| PlayersScreen | ✅ | Tabs con lazy load, cache por temporadaId. |
| TeamsScreen | ✅ | Debounce 300ms, cache 3 días. Sin repositorio. |
| TeamDetailScreen | ✅ | Plantel + historial. Sin repositorio. |
| PlayerDetailScreen | ✅ | Stats + historial paginado. Sin repositorio. |
| ScorersScreen | ✅ | Cache primer load, sin cache para paginación. |
| ImbatiblesScreen | ✅ | Igual que scorers. Correcto. |
| ListasScreen | ⚠️ | Sin caché del JSON externo. Falla silenciosa si el JSON no carga. |
| MoreScreen | ✅ | Usa cacheRepositoryProvider. Debug-only clear. |
| SolicitudCambioWebView | ✅ | JS injection para ocultar header/footer. |

---

## 6. Modelo de datos

### 6.1 Modelos bien implementados

| Modelo | Campos clave | fromJson | toJson |
|--------|-------------|---------|--------|
| `Temporada` | id, name, isCurrent | ✅ | ✅ |
| `Equipo` | id, nombre, imagen?, escudo?, temporadas | ✅ | ✅ |
| `Partido` | id, local, visitante, goles, fecha, liga | ✅ | ✅ |
| `Jugador` | id, nombre, posicion, puntaje, equipo, etc. | ✅ | ✅ |

### 6.2 Datos no tipados (candidatos a modelar)

Las respuestas de goleadores del partido, filas de tabla de posiciones, imbatibles y partidos del jugador se trabajan como `Map<String, dynamic>` / `List<dynamic>`. No es un bug pero reduce la seguridad de tipos.

**Candidatos para modelar en una iteración futura:** `GoleadorDelPartido`, `FilaTabla`, `Imbatible`, `PartidoDelJugador`

---

## 7. Estrategia de caché

### 7.1 Resumen de claves y TTL

| Clave | TTL cliente | TTL servidor |
|-------|------------|-------------|
| `cached_temporadas` | 7 días | 30 días |
| `cached_players_current_{id}` | 7 días | N/A |
| `cached_scorers_{id}` | 7 días | variable |
| `cached_imbatibles_{id}` | 7 días | variable |
| `cached_partidos_jugados_{id}` | 7 días | variable |
| `cached_players_equipo_{id}` | 3 días | N/A |
| `cache_equipos_actuales` | 3 días | variable |
| `cache_equipos_historicos` | 3 días | variable |

### 7.2 Limpieza automática

- `clearCacheOncePerWeekWindow()` se ejecuta al inicio de la app (sábados 21h): ✅
- Los prefijos dinámicos se limpian correctamente en `clearAllCaches()`: ✅
- El log dice "19h" pero el código es a las 21h: ver Q-6

### 7.3 Observación sobre caché del servidor

Con 30 días de TTL en el transient de WordPress para temporadas y ligas, el botón "Limpiar caché" de la app solo limpia el cliente. Si hay cambios en el backend, el servidor seguirá sirviendo datos en caché hasta que el transient expire. Para datos críticos que cambian frecuentemente, reducir el TTL del servidor.

---

## 8. Configuración de build

### 8.1 iOS
- Bundle ID: `com.andrescampos.torneochaminade`
- `ITSAppUsesNonExemptEncryption = false` declarado: ✅
- Splash color `#0057AA` en LaunchScreen.storyboard: ✅
- Versión seguida desde pubspec.yaml: ✅

### 8.2 Android
- Bundle ID: `com.entre_redes.app`
- Versión hardcodeada `versionCode=14, versionName="1.1.10"` — desincronizada: ver C-3
- Splash color `#0057AA` en colors.xml + launch_background.xml: ✅
- Keystore: nuevo `entre_redes_key.jks` generado en Marzo 2026
- Upload key reset en proceso con Google Play (Play App Signing activo)

---

## 9. Tests

**Estado actual: 0 tests**

La arquitectura post-refactoring está lista para testing:

| Componente | Testeable sin Flutter | Prioridad |
|------------|-----------------------|----------|
| `PlayerFilterService` | ✅ | Alta |
| `StandingsService` | ✅ | Alta |
| `date_utils` | ✅ | Media |
| `liga_utils` | ✅ | Media |
| `Jugador.fromJson()` | ✅ | Alta (puntaje con formatos variados) |
| `ApiRepository` | ✅ (mock interfaces) | Media |
| Screens | Widget tests con ProviderScope | Baja |

**Tests prioritarios a escribir:**
1. `PlayerFilterService.filtrar()` — sin filtro, por posición, por query, combinado
2. `StandingsService.ordenarYAsignarPosicion()` — empates por pts, DG, GF
3. `Jugador.fromJson()` — puntaje como String, num, null, con coma decimal
4. `ApiRepository.getTemporadas()` — mock cache hit, mock cache miss + API call

---

## 10. Seguridad

| Check | Estado |
|-------|--------|
| No `print()` en producción | ✅ Solo `debugPrint` con `kDebugMode` |
| Sin credenciales hardcodeadas en código | ✅ |
| `key.properties` fuera del repo | ✅ |
| HTTPS en todos los endpoints | ✅ |
| `ITSAppUsesNonExemptEncryption` declarado | ✅ |
| GAS URL / dead code eliminado | ✅ |
| Endpoints autenticados | No aplica (datos públicos del torneo) |
| Rate limiting en backend | ❌ Pendiente |

---

## 11. Rendimiento

| Punto | Estado |
|-------|--------|
| Deduplicación O(n) via `Set` | ✅ PlayersScreen |
| Debounce en búsqueda 300ms | ✅ TeamsScreen |
| SharedPreferences lazy init (getter memoizado) | ✅ |
| `Future.wait()` para requests paralelos | ✅ ListasScreen |
| Timeout en requests HTTP | ❌ Ver C-2 |
| IndexedStack con 5 pantallas vivas | ⚠️ Ver Q-7 |
| `_fetchAllPages()` sin límite máximo de páginas | ⚠️ Loop teóricamente infinito si el backend no termina |

---

## 12. Tabla de prioridades

### Crítico
| ID | Issue | Archivo |
|----|-------|---------|
| C-1 | Verificar `is_current` en endpoint `/temporadas` live + actualizar docs | backend |
| C-2 | Agregar timeout a todos los `http.get()` | api_service.dart |
| C-3 | Sincronizar versión Android con pubspec.yaml | build.gradle.kts |
| C-4 | Manejo de error/retry en carga inicial de temporada | main.dart |

### Medio plazo
| ID | Issue | Archivo |
|----|-------|---------|
| Q-2 | Verificar y eliminar métodos legacy de CacheService | cache_service.dart |
| Q-6 | Corregir log de limpieza semanal (19h → 21h) | cache_service.dart |
| Q-8 | Unificar firma de `getTablas()` (int vs String) | i_api_service.dart |
| — | Caché para el JSON externo de ListasScreen | listas_screen.dart |
| — | Actualizar `endpoints_wordpress.txt` con código live actual | wordpress_files/ |
| — | Rate limiting en backend WordPress | functions.php |

### Largo plazo
| ID | Issue | Archivo |
|----|-------|---------|
| Q-1 | Completar Repository Pattern (Matches, Players, Teams) | repositories/ |
| Q-3 | Unificar nombres de campos en CacheService ('data' para todos) | cache_service.dart |
| Q-4 | Refactorizar `getEquipos()` para reusar helper de paginación | api_service.dart |
| Q-5 | Eliminar `SplashToMain` innecesario | main.dart |
| Q-7 | Evaluar PageView vs IndexedStack para uso de memoria | main.dart |
| — | Unit tests: PlayerFilterService, StandingsService, Jugador.fromJson | test/ |
| — | Widget tests con mocks de providers | test/ |
| — | Modelos tipados para goleadores, tablas, imbatibles | models/ |

---

## 13. Inventario de archivos

```
lib/
├── main.dart                          Navegación + splash + bootstrap (185 líneas)
├── theme.dart                         Colores y tema Material3 (36 líneas)
├── models/
│   ├── temporada.dart                 id, name, isCurrent (24 líneas)
│   ├── equipo.dart                    id, nombre, imagen, escudo, temporadas (35 líneas)
│   ├── partido.dart                   Partido con todos los campos del API (59 líneas)
│   └── jugador.dart                   Jugador unificado (69 líneas)
├── services/
│   ├── i_api_service.dart             Interface — 18 métodos (82 líneas)
│   ├── api_service.dart               Implementación HTTP (435 líneas)
│   ├── i_cache_service.dart           Interface — 20 métodos (38 líneas)
│   ├── cache_service.dart             Implementación SharedPreferences (403 líneas)
│   ├── partidos_cache.dart            Combina API + cache para partidos (37 líneas)
│   ├── remote_data_service.dart       JSON remoto (publicidades, listas) (114 líneas)
│   ├── player_filter_service.dart     Filtrado puro de jugadores (43 líneas)
│   └── standings_service.dart         Ordenamiento puro de posiciones (71 líneas)
├── repositories/
│   ├── api_repository.dart            Fetch-cache integrado por entidad (105 líneas)
│   └── cache_repository.dart          Gestión de caché (16 líneas)
├── providers/
│   ├── service_providers.dart         apiServiceProvider, cacheServiceProvider
│   ├── repository_providers.dart      apiRepositoryProvider, cacheRepositoryProvider
│   ├── temporadas_provider.dart       temporadasProvider, temporadaActualProvider
│   └── partidos_cache_provider.dart   partidosCacheProvider
├── screens/
│   ├── matches_screen.dart            Partidos jugados + programados, filtros
│   ├── match_detail_screen.dart       Detalle, alineaciones, goleadores
│   ├── standings_screen.dart          Tablas de posiciones por zona
│   ├── teams_screen.dart              Lista de equipos (actual + histórico)
│   ├── team_detail_screen.dart        Plantel + historial del equipo
│   ├── players_screen.dart            Jugadores (actual + histórico, lazy)
│   ├── player_detail_screen.dart      Stats + historial del jugador
│   ├── scorers_screen.dart            Tabla de goleadores
│   ├── imbatibles_screen.dart         Tabla de imbatibles
│   ├── listas_screen.dart             Lista espera + reserva
│   ├── more_screen.dart               Menú + opciones + caché
│   └── solicitud_cambio_webview.dart  WebView solicitud de cambio
├── widgets/
│   ├── player_pod.dart                Avatar con badges (goles, tarjetas, capitán)
│   ├── match_card.dart                Tarjeta de partido
│   ├── full_field_painter.dart        CustomPainter campo de fútbol
│   └── zocalo_publicitario.dart       Carrusel de publicidades
└── utils/
    ├── date_utils.dart                calcularEdad(), formatFechaNacimiento()
    ├── puntaje_utils.dart             formatearPuntaje()
    ├── posicion_utils.dart            posicionAbreviada(), posicionColor(), ordenPosiciones
    └── liga_utils.dart                prioridadLiga(), prioridadTitulo()

wordpress_files/
├── endpoints_wordpress.txt            Endpoints custom (functions.php) — DESACTUALIZADO
├── sportspress/                       Plugin SportsPress (datos deportivos)
└── sportspress-for-soccer/            Extension fútbol para SportsPress
```

---

## 14. Conclusión

La app está en buen estado para producción. La arquitectura es clara, la separación de responsabilidades es correcta, y el ciclo anterior de mejoras eliminó los bugs más graves.

**Fortalezas:**
- Arquitectura limpia con DI via Riverpod
- Caché robusta con TTL, limpieza automática y claves por entidad/temporada
- Lógica de negocio pura y testeable (PlayerFilterService, StandingsService, utils)
- Modelos tipados con fromJson/toJson
- UI bien estructurada con paginación e infinite scroll
- Integración backend completa (14+ endpoints) con caché de doble nivel

**Principal deuda técnica:**
1. Timeout en requests HTTP (C-2) — fácil de implementar, alto impacto en UX
2. Manejo de error en carga inicial (C-4) — evita loading infinito ante fallo de red
3. Sincronizar versión Android (C-3) — dos líneas de cambio
4. Tests unitarios — la arquitectura ya los permite, falta implementarlos

El backend (WordPress + SportsPress + plugin custom) es sólido y el diseño de endpoints es adecuado para el dominio. El principal riesgo operativo es la caché agresiva del servidor (30 días de TTL) que puede retrasar la visibilidad de cambios en producción ante datos que cambian frecuentemente.
