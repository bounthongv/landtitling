/// Build-time configuration for the manager app.
///
/// The API base URL is injected with `--dart-define=API_BASE=...`. On the
/// Android emulator the host machine is `10.0.2.2`, not `localhost`.
class AppConfig {
  const AppConfig._();

  static const String apiBase = String.fromEnvironment(
    'API_BASE',
    defaultValue: 'https://landtax.apis.com.la/api/v1',
  );

  static const String appNameLo = 'ບັກຊໍາລະທໍານຽມ';
  static const String appNameEn = 'Land Tax Manager';
}
