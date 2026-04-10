/// Configuración remota de la app, leída desde `configuraciones.json`.
/// Todos los campos son opcionales con valores por defecto seguros.
class AppConfig {
  final String playersCacheVersion;
  final String standingsCacheVersion;
  final String scorersCacheVersion;
  final String imbatiblesCacheVersion;
  final String? maintenanceMessage;
  final String? seasonAnnouncement;
  final String? minAppVersion;
  final int? cacheTtlDays;
  final String? appBarLogoUrl;
  final List<String>? ligasOrden;

  const AppConfig({
    this.playersCacheVersion = '1',
    this.standingsCacheVersion = '1',
    this.scorersCacheVersion = '1',
    this.imbatiblesCacheVersion = '1',
    this.maintenanceMessage,
    this.seasonAnnouncement,
    this.minAppVersion,
    this.cacheTtlDays,
    this.appBarLogoUrl,
    this.ligasOrden,
  });

  factory AppConfig.fromJson(Map<String, dynamic> json) {
    return AppConfig(
      playersCacheVersion: json['players_cache_version']?.toString() ?? '1',
      standingsCacheVersion: json['standings_cache_version']?.toString() ?? '1',
      scorersCacheVersion: json['scorers_cache_version']?.toString() ?? '1',
      imbatiblesCacheVersion: json['imbatibles_cache_version']?.toString() ?? '1',
      maintenanceMessage: json['maintenance_message'] as String?,
      seasonAnnouncement: json['season_announcement'] as String?,
      minAppVersion: json['min_app_version'] as String?,
      cacheTtlDays: json['cache_ttl_days'] as int?,
      appBarLogoUrl: json['app_bar_logo_url'] as String?,
      ligasOrden: (json['ligas_orden'] as List<dynamic>?)?.map((e) => e.toString()).toList(),
    );
  }
}
