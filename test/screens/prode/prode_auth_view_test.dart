import 'package:flutter/material.dart';
import 'package:flutter_riverpod/flutter_riverpod.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:torneo_futbol_app/config/prode_auth_config.dart';
import 'package:torneo_futbol_app/providers/prode_providers.dart';
import 'package:torneo_futbol_app/screens/prode/prode_auth_view.dart';
import 'package:torneo_futbol_app/screens/prode/prode_fixtures_screen.dart';
import 'package:torneo_futbol_app/services/prode_api_service.dart';
import 'package:torneo_futbol_app/services/prode_auth_repository.dart';
import 'package:torneo_futbol_app/services/prode_auth_state.dart';
import 'package:torneo_futbol_app/services/prode_fixtures_controller.dart';
import 'package:torneo_futbol_app/services/prode_history_controller.dart';
import 'package:torneo_futbol_app/services/prode_ranking_controller.dart';

// ---------------------------------------------------------------------------
// Fakes / stubs for the Authenticated arm's default destination
// (ProdeChamiScreen) — no network, fixed initial states, mirrors the
// convention already used in prode_fixtures_screen_test.dart /
// prode_history_list_test.dart.
// ---------------------------------------------------------------------------

class _FakeApiService extends ProdeApiService {
  _FakeApiService()
      : super(
          config: const ProdeAuthConfig(
            prodeApiBaseUrl: 'https://nowhere.test',
            googleWebClientId: 'test',
            appleTeamId: 'TEST',
          ),
          authRepo: ProdeAuthRepository(),
        );
}

class _StubHistoryController extends ProdeHistoryController {
  _StubHistoryController(ProdeHistoryState initialState) : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}

  @override
  Future<void> loadMore() async {}
}

class _StubRankingController extends ProdeRankingController {
  _StubRankingController(ProdeRankingState initialState) : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}
}

class _StubFechaRankingController extends ProdeFechaRankingController {
  _StubFechaRankingController(ProdeRankingState initialState) : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}
}

class _StubFixturesController extends ProdeFixturesController {
  _StubFixturesController(ProdeFixturesState initialState) : super(_FakeApiService()) {
    state = initialState;
  }

  @override
  Future<void> load() async {}

  @override
  Future<void> refresh() async {}

  @override
  Future<void> selectFecha(int fechaId) async {}
}

/// Pumps ProdeAuthView in the Authenticated state with NO authenticatedBuilder
/// (the default), wrapped in a [ProviderScope] with every controller
/// [ProdeChamiScreen] reads stubbed to a fixed, no-network state.
Future<void> _pumpAuthenticatedDefault(
  WidgetTester tester, {
  required bool stale,
  required VoidCallback onLogout,
}) async {
  await tester.pumpWidget(
    ProviderScope(
      overrides: [
        prodeApiServiceProvider.overrideWithValue(_FakeApiService()),
        prodeHistoryControllerProvider.overrideWith(
          (ref) => _StubHistoryController(
            const ProdeHistoryState(phase: ProdeHistoryPhase.ready, items: []),
          ),
        ),
        prodeRankingControllerProvider.overrideWith(
          (ref) => _StubRankingController(const ProdeRankingLoading()),
        ),
        prodeFechaRankingControllerProvider.overrideWith(
          (ref) => _StubFechaRankingController(const ProdeRankingLoading()),
        ),
        prodeFixturesControllerProvider.overrideWith(
          (ref) => _StubFixturesController(const ProdeFixturesEmpty()),
        ),
      ],
      child: MaterialApp(
        home: Scaffold(
          body: ProdeAuthView(
            state: ProdeAuthAuthenticated(
              user: const ProdeUser(userId: 1, playerId: 2, name: 'Ana', sessionVersion: 1),
              stale: stale,
            ),
            onLogout: onLogout,
            onRetry: () {},
            onGoogleSignIn: () {},
            onAppleSignIn: null,
            onConfirmDni: (_) async => null,
          ),
        ),
      ),
    ),
  );
  await tester.pump(); // settle initState microtasks (guarded, so no real load fires)
}

