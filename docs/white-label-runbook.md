# White-Label Runbook — Entre Redes Flutter App

This document is the authoritative reference for onboarding new tenants and building per-client APKs/IPAs.

---

## 1. How to Create a New Tenant

A new tenant requires: one Dart config file, one Dart entry point, one Android product flavor, and one iOS scheme (manual Xcode step). No existing shared `lib/` source files need to be edited.

### Step 1 — Create `lib/config/tenants/<tenant_id>.dart`

Copy `lib/config/tenants/facundo.dart` as a starting template.

Replace all placeholder values:

| Field | Description |
|-------|-------------|
| `tenantId` | Stable machine-readable slug, e.g. `"acb"`. Used in build commands. |
| `appName` | Human-readable league name shown in the UI. |
| `apiBaseUrl` | Full HTTPS base URL of the tenant's WordPress REST API. No trailing slash. |
| `mediaBaseUrl` | Base URL for the tenant's `wp-content/uploads/media/` directory. |
| `colors.primary` | Main brand color (hex). |
| `colors.accent` | Secondary/accent color (hex). |
| `colors.splashBackground` | Splash screen background color (usually same as primary). |
| `features.waitingLists` | `true` if the league uses the waiting-list feature. Requires `appsScriptUrl`. |
| `features.newsTab` | `true` to show the Noticias tab in the main navigation. |
| `features.ads` | `true` to enable banner ads via the zócalo widget. |
| `integrations.appsScriptUrl` | Google Apps Script URL for live waiting-list JSON. Required when `waitingLists=true`. |
| `logoAsset` | Asset path for the tenant logo, e.g. `assets/images/<tenant_id>/app_logo.png`. |
| `documents.*` | Optional PDF/webview URLs for regulations, yearbooks, etc. Leave null to hide entries. |
| `androidStoreUrl` | Play Store listing URL for the forced-update dialog. |
| `iosStoreUrl` | App Store listing URL for the forced-update dialog. |

**Validation rule**: if `waitingLists=true` and `appsScriptUrl` is null or empty, the app will crash at startup with a `StateError`. This is by design — it prevents silent failures in production.

### Step 2 — Create `lib/main_<tenant_id>.dart`

```dart
import 'bootstrap.dart';
import 'config/tenants/<tenant_id>.dart';

void main() => bootstrap(<tenant_id>Tenant);
```

### Step 3 — Add Android Flavor

In `android/app/build.gradle.kts`, inside the `productFlavors` block, add:

```kotlin
create("<tenant_id>") {
    dimension = "tenant"
    applicationId = "com.<tenant_id>.ligafutbol"  // unique bundle id
    resValue("string", "app_name", "<League Name>")
}
```

`AndroidManifest.xml` already uses `@string/app_name` — no further change needed.

### Step 4 — Add iOS Scheme (manual Xcode step)

iOS flavor setup cannot be automated via code — it must be done manually in Xcode. See the comment block at the top of `ios/Runner/Info.plist` for step-by-step instructions.

Summary:
1. Open `ios/Runner.xcworkspace` in Xcode
2. Duplicate the Runner scheme and name it `<tenant_id>`
3. Set `PRODUCT_BUNDLE_IDENTIFIER` and `DISPLAY_NAME` in scheme Build Settings
4. Create `ios/Flutter/<tenant_id>.xcconfig` pointing to `lib/main_<tenant_id>.dart`
5. If push notifications are per-tenant, add the correct `GoogleService-Info.plist`

### Step 5 — Add Tenant Logo

Place the logo at `assets/images/<tenant_id>/app_logo.png`. The directory is pre-registered in `pubspec.yaml` — no pubspec edit needed for a new tenant (as long as the directory follows the `assets/images/<tenant_id>/` pattern).

If you add a new directory with a different name, register it in `pubspec.yaml` under `flutter.assets`.

### Step 6 — Generate Launcher Icons

```bash
flutter pub run flutter_launcher_icons:main --flavor <tenant_id>
```

Add a `flutter_launcher_icons_<tenant_id>` block to `pubspec.yaml` before running this command (see existing `facundo` block as a template).

---

## 2. How to Build for a Specific Tenant

### Run locally (development)

```bash
# Marianista (tenant zero)
flutter run --flavor marianista -t lib/main_marianista.dart

# Facundo
flutter run --flavor facundo -t lib/main_facundo.dart
```

### Build release APK (Android)

```bash
# Marianista
flutter build apk --flavor marianista -t lib/main_marianista.dart --release

# Facundo
flutter build apk --flavor facundo -t lib/main_facundo.dart --release
```

### Build release IPA (iOS — after Xcode scheme is set up)

```bash
# Marianista
flutter build ipa --flavor marianista -t lib/main_marianista.dart

# Facundo
flutter build ipa --flavor facundo -t lib/main_facundo.dart
```

### Without flavor (default, for development only)

```bash
flutter run
```

Bare `flutter run` (no `--flavor`) uses `lib/main.dart` which bootstraps `marianistaTenant`. This is intentional — it allows quick iteration without specifying a flavor. Do not publish this build to any store.

---

## 3. API Contract — Minimum Backend Surface

Every tenant's WordPress backend must expose the following REST endpoints under the plugin base path `/wp-json/entre-redes/v1`. Responses use JSON; pagination uses the `x-wp-total` response header to communicate the total item count.

### Required Endpoints

