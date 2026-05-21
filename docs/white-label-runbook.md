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

### Step 5 — Add Tenant Logos (two variants)

The tenant needs **two logo variants** under `assets/images/<tenant_id>/`:

| File | Size | Purpose |
|------|------|---------|
| `app_logo.png` | 1024×1024, full-bleed (content fills the canvas) | Source for launcher icons via `flutter_launcher_icons`. Should match the published store icon. |
| `app_logo_splash.png` | 1152×1152, content centered inside ~800×800 with transparent padding | Source for the Android 12+ adaptive splash icon. The circular mask of the new SplashScreen API would crop a full-bleed icon, hence the padded variant. |

The directory `assets/images/<tenant_id>/` is pre-registered in `pubspec.yaml` — no pubspec edit needed (as long as the path follows that pattern).

**Generate the padded splash variant from the full-bleed icon** (requires ImageMagick — `brew install imagemagick`):

```bash
magick assets/images/<tenant_id>/app_logo.png \
  -resize 800x800 -background none -gravity center \
  -extent 1152x1152 \
  assets/images/<tenant_id>/app_logo_splash.png
```

This scales the original into a 800×800 region centered inside a 1152×1152 transparent canvas, leaving ~176px of safe-zone padding on each side.

If you add a new asset directory with a different name, register it in `pubspec.yaml` under `flutter.assets`.

### Step 6 — Generate Launcher Icons

```bash
flutter pub run flutter_launcher_icons:main --flavor <tenant_id>
```

Add a `flutter_launcher_icons_<tenant_id>` block to `pubspec.yaml` before running this command (see existing `facundo` block as a template).

### Step 7 — Configure the Splash Screen

The splash is generated at build time by `flutter_native_splash` per-flavor. There is one YAML config file per tenant at the project root.

**Step 7.1 — Sample the icon's actual blue (avoid the brand-color mismatch class of bug)**

The `colors.primary` value in `TenantConfig` is what the UI uses (AppBar, buttons, etc.) but it does NOT necessarily match the blue inside the published store icon. The splash places the icon on a solid colored background, so any mismatch becomes visible immediately. Always sample the icon's actual background color:

```bash
magick assets/images/<tenant_id>/app_logo.png -format "%[pixel:p{10,10}]" info:
# Example output: srgb(0,87,169) → #0057A9
```

Use the sampled hex value as `colors.splashBackground` in the tenant config AND as `color` / `icon_background_color` in the YAML below. Leave `colors.primary` alone unless you are doing a deliberate brand-coherence refactor — changing it affects the whole UI and requires re-validation.

**Step 7.2 — Create `flutter_native_splash-<tenant_id>.yaml`** at the project root:

```yaml
flutter_native_splash:
  color: "#0057A9"                                         # sampled icon blue
  image: assets/images/<tenant_id>/app_logo.png            # full-bleed for legacy splash
  android: true
  ios: true
  web: false

  android_12:
    image: assets/images/<tenant_id>/app_logo_splash.png   # padded version
    icon_background_color: "#0057A9"
```

**Step 7.3 — Generate the splash assets**:

```bash
dart run flutter_native_splash:create --flavors <tenant_id>
```

This creates a flavor source set under `android/app/src/<tenant_id>/res/` (drawables + Android 12+ `values-v31/styles.xml`) and a suffixed iOS storyboard `LaunchScreen<TenantId>.storyboard` plus `LaunchImage<TenantId>.imageset/` and `LaunchBackground<TenantId>.imageset/` under `ios/Runner/Assets.xcassets/`.

**Step 7.4 — Wire the splash to iOS (one of two paths)**:

- **Tenant zero (Marianista) — uses the default `Runner` scheme**: Overwrite `ios/Runner/Base.lproj/LaunchScreen.storyboard` with the generated `LaunchScreen<TenantId>.storyboard` contents, then delete the suffixed `.storyboard` (it is not registered in `project.pbxproj` so it would not be compiled anyway). Keep the suffixed imagesets — future regenerations will refresh them automatically and the (now hacked) `LaunchScreen.storyboard` keeps referencing them by name. Self-healing.

  ```bash
  cp ios/Runner/Base.lproj/LaunchScreen<TenantId>.storyboard \
     ios/Runner/Base.lproj/LaunchScreen.storyboard
  rm ios/Runner/Base.lproj/LaunchScreen<TenantId>.storyboard
  rm -r ios/Runner/Assets.xcassets/LaunchImage.imageset   # remove the legacy default
  ```

