/// Build-time configuration.
///
/// The API base URL is injected with `--dart-define=API_BASE=...` so the same
/// source builds against the live server, a staging server, or a local PHP
/// server. On the Android emulator the host machine is `10.0.2.2`, not
/// `localhost`.
class AppConfig {
  const AppConfig._();

  static const String apiBase = String.fromEnvironment(
    'API_BASE',
    defaultValue: 'https://landtax.apis.com.la/api/v1',
  );

  /// When true the app shows developer affordances (e.g. the OTP code that
  /// the server returns when OTP_DEBUG is enabled).
  static const bool debug = bool.fromEnvironment(
    'DEBUG_UI',
    defaultValue: false,
  );

  static const String appNameLo = 'ຊຳລະຄ່າທີ່ດິນ';
  static const String appNameEn = 'Land Tax Payment';

  static const String provinceLo = 'ແຂວງສະຫວັດນະຄອນ';
  static const String provinceEn = 'Savannakhet Province';

  /// How long an OTP stays valid, as told by the server.
  static const Duration otpTtl = Duration(minutes: 5);
}
