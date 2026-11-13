/// An error returned by the Land Fee API, or a transport failure.
class ApiException implements Exception {
  ApiException(this.message, {this.code, this.statusCode});

  /// Human readable message, already localised by the server (English).
  final String message;

  /// Machine code from the API envelope, e.g. `forbidden`, `invalid_credentials`.
  final String? code;

  /// HTTP status, when the failure came from a response.
  final int? statusCode;

  /// The session is gone or expired; the app should send the user back to login.
  bool get isUnauthorized => statusCode == 401;

  @override
  String toString() => message;
}