- **New tenant with its own iOS scheme (e.g. Facundo)**: In Xcode, after Step 4 of section "How to Create a New Tenant", set the scheme's `Info.plist` (or use a per-scheme `Info-<TenantId>.plist`) so `UILaunchStoryboardName` points to `LaunchScreen<TenantId>`. No file copy needed — Xcode will compile the suffixed storyboard for that scheme directly.

**Step 7.5 — Verify**:

```bash
# Android
flutter run --flavor <tenant_id> -t lib/main_<tenant_id>.dart -d <android_device>
adb shell am force-stop <android_application_id>
adb shell am start -n <android_application_id>/.MainActivity   # forces cold start

# iOS
flutter run -d <ios_device> -t lib/main_<tenant_id>.dart
xcrun simctl terminate booted <ios_bundle_id>
xcrun simctl launch booted <ios_bundle_id>
```

Splash must show: tenant blue background + centered logo, no cropping on Android 12+, color matches the icon's blue.

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

### Build release IPA (iOS)

`--flavor` on iOS only applies when an Xcode scheme with that exact name exists. Marianista is tenant zero and keeps the default `Runner` scheme (no flavor renaming), so its IPA build must NOT pass `--flavor`. Facundo (and any future tenant) gets its own Xcode scheme during the manual setup, so `--flavor` works for those.

```bash
# Marianista — tenant zero, uses the default Runner scheme (no --flavor)
flutter build ipa -t lib/main_marianista.dart

# Facundo — requires the `facundo` Xcode scheme to exist (see "Add iOS Scheme")
flutter build ipa --flavor facundo -t lib/main_facundo.dart
```

Passing `--flavor marianista` to `flutter build ipa` fails with:

> The Xcode project does not define custom schemes. You cannot use the --flavor option.

That is by design — there is no `marianista` scheme to select.

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
| Marianista iOS splash uses the "option B" hack — `LaunchScreen.storyboard` was overwritten in place with the contents of the generated `LaunchScreenMarianista.storyboard`, which then was deleted | Re-running `dart run flutter_native_splash:create --flavors marianista` will recreate `LaunchScreenMarianista.storyboard`; the hacked `LaunchScreen.storyboard` is NOT regenerated by the plugin and must be re-overwritten manually if the storyboard structure changes (the imageset PNGs ARE auto-refreshed because the storyboard references them by name) | Only Marianista (tenant zero) needs this; new tenants get their own iOS scheme and use the suffixed storyboard directly via Xcode |
| `colors.primary` in Marianista config is `#005BBB`, the App Store icon uses `#0057A9` | Splash uses `#0057A9` to match the icon; AppBar / Material primary still uses `#005BBB`. The two are 18 units apart on the B channel and don't sit next to each other in the running app, so users don't notice — but the brand is technically inconsistent | Pending brand-coherence review with the Colegio. If `#0057A9` is the canonical brand value, update `colors.primary` and `theme.dart` in a separate refactor and re-validate the whole UI |

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
- [ ] Real logo placed at `assets/images/facundo/app_logo.png` (1024×1024 full-bleed)
- [ ] Padded splash logo generated at `assets/images/facundo/app_logo_splash.png` (1152×1152 with 800×800 content centered) — see Step 5
- [ ] `flutter pub run flutter_launcher_icons:main --flavor facundo` run successfully
- [ ] `flutter_native_splash-facundo.yaml` created with the sampled icon blue (see Step 7.1) and `dart run flutter_native_splash:create --flavors facundo` run successfully
- [ ] Android `applicationId` registered on Google Play Console
- [ ] iOS bundle ID registered in App Store Connect
- [ ] iOS scheme created in Xcode with correct `PRODUCT_BUNDLE_IDENTIFIER` and `UILaunchStoryboardName=LaunchScreenFacundo`
- [ ] `flutter build apk --flavor facundo -t lib/main_facundo.dart --release` exits 0
- [ ] `flutter build ipa --flavor facundo -t lib/main_facundo.dart` exits 0
- [ ] Splash on Android cold start shows tenant blue + uncropped logo on API 31+ (Android 12+ adaptive splash)
- [ ] Splash on iOS cold start shows tenant blue + logo, color matches the App Store icon
- [ ] No Marianista URLs appear in Facundo's network traffic (confirm in Charles/Proxyman)
- [ ] No Listas tab visible when `waitingLists=false`
- [ ] No zócalo ads visible when `ads=false`
