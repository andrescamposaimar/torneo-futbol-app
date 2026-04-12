import 'package:firebase_messaging/firebase_messaging.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Handler para mensajes que llegan con la app en background/terminated.
/// Debe ser top-level (no puede ser método de instancia).
@pragma('vm:entry-point')
Future<void> firebaseMessagingBackgroundHandler(RemoteMessage message) async {
  // Los notification messages se muestran automáticamente en background.
}

class NotificationService {
  final FirebaseMessaging _messaging = FirebaseMessaging.instance;
  final FlutterLocalNotificationsPlugin _localNotifications =
      FlutterLocalNotificationsPlugin();

  static const _channelId = 'entreredes_avisos';
  static const _channelName = 'Avisos del torneo';
  static const _prefsKey = 'notifications_enabled';
  static const _topic = 'avisos';

  Future<void> init() async {
    // Pedir permiso (iOS muestra el dialog nativo, Android 13+ también)
    await _messaging.requestPermission(
      alert: true,
      badge: true,
      sound: true,
    );

    // Configurar notificaciones locales para foreground
    const androidSettings =
        AndroidInitializationSettings('@mipmap/ic_launcher');
    const iosSettings = DarwinInitializationSettings();
    await _localNotifications.initialize(
      const InitializationSettings(android: androidSettings, iOS: iosSettings),
    );

    // Crear canal de Android (requerido para Android 8.0+)
    await _localNotifications
        .resolvePlatformSpecificImplementation<
            AndroidFlutterLocalNotificationsPlugin>()
        ?.createNotificationChannel(const AndroidNotificationChannel(
          _channelId,
          _channelName,
          importance: Importance.high,
        ));

    // Registrar handler de background
    FirebaseMessaging.onBackgroundMessage(firebaseMessagingBackgroundHandler);

    // Mostrar notificación cuando la app está en foreground
    FirebaseMessaging.onMessage.listen(_mostrarNotificacionForeground);

    // Suscribir al topic cuando el FCM token esté disponible.
    // En iOS el token llega async vía APNs — no bloqueamos, escuchamos.
    _messaging.onTokenRefresh.listen((fcmToken) {
      _suscribirSiHabilitado();
    });

    // Intentar suscribir ahora (funciona si el token ya está disponible)
    _suscribirSiHabilitado();
  }

  Future<void> _suscribirSiHabilitado() async {
    try {
      final token = await _messaging.getToken();
      if (token == null) return;
      final habilitadas = await isEnabled();
      if (habilitadas) {
        await _messaging.subscribeToTopic(_topic);
      }
      if (kDebugMode) print('[Notifications] FCM Token: $token');
    } catch (_) {
      // Token aún no disponible — onTokenRefresh lo reintentará
    }
  }

  Future<void> _mostrarNotificacionForeground(RemoteMessage message) async {
    final notification = message.notification;
    if (notification == null) return;

    await _localNotifications.show(
      notification.hashCode,
      notification.title,
      notification.body,
      const NotificationDetails(
        android: AndroidNotificationDetails(
          _channelId,
          _channelName,
          importance: Importance.high,
          priority: Priority.high,
        ),
        iOS: DarwinNotificationDetails(),
      ),
    );
  }

  Future<bool> isEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    return prefs.getBool(_prefsKey) ?? true;
  }

  Future<void> setEnabled(bool enabled) async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_prefsKey, enabled);
    try {
      if (enabled) {
        await _messaging.subscribeToTopic(_topic);
      } else {
        await _messaging.unsubscribeFromTopic(_topic);
      }
    } catch (_) {
      // Si el token no está listo, onTokenRefresh lo manejará
    }
  }
}
