# Entre Redes Flutter App — Professional Documentation

## 1. Project Objective

The Entre Redes Flutter App is designed for the "Entre Redes" sports league, providing real-time and historical information about matches, teams, players, standings, scorers, and more. It integrates with a WordPress backend via a custom API and offers features such as player lists, team details, match statistics, and advertising banners.

---

## 2. Project Structure

```
lib/
  main.dart
  theme.dart
  /screens
  /services
  /widgets
assets/
android/
ios/
macos/
web/
test/
```

---

## 3. App Entry Point

### `main.dart`
- **EntreRedesApp**: The root widget, sets up the `MaterialApp` with a custom theme and disables the debug banner.
- **SplashToMain**: Shows a splash screen briefly, then navigates to the main navigation.
- **MainNavigation**: Implements a bottom navigation bar with five main sections:
  - Matches
  - Standings
  - Teams
  - Players
  - More

---

## 4. Screens (`lib/screens/`)

Each screen is a major section of the app, usually corresponding to a tab or a detailed view.

| Screen/Class              | Purpose/Features                                                                 |
|--------------------------|---------------------------------------------------------------------------------|
| MatchesScreen            | Shows lists of played and upcoming matches, with filters and infinite scroll.     |
| MatchDetailScreen        | Detailed info about a match: summary, stats, lineups, player pods, ads.          |
| StandingsScreen          | Displays league standings (tables) for the selected season and zone.             |
| TeamsScreen              | Lists all teams for the current season and historical teams, with search.        |
| TeamDetailScreen         | Details for a specific team: roster, matches, navigation to player details.      |
| PlayersScreen            | Lists all players, with search and filtering.                                    |
| PlayerDetailScreen       | Detailed info about a player: stats, matches, seasons, team navigation.          |
| ScorersScreen            | Displays top scorers for the season.                                             |
| ImbatiblesScreen         | Shows goalkeepers with the most clean sheets.                                    |
| ListasScreen             | Manages and displays waiting and reserve lists for players.                      |
| MoreScreen               | Aggregates extra features and info, such as regulations and cache clearing.      |
| SolicitudCambioWebView   | WebView for player change requests.                                              |

---

## 5. Services (`lib/services/`)

These classes handle data fetching, caching, and remote operations.

| Service/Class         | Purpose/Responsibility                                                                 |
|----------------------|---------------------------------------------------------------------------------------|
| ApiService           | Centralizes all HTTP requests to the WordPress backend. Handles pagination, errors.    |
| CacheService         | Manages local caching using `SharedPreferences`. Caches players, teams, stats, etc.    |
| RemoteDataService    | Fetches remote JSON files for ads and player lists, provides live results utilities.   |

---

## 6. Widgets (`lib/widgets/`)

| Widget/Class           | Purpose/Features                                                      |
|-----------------------|-----------------------------------------------------------------------|
| ZocaloPublicitario    | Displays a persistent advertising banner at the bottom of the screen.  |

---

## 7. Theme (`lib/theme.dart`)

- **AppColors**: Centralizes color definitions for primary, background, text, and cards.
- **AppTheme**: Provides a `lightTheme` for the app, customizing colors, text styles, and Material 3 usage.

---

## 8. Data Flow and Architecture

- **Navigation**: Bottom navigation bar for main sections; stack-based navigation for details.
- **Data Fetching**: All data is fetched via `ApiService` or `RemoteDataService`, with caching via `CacheService`.
- **State Management**: Each screen manages its own state using `StatefulWidget` and `setState`.
- **Caching**: Aggressively caches data to reduce network usage and improve performance.
- **Ads**: Loads and displays advertising images from a remote JSON configuration.

---

## 9. Key Functions and Utilities

- **Filtering and Searching**: Most list screens (players, teams, matches) support search and filtering.
- **Pagination**: Infinite scroll for large lists, with page-based API requests.
- **Error Handling**: API and cache errors are caught and displayed to the user.
- **UI Components**: Custom widgets for player pods, team cards, match cards, and more.

---

## 10. Extensibility and Customization

- **Adding Screens**: New screens can be added by creating a new widget in `lib/screens` and adding it to the navigation.
- **API Expansion**: New endpoints can be integrated by adding methods to `ApiService`.
- **Theming**: Colors and styles are centralized in `theme.dart` for easy updates.
- **Caching**: Use or extend `CacheService` for new data types.

---

## 11. Best Practices Observed

- **Separation of concerns**: UI, data fetching, and caching are well separated.
- **Reusability**: Custom widgets and services are reusable across screens.
- **Performance**: Caching and pagination are used to optimize performance.
- **User Experience**: Loading indicators, error handling, and search/filtering improve UX.

---

## 12. Potential Improvements

- **State Management**: Consider using a state management solution (Provider, Riverpod, Bloc) for more complex state.
- **Testing**: Add unit and widget tests for critical logic and UI.
- **Documentation**: Add docstrings to all public methods and classes for even better maintainability.
- **Internationalization**: Extract hardcoded strings for easier localization.

---

## 13. Summary Table of Main Classes

| Class/Widget                | File                        | Purpose/Responsibility                                      |
|-----------------------------|-----------------------------|-------------------------------------------------------------|
| EntreRedesApp               | main.dart                   | App root, theme, navigation                                 |
| MainNavigation              | main.dart                   | Bottom navigation bar, main sections                        |
| MatchesScreen               | screens/matches_screen.dart | List of matches, filters, navigation                        |
| MatchDetailScreen           | screens/match_detail_screen.dart | Match details, stats, lineups, ads                     |
| StandingsScreen             | screens/standings_screen.dart | League standings, filtering, caching                    |
| TeamsScreen                 | screens/teams_screen.dart   | List of teams, search, navigation                           |
| TeamDetailScreen            | screens/team_detail_screen.dart | Team details, squad, matches, navigation                |
| PlayersScreen               | screens/players_screen.dart | List of players, search, navigation                         |
| PlayerDetailScreen          | screens/player_detail_screen.dart | Player details, stats, matches, navigation            |
| ScorersScreen               | screens/scorers_screen.dart | Top scorers list                                            |
| ImbatiblesScreen            | screens/imbatibles_screen.dart | Top goalkeepers list                                    |
| ListasScreen                | screens/listas_screen.dart  | Waiting/reserve player lists, filtering                     |
| MoreScreen                  | screens/more_screen.dart    | Extra features, info, cache clearing                        |
| ApiService                  | services/api_service.dart   | All API requests, data fetching                             |
| CacheService                | services/cache_service.dart | Local data caching, cache management                        |
| RemoteDataService           | services/remote_data_service.dart | Remote JSON fetch, live results, ad images           |
| ZocaloPublicitario          | widgets/zocalo_publicitario.dart | Advertising banner widget                              |

---

## 14. How to Extend or Maintain

- **To add a new feature**: Create a new screen or widget, add API methods if needed, and update navigation.
- **To update data sources**: Modify or extend `ApiService` and `RemoteDataService`.
- **To change the look and feel**: Update `theme.dart` and `AppColors`.
- **To manage caching**: Use or extend `CacheService` for new data types.

---

## 15. Contact & Onboarding

For onboarding new developers, ensure they:
- Have Flutter and Dart installed.
- Run `flutter pub get` to install dependencies.
- Review this documentation and the code structure.
- Start with `main.dart` and explore the navigation and services.

For questions or contributions, contact the project maintainer. 