import 'package:flutter_riverpod/flutter_riverpod.dart';

import '../services/credencial_photo_store.dart';
import '../services/credencial_repository.dart';

/// Provides a single [CredencialPhotoStore] instance for the lifetime of the
/// enclosing [ProviderScope] (design D5: photo bytes live in the app support
/// directory, separate from secure storage).
final credencialPhotoStoreProvider = Provider<CredencialPhotoStore>((ref) {
  return CredencialPhotoStore();
});

/// Provides the [CredencialRepository] (secure-storage JSON cache + photo
/// verification).
///
/// Deliberately kept in its own file with NO dependency on
/// `prode_providers.dart`: `clear()` must be safely callable from
/// [ProdeAuthController.onLoggedOut] (wired in `prode_providers.dart`), and a
/// two-way import between that file and `credencial_providers.dart` (which
/// itself needs `prodeApiServiceProvider`) would otherwise create a
/// same-library import cycle for no real dependency reason — the repository
/// genuinely never needs tenant config or Prode services.
final credencialRepositoryProvider = Provider<CredencialRepository>((ref) {
  return CredencialRepository(
    photoStore: ref.watch(credencialPhotoStoreProvider),
  );
});
