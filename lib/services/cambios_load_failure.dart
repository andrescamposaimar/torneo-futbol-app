import 'cambios_api_service.dart';

/// Machine-readable [code] plus a diagnostic [message] for a failed Cambios
/// load, so an error state can tell a server rejection from a malformed
/// payload from a transport failure instead of collapsing all three.
///
/// Same `code`/`message` contract as `ProdeRankingError`: [code] is stable and
/// safe to show as a short reference; [message] is diagnostic and MUST NOT be
/// shown raw in the UI (screens keep their own plain-Spanish copy).
typedef CambiosLoadFailure = ({String code, String message});

/// Classifies [error] thrown by a `CambiosApiService` read under [scope]
/// (e.g. `solicitudes`, `plantel`):
///   - [CambiosApiException] -> `<scope>_<statusCode>` (e.g. `solicitudes_500`)
///   - [CambiosMalformedResponseException] -> `<scope>_malformed`
///   - anything else (timeout, no connection, auth/refresh failure) ->
///     `<scope>_error`; the runtime type is kept in [message].
CambiosLoadFailure describeCambiosLoadFailure(String scope, Object error) {
  if (error is CambiosApiException) {
    // The one error that is NOT scope-specific: the comisión closed the
    // whole captain-facing side, so every scope must report the same stable
    // code and let the screen say the same thing. Scoping it would make
    // `plantel_503` and `solicitudes_503` two different-looking problems
    // with one cause.
    if (error.code == kCodigoDeshabilitado) {
      return (code: kCodigoDeshabilitado, message: error.mensajeDeshabilitado ?? '');
    }

    return (code: '${scope}_${error.statusCode}', message: error.toString());
  }
  if (error is CambiosMalformedResponseException) {
    return (code: '${scope}_malformed', message: error.toString());
  }
  return (code: '${scope}_error', message: '${error.runtimeType}: $error');
}
