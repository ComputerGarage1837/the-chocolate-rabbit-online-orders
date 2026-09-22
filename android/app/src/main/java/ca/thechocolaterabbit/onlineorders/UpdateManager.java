package ca.thechocolaterabbit.onlineorders;

import android.app.AlertDialog;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.provider.Settings;
import androidx.activity.ComponentActivity;
import androidx.core.content.FileProvider;
import org.json.JSONObject;
import java.io.ByteArrayOutputStream;
import java.io.File;
import java.io.FileInputStream;
import java.io.FileOutputStream;
import java.io.InputStream;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.security.MessageDigest;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;
import javax.net.ssl.HttpsURLConnection;

final class UpdateManager {
    private static final String MANIFEST = "https://raw.githubusercontent.com/ComputerGarage1837/the-chocolate-rabbit-online-orders/main/release/latest.json";
    private static final String PACKAGE = "ca.thechocolaterabbit.onlineorders";
    private static final String CERTIFICATE_SHA256 = "759a2c04ae79c2037eb82b7503d3bd29a79b61b23e008c2d49f0561947486e7b";
    private static final long MAX_APK = 80L * 1024 * 1024;
    private final ComponentActivity activity;
    private final ExecutorService network = Executors.newSingleThreadExecutor();
    private volatile boolean destroyed, busy;
    private boolean foreground;

    UpdateManager(ComponentActivity activity) { this.activity = activity; }
    void onResume() { foreground = true; }
    void checkManually() { check(true); }
    void destroy() { foreground = false; destroyed = true; network.shutdownNow(); }

    private void check(boolean manual) {
        if (destroyed || busy) return;
        busy = true;
        network.execute(() -> {
            try {
                JSONObject manifest = new JSONObject(fetch(MANIFEST, 32768));
                int code = manifest.getInt("versionCode"), minSdk = manifest.getInt("minSdk");
                String version = manifest.getString("versionName"), apkUrl = manifest.getString("apkUrl");
                String hash = manifest.getString("sha256").toLowerCase(), certificate = manifest.getString("certificateSha256").toLowerCase();
                long size = manifest.getLong("sizeBytes");
                if (code < 1 || minSdk < 26 || !version.matches("[0-9]+(?:\\.[0-9]+){1,3}") || !hash.matches("[a-f0-9]{64}")
                        || !CERTIFICATE_SHA256.equals(certificate) || size < 1 || size > MAX_APK
                        || !apkUrl.equals("https://github.com/ComputerGarage1837/the-chocolate-rabbit-online-orders/releases/download/v" + version + "/The-Chocolate-Rabbit-Online-Orders.apk")) throw new IllegalStateException();
                long installed = BuildConfig.VERSION_CODE;
                activity.runOnUiThread(() -> {
                    busy = false;
                    if (destroyed || !foreground) return;
                    if (code <= installed) notice("You’re up to date", "Version " + BuildConfig.VERSION_NAME + " is installed.");
                    else if (minSdk > Build.VERSION.SDK_INT) notice("Update unavailable", "The latest version needs a newer Android version.");
                    else new AlertDialog.Builder(activity).setTitle("Update available").setMessage("Version " + version + " is ready.\n\n" + manifest.optString("releaseNotes"))
                            .setPositiveButton("Download update", (d, w) -> download(manifest)).setNegativeButton("Later", null).show();
                });
            } catch (Exception error) {
                activity.runOnUiThread(() -> { busy = false; if (manual && foreground && !destroyed) notice("Couldn’t check for updates", "Check your connection and try again."); });
            }
        });
    }

    private void download(JSONObject manifest) {
        if (busy || destroyed) return;
        busy = true;
        AlertDialog progress = new AlertDialog.Builder(activity).setTitle("Downloading update").setMessage("Please keep the app open…").setCancelable(false).show();
        network.execute(() -> {
            File file = null;
            try {
                long expected = manifest.getLong("sizeBytes");
                File dir = new File(activity.getCacheDir(), "updates/install");
                if (!dir.isDirectory() && !dir.mkdirs()) throw new IllegalStateException();
                file = new File(dir, "TCR-Orders-" + manifest.getInt("versionCode") + ".apk");
                download(manifest.getString("apkUrl"), file, expected);
                if (!manifest.getString("sha256").equalsIgnoreCase(hash(file))) throw new IllegalStateException();
                verifyArchive(file, manifest);
                File ready = file;
                activity.runOnUiThread(() -> {
                    busy = false; progress.dismiss();
                    if (!destroyed && foreground) new AlertDialog.Builder(activity).setTitle("Ready to install")
                            .setMessage("Android will ask you to confirm the update. Your pairing and settings will be kept.")
                            .setPositiveButton("Install update", (d, w) -> install(ready)).setNegativeButton("Later", null).show();
                });
            } catch (Exception error) {
                if (file != null) file.delete();
                activity.runOnUiThread(() -> { busy = false; progress.dismiss(); if (!destroyed && foreground) notice("Update not completed", "The update could not be downloaded and verified."); });
            }
        });
    }

