# Tax Manager — TWA (PWA packaged as an Android APK)

The manager "app" is the existing PWA at `/admin/` wrapped in a **Trusted Web
Activity**. There is no separate app code: the APK loads
`https://landtax.apis.com.la/admin/monitor.php` full-screen, so any change to the
web UI (reports, filters, figures) reaches managers instantly with no rebuild.

- **APK (published):** `public/download/tax-manager.apk` → `https://landtax.apis.com.la/download/tax-manager.apk`
- **Package id:** `la.apis.landtax.manager`
- **Digital Asset Links:** `public/.well-known/assetlinks.json` (served at the
  domain root; it is what removes the Chrome address bar). If it is missing or the
  fingerprint is wrong, the APK still works but opens inside a Custom Tab with a
  browser bar.
- **minSdk:** 24 (androidbrowserhelper requires it).

## Rebuild

Tooling: Node 18+, JDK 17, Android SDK with `platforms;android-36`,
`build-tools;36.1.0`. bubblewrap needs `sdk/bin` or `sdk/tools` to exist (a
junction to `cmdline-tools/latest/bin` works).

```powershell
# One-time config (bubblewrap skips its interactive setup when this exists):
#   %USERPROFILE%\.bubblewrap\config.json
#   { "jdkPath": "D:\\jdk17", "androidSdkPath": "D:\\AndroidSdk" }
# Use a JDK path with NO spaces: bubblewrap mis-quotes "C:\\Program Files\\...".
# (D:\\jdk17 is a junction to the real JDK 17.)

cd twa/manager
$env:BUBBLEWRAP_KEYSTORE_PASSWORD = '<keystore password>'
$env:BUBBLEWRAP_KEY_PASSWORD      = '<key password>'
bubblewrap update --skipVersionUpgrade
$env:PATH = "D:\jdk17\bin;" + $env:PATH   # so the AAB step finds jarsigner
bubblewrap build --skipPwaValidation --skipVersionUpgrade
```

`app-release-signed.apk` is the installable file; copy it to
`public/download/tax-manager.apk` and commit.

## Signing key (important)

`twa/manager/android.keystore` (alias `android`) is **git-ignored** — keep it
backed up. Rebuilding with a different key changes the certificate fingerprint,
which must then be updated in `public/.well-known/assetlinks.json` or the app
falls back to showing the browser bar. Current SHA-256:

```
65:6B:DB:E1:E4:B9:29:15:B1:89:34:13:77:4B:01:25:46:E3:4E:95:A9:F3:25:2D:BF:F4:8F:EB:AD:AB:87:55
```

Get it again with:

```powershell
# bubblewrap prints it at the end of a build; or:
& 'D:\jdk17\bin\java.exe' -jar 'D:\AndroidSdk\build-tools\36.1.0\lib\apksigner.jar' verify --print-certs twa/manager/app-release-signed.apk
```

## Local (non-production) URLs

`twa-manifest.json` points at the live domain. To test against a staging host,
edit `host`/`startUrl`/`fullScopeUrl`/`webManifestUrl`/`iconUrl`, add that host's
`assetlinks.json`, and rebuild.