| Endpoint | Key Parameters | Notes |
|----------|---------------|-------|
| `GET /partidos` | `fecha`, `liga`, `temporada`, `equipo`, `page`, `per_page` | Played matches |
| `GET /partidos-programados` | `page`, `per_page` | Upcoming matches |
| `GET /ligas` | `temporada` | Leagues for a season |
| `GET /temporadas` | — | All seasons |
| `GET /zonas` | `liga` | Zones within a league |
| `GET /equipos` | `liga`, `temporada`, `page`, `per_page` | Teams |
| `GET /tablas` | `temporada`, `zona`, `search`, `page`, `per_page` | Standings table |
| `GET /jugadores` | `temporada`, `liga`, `zona`, `equipo_id`, `search`, `page`, `per_page` | Players list |
| `GET /jugadores/{id}` | — | Single player detail |
| `GET /partidos-jugador` | `jugador`, `page`, `per_page` | Match history for a player |
| `GET /goleadores` | `partido_id` | Scorers for a match |
| `GET /tabla-goleadores` | `id_temporada`, `id_liga`, `page`, `per_page` | Top scorers table |
| `GET /partidos-equipo` | `equipo` or `equipo_id` | Match history for a team |
| `GET /tabla-imbatibles` | `temporada`, `page`, `per_page` | Goalkeepers clean-sheet table |

### Pagination Header

Every paginated endpoint MUST return:

```
x-wp-total: <total_item_count>
```

The app uses this header to determine when to stop infinite-scroll pagination.

### Media JSON Files (under `mediaBaseUrl`)

The app fetches three JSON files from `{mediaBaseUrl}/`:

| File | Purpose |
|------|---------|
| `publicidades.json` | Ad images for the zócalo banner. Array of `{imageUrl, link}`. |
| `listas_jugadores.json` | Waiting-list player IDs. Object with `espera`, `reserva`, `no_inscriptos` arrays. Required when `waitingLists=true`. |
| `configuraciones.json` | Remote app config: `minAppVersion`, `maintenanceMessage`, `seasonAnnouncement`. |

### Known Backend Gotcha

`get_page_by_title()` is deprecated in WordPress 6.2+. The Entre Redes plugin may trigger a deprecation warning in server logs. This is tracked as a separate backend fix and does not affect app functionality.

---

## 4. Known Limitations (August 2026 Deadline)

| Limitation | Impact | Planned Fix |
|------------|--------|-------------|
| Firebase project is shared between Marianista and Facundo | Push notifications from Firebase are sent to both tenants' users | Separate Firebase projects; create per-tenant `GoogleService-Info.plist` and `google-services.json` |
| iOS scheme must be added manually in Xcode | Cannot be scripted or committed as code | Document in this runbook; consider `flutter_flavorizr` after 2 tenants stabilize |
| `flutter_launcher_icons` per-flavor config requires manual pubspec entries | Each new tenant needs a pubspec block added | Accepted cost; only 2 tenants for August |
| Facundo logo is a 1×1 placeholder PNG | Will show a blank icon until real logo is provided | Client must supply a 1024×1024 PNG |
| Facundo `apiBaseUrl` and `androidStoreUrl`/`iosStoreUrl` are placeholders | Facundo flavor cannot connect to a real backend until client provides URLs | Replace values in `lib/config/tenants/facundo.dart` once client provisions WordPress |

---

## 5. Onboarding Facundo — What to Replace

All placeholder values are in `lib/config/tenants/facundo.dart`. Search for `TODO` comments.

| What to replace | Where | Notes |
|----------------|-------|-------|
| `apiBaseUrl` | `facundo.dart` | Client must replicate the WordPress plugin from Marianista's install |
| `mediaBaseUrl` | `facundo.dart` | Must expose `publicidades.json` and `configuraciones.json` |
| `appName` | `facundo.dart` | Confirm commercial league name with client |
| `colors.primary` / `colors.accent` | `facundo.dart` | Confirm brand colors with client |
| Logo asset | `assets/images/facundo/app_logo.png` | Replace placeholder PNG with actual 1024×1024 logo |
| `androidStoreUrl` | `facundo.dart` | Set after the app is published on Play Store |
| `iosStoreUrl` | `facundo.dart` | Set after the app is published on App Store |
| Android application ID | `android/app/build.gradle.kts` (facundo flavor) | Confirm `com.facundo.ligafutbol` or choose a real reverse-domain ID |
| iOS bundle identifier | Xcode scheme for `facundo` | Must match what's registered in App Store Connect |
| FCM topic / Firebase project | `ios/Runner/<flavor>/GoogleService-Info.plist` | Only needed if push notifications are separate per tenant |

### Validation checklist before Facundo goes live

- [ ] `apiBaseUrl` points to a live WordPress REST endpoint returning valid JSON from `/temporadas`
- [ ] `mediaBaseUrl/configuraciones.json` is reachable and returns valid JSON
- [ ] Real logo placed at `assets/images/facundo/app_logo.png`
- [ ] `flutter pub run flutter_launcher_icons:main --flavor facundo` run successfully
- [ ] Android `applicationId` registered on Google Play Console
- [ ] iOS bundle ID registered in App Store Connect
- [ ] iOS scheme created in Xcode with correct `PRODUCT_BUNDLE_IDENTIFIER`
- [ ] `flutter build apk --flavor facundo -t lib/main_facundo.dart --release` exits 0
- [ ] `flutter build ipa --flavor facundo -t lib/main_facundo.dart` exits 0
- [ ] No Marianista URLs appear in Facundo's network traffic (confirm in Charles/Proxyman)
- [ ] No Listas tab visible when `waitingLists=false`
- [ ] No zócalo ads visible when `ads=false`
