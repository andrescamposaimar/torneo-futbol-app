import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../models/cambios_vigente.dart';
import 'cambios_api_service.dart';
import 'cambios_load_failure.dart';

sealed class CambiosVigentesState {
  const CambiosVigentesState();
}

final class CambiosVigentesLoading extends CambiosVigentesState {
  const CambiosVigentesLoading();
}

/// A failed load. [code] is safe to show as a short reference; [message] is
/// diagnostic only and must not be shown raw.
final class CambiosVigentesError extends CambiosVigentesState {
  final String code;
  final String message;

  const CambiosVigentesError({required this.code, required this.message});
}

final class CambiosVigentesLoaded extends CambiosVigentesState {
  final List<CambiosVigente> vigentes;
  const CambiosVigentesLoaded({required this.vigentes});
}

class CambiosVigentesController extends StateNotifier<CambiosVigentesState> {
  final CambiosApiService _service;

  CambiosVigentesController(this._service) : super(const CambiosVigentesLoading());

  Future<void> load() async {
    state = const CambiosVigentesLoading();
    await _fetch();
  }

  /// Pull-to-refresh: keeps the list on screen while re-fetching, and also
  /// when the re-fetch fails — a transient network error must not replace a
  /// list the user is reading with an error screen. Returns false in that
  /// case so the screen can say the list could not be updated.
  Future<bool> refresh() => _fetch(keepLoadedOnError: true);

  Future<bool> _fetch({bool keepLoadedOnError = false}) async {
    try {
      final result = await _service.fetchVigentes();
      if (!mounted) return false;
      state = CambiosVigentesLoaded(vigentes: result.vigentes);
      return true;
    } catch (e) {
      if (!mounted) return false;
      if (keepLoadedOnError && state is CambiosVigentesLoaded) return false;
      final failure = describeCambiosLoadFailure('vigentes', e);
      state = CambiosVigentesError(code: failure.code, message: failure.message);
      return false;
    }
  }
}
