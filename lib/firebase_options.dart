import 'package:firebase_core/firebase_core.dart' show FirebaseOptions;
import 'package:flutter/foundation.dart'
    show defaultTargetPlatform, TargetPlatform;

class DefaultFirebaseOptions {
  static FirebaseOptions get currentPlatform {
    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        return android;
      case TargetPlatform.iOS:
        return ios;
      default:
        throw UnsupportedError('Plataforma no soportada');
    }
  }

  static const FirebaseOptions android = FirebaseOptions(
    apiKey: 'AIzaSyASenQvrfp2KlNEMUKz8MCKNe7d60vwbjk',
    appId: '1:1056489511563:android:489b866d6ee1dd2652528e',
    messagingSenderId: '1056489511563',
    projectId: 'entreredes-app',
    storageBucket: 'entreredes-app.firebasestorage.app',
  );

  static const FirebaseOptions ios = FirebaseOptions(
    apiKey: 'AIzaSyDFYisjDdOSEgvI2CkTIb7HNkO-emivj6A',
    appId: '1:1056489511563:ios:8efe79533a0299c652528e',
    messagingSenderId: '1056489511563',
    projectId: 'entreredes-app',
    storageBucket: 'entreredes-app.firebasestorage.app',
    iosBundleId: 'com.andrescampos.torneochaminade',
  );
}