void main() {
  // Pumps ProdeAuthView with [state] and returns counters for the callbacks so
  // tests can assert which action a given state's button triggers.
  Future<({int logout, int retry})> pumpView(
    WidgetTester tester,
    ProdeAuthState state, {
    VoidCallback? onTapAction,
  }) async {
    var logout = 0;
    var retry = 0;
    await tester.pumpWidget(
      MaterialApp(
        home: Scaffold(
          body: ProdeAuthView(
            state: state,
            onLogout: () => logout++,
            onRetry: () => retry++,
            onGoogleSignIn: () {},
            onAppleSignIn: null,
            onConfirmDni: (_) async => null,
          ),
        ),
      ),
    );
    return (logout: logout, retry: retry);
  }

  group('ProdeAuthView state routing', () {
    testWidgets('Hydrating shows a loading indicator', (tester) async {
      await pumpView(tester, const ProdeAuthHydrating());
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    testWidgets('Authenticating shows a loading indicator', (tester) async {
      await pumpView(tester, const ProdeAuthAuthenticating(provider: 'google'));
      expect(find.byType(CircularProgressIndicator), findsOneWidget);
    });

    // NOTE: the three Authenticated-arm tests that previously asserted on
    // _ProdeHome copy ('¡Hola, Ana!', 'Sincronizando tus datos…', 'Cerrar sesión')
    // were removed here (B-6); those scenarios stay covered by the
    // ProdeFixturesScreen widget tests in prode_fixtures_screen_test.dart
    // (B-4). What follows instead is specific to ProdeAuthView's OWN Authenticated
    // arm — the `authenticatedBuilder != null ? authenticatedBuilder!(stale,
    // onLogout) : ProdeChamiScreen(stale: stale, onLogout: onLogout)` branch —
    // which had zero coverage: neither branch, nor whether the arguments each
    // one receives are the right ones in the right order.
    group('Authenticated arm routing (authenticatedBuilder)', () {
      testWidgets(
          'default (no builder) renders ProdeChamiScreen with the given stale flag',
          (tester) async {
        await _pumpAuthenticatedDefault(tester, stale: true, onLogout: () {});

        final chami = tester.widget<ProdeChamiScreen>(find.byType(ProdeChamiScreen));
        expect(chami.stale, isTrue);
        // Cross-check via the UI itself, not just the widget's field: the
        // stale banner only renders when ProdeChamiScreen actually received
        // stale=true.
        expect(find.text('Sincronizando tus datos…'), findsOneWidget);
      });

      testWidgets('default (no builder) forwards stale=false too (no stray banner)',
          (tester) async {
        await _pumpAuthenticatedDefault(tester, stale: false, onLogout: () {});

        final chami = tester.widget<ProdeChamiScreen>(find.byType(ProdeChamiScreen));
        expect(chami.stale, isFalse);
        expect(find.text('Sincronizando tus datos…'), findsNothing);
      });

      testWidgets(
          'default onLogout reaches the callback ProdeAuthView was constructed with',
          (tester) async {
        var logoutCalls = 0;
        await _pumpAuthenticatedDefault(tester, stale: false, onLogout: () => logoutCalls++);

        // Segment 0 (Anteriores) is the default and has no logout affordance;
        // "A Jugarse" (ProdeFixturesScreen, Empty state) does.
        await tester.tap(find.byKey(const Key('prode_segment_1')));
        await tester.pump();

        await tester.tap(find.text('Cerrar sesión'));
        expect(logoutCalls, equals(1));
      });

      testWidgets('supplied builder receives the correct stale value', (tester) async {
        bool? builderStale;
        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: ProdeAuthView(
                state: const ProdeAuthAuthenticated(
                  user: ProdeUser(userId: 1, playerId: 2, name: 'Ana', sessionVersion: 1),
                  stale: true,
                ),
                onLogout: () {},
                onRetry: () {},
                onGoogleSignIn: () {},
                onAppleSignIn: null,
                onConfirmDni: (_) async => null,
                authenticatedBuilder: (stale, onLogout) {
                  builderStale = stale;
                  return Text('builder stale=$stale');
                },
              ),
            ),
          ),
        );

        expect(builderStale, isTrue);
        expect(find.text('builder stale=true'), findsOneWidget);
        expect(find.byType(ProdeChamiScreen), findsNothing);
      });

      testWidgets(
          'supplied builder receives the SAME onLogout ProdeAuthView was constructed '
          'with — not onRetry or any other callback', (tester) async {
        var logoutCalls = 0;
        var retryCalls = 0;

        await tester.pumpWidget(
          MaterialApp(
            home: Scaffold(
              body: ProdeAuthView(
                state: const ProdeAuthAuthenticated(
                  user: ProdeUser(userId: 1, playerId: 2, name: 'Ana', sessionVersion: 1),
                ),
                onLogout: () => logoutCalls++,
                onRetry: () => retryCalls++,
                onGoogleSignIn: () {},
                onAppleSignIn: null,
                onConfirmDni: (_) async => null,
                authenticatedBuilder: (stale, onLogout) => ElevatedButton(
                  onPressed: onLogout,
                  child: const Text('builder logout button'),
                ),
              ),
            ),
          ),
        );

        await tester.tap(find.text('builder logout button'));

        // The assertion that catches a swap: if the builder had been handed
        // onRetry instead of onLogout, this would fire retryCalls instead.
        expect(logoutCalls, equals(1));
        expect(retryCalls, equals(0));
      });
    });

    testWidgets('Unauthenticated shows Google; Apple hidden when unavailable',
        (tester) async {
      // pumpView passes onAppleSignIn: null → Apple button hidden.
      await pumpView(tester, const ProdeAuthUnauthenticated());
      expect(find.text('Sumate al Prode'), findsOneWidget);
      expect(find.text('Continuar con Google'), findsOneWidget);
      expect(find.text('Continuar con Apple'), findsNothing);
    });

    testWidgets('Apple button shows and triggers onAppleSignIn when available',
        (tester) async {
      var apple = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthUnauthenticated(),
              onLogout: () {},
              onRetry: () {},
              onGoogleSignIn: () {},
              onAppleSignIn: () => apple++,
              onConfirmDni: (_) async => null,
            ),
          ),
        ),
      );
      expect(find.text('Continuar con Apple'), findsOneWidget);
      await tester.tap(find.text('Continuar con Apple'));
      expect(apple, equals(1));
    });

    testWidgets('Continuar con Google triggers onGoogleSignIn', (tester) async {
      var google = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthUnauthenticated(),
              onLogout: () {},
              onRetry: () {},
              onGoogleSignIn: () => google++,
              onAppleSignIn: null,
              onConfirmDni: (_) async => null,
            ),
          ),
        ),
      );
      await tester.tap(find.text('Continuar con Google'));
      expect(google, equals(1));
    });

    testWidgets('NeedsDniConfirmation shows the DNI form (greeting + field + button)',
        (tester) async {
      await pumpView(
        tester,
        const ProdeAuthNeedsDniConfirmation(
          intentToken: 'tok',
          nameHint: 'Ana',
        ),
      );
      expect(find.text('¡Hola, Ana!'), findsOneWidget);
      expect(find.widgetWithText(TextField, 'DNI'), findsOneWidget);
      expect(find.widgetWithText(ElevatedButton, 'Confirmar'), findsOneWidget);
    });

    testWidgets('submitting a DNI calls onConfirmDni and shows the returned error inline',
        (tester) async {
      String? submitted;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthNeedsDniConfirmation(intentToken: 'tok'),
              onLogout: () {},
              onRetry: () {},
              onGoogleSignIn: () {},
              onAppleSignIn: null,
              onConfirmDni: (dni) async {
                submitted = dni;
                return 'Ese DNI no figura en el padrón.';
              },
            ),
          ),
        ),
      );

      await tester.enterText(find.byType(TextField), '12345678');
      await tester.tap(find.widgetWithText(ElevatedButton, 'Confirmar'));
      await tester.pumpAndSettle();

      expect(submitted, equals('12345678'));
      expect(find.text('Ese DNI no figura en el padrón.'), findsOneWidget);
    });

    testWidgets('empty DNI shows a validation message and does not call onConfirmDni',
        (tester) async {
      var called = false;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthNeedsDniConfirmation(intentToken: 'tok'),
              onLogout: () {},
              onRetry: () {},
              onGoogleSignIn: () {},
              onAppleSignIn: null,
              onConfirmDni: (dni) async {
                called = true;
                return null;
              },
            ),
          ),
        ),
      );

      await tester.tap(find.widgetWithText(ElevatedButton, 'Confirmar'));
      await tester.pumpAndSettle();

      expect(called, isFalse);
      expect(find.text('Ingresá tu DNI.'), findsOneWidget);
    });

    testWidgets('Revoked shows the session-closed message and re-login CTA',
        (tester) async {
      var logout = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              state: const ProdeAuthRevoked(reason: 'session_revoked'),
              onLogout: () => logout++,
              onRetry: () {},
              onGoogleSignIn: () {},
              onAppleSignIn: null,
              onConfirmDni: (_) async => null,
            ),
          ),
        ),
      );
      expect(find.text('Tu sesión se cerró'), findsOneWidget);
      await tester.tap(find.text('Volver a ingresar'));
      expect(logout, equals(1));
    });

    testWidgets('Error shows friendly copy (not the raw message) and Reintentar triggers onRetry',
        (tester) async {
      var retry = 0;
      await tester.pumpWidget(
        MaterialApp(
          home: Scaffold(
            body: ProdeAuthView(
              // message is a raw exception string — must NOT reach the UI.
              state: const ProdeAuthError(
                code: 'bootstrap_error',
                message: 'Bad state: PlatformException(...)',
              ),
              onLogout: () {},
              onRetry: () => retry++,
              onGoogleSignIn: () {},
              onAppleSignIn: null,
              onConfirmDni: (_) async => null,
            ),
          ),
        ),
      );
      expect(find.text('Algo salió mal'), findsOneWidget);
      expect(find.textContaining('PlatformException'), findsNothing);
      await tester.tap(find.text('Reintentar'));
      expect(retry, equals(1));
    });
  });
}