    private void install(File file) {
        if (!activity.getPackageManager().canRequestPackageInstalls()) {
            activity.startActivity(new Intent(Settings.ACTION_MANAGE_UNKNOWN_APP_SOURCES, Uri.parse("package:" + PACKAGE)));
            notice("Allow updates", "Turn on “Allow from this source”, return here, then tap Update again.");
            return;
        }
        Uri uri = FileProvider.getUriForFile(activity, PACKAGE + ".updates", file);
        Intent intent = new Intent(Intent.ACTION_INSTALL_PACKAGE).setDataAndType(uri, "application/vnd.android.package-archive")
                .addFlags(Intent.FLAG_GRANT_READ_URI_PERMISSION);
        activity.startActivity(intent);
    }

    @SuppressWarnings("deprecation")
    private void verifyArchive(File file, JSONObject manifest) throws Exception {
        int flags = Build.VERSION.SDK_INT >= 28 ? PackageManager.GET_SIGNING_CERTIFICATES : PackageManager.GET_SIGNATURES;
        android.content.pm.PackageInfo info = activity.getPackageManager().getPackageArchiveInfo(file.getAbsolutePath(), flags);
        if (info == null || !PACKAGE.equals(info.packageName) || (Build.VERSION.SDK_INT >= 28 ? info.getLongVersionCode() : info.versionCode) != manifest.getLong("versionCode")) throw new IllegalStateException();
        android.content.pm.Signature[] signatures = Build.VERSION.SDK_INT >= 28 ? info.signingInfo.getApkContentsSigners() : info.signatures;
        if (signatures == null || signatures.length != 1 || !CERTIFICATE_SHA256.equals(hex(MessageDigest.getInstance("SHA-256").digest(signatures[0].toByteArray())))) throw new IllegalStateException();
        if ((info.applicationInfo.flags & android.content.pm.ApplicationInfo.FLAG_DEBUGGABLE) != 0) throw new IllegalStateException();
    }

    private static String fetch(String address, int max) throws Exception {
        HttpsURLConnection c = (HttpsURLConnection) new URL(address).openConnection();
        c.setConnectTimeout(15000); c.setReadTimeout(20000); c.setRequestProperty("Accept", "application/json");
        if (c.getResponseCode() != 200) throw new IllegalStateException();
        try (InputStream input = c.getInputStream(); ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[4096]; int count;
            while ((count = input.read(buffer)) != -1) { if (output.size() + count > max) throw new IllegalStateException(); output.write(buffer, 0, count); }
            return new String(output.toByteArray(), StandardCharsets.UTF_8);
        } finally { c.disconnect(); }
    }
    private static void download(String address, File target, long expected) throws Exception {
        HttpsURLConnection c = (HttpsURLConnection) new URL(address).openConnection();
        c.setInstanceFollowRedirects(true); c.setConnectTimeout(15000); c.setReadTimeout(60000);
        if (c.getResponseCode() != 200) throw new IllegalStateException();
        long total = 0;
        try (InputStream input = c.getInputStream(); FileOutputStream output = new FileOutputStream(target)) {
            byte[] buffer = new byte[32768]; int count;
            while ((count = input.read(buffer)) != -1) { total += count; if (total > expected || total > MAX_APK) throw new IllegalStateException(); output.write(buffer, 0, count); }
        } finally { c.disconnect(); }
        if (total != expected) throw new IllegalStateException();
    }
    private static String hash(File file) throws Exception {
        MessageDigest digest = MessageDigest.getInstance("SHA-256");
        try (FileInputStream input = new FileInputStream(file)) { byte[] b = new byte[32768]; int n; while ((n = input.read(b)) != -1) digest.update(b, 0, n); }
        return hex(digest.digest());
    }
    private static String hex(byte[] bytes) { StringBuilder value = new StringBuilder(); for (byte b : bytes) value.append(String.format("%02x", b)); return value.toString(); }
    private void notice(String title, String text) { if (!destroyed && foreground) new AlertDialog.Builder(activity).setTitle(title).setMessage(text).setPositiveButton("OK", null).show(); }
}
