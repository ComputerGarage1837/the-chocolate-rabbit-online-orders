package ca.thechocolaterabbit.onlineorders;

import android.content.Context;
import org.json.JSONObject;
import java.io.ByteArrayOutputStream;
import java.io.InputStream;
import java.io.OutputStream;
import java.net.URI;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import javax.net.ssl.HttpsURLConnection;

final class ApiClient {
    private static final int MAX_RESPONSE = 2 * 1024 * 1024;
    private final SecureStore store;

    ApiClient(Context context) { store = new SecureStore(context); }
    SecureStore store() { return store; }

    JSONObject pair(String storeUrl, String code) throws Exception {
        String origin = normalizeOrigin(storeUrl);
        JSONObject response = send(origin, "POST", "/wp-json/tcr-orders/v1/pair",
                new JSONObject().put("code", code.trim()), false);
        String token = response.getString("token");
        if (!token.matches("[A-Za-z0-9_-]{40,100}")) throw new IllegalArgumentException("The store returned an invalid pairing token.");
        store.write(origin, token);
        return response;
    }

    JSONObject get(String path) throws Exception { return authenticated("GET", path, null); }
    JSONObject post(String path, JSONObject body) throws Exception { return authenticated("POST", path, body); }
    private JSONObject authenticated(String method, String path, JSONObject body) throws Exception {
        if (!store.isPaired()) throw new IllegalStateException("This app is not paired with the store.");
        return send(store.storeUrl(), method, path, body, true);
    }

    private JSONObject send(String origin, String method, String path, JSONObject body, boolean authenticated) throws Exception {
        if (!path.startsWith("/wp-json/tcr-orders/v1/")) throw new IllegalArgumentException("Invalid API path.");
        HttpsURLConnection connection = (HttpsURLConnection) new URL(origin + path).openConnection();
        connection.setInstanceFollowRedirects(false);
        connection.setConnectTimeout(15000);
        connection.setReadTimeout(30000);
        connection.setRequestMethod(method);
        connection.setRequestProperty("Accept", "application/json");
        connection.setRequestProperty("Cache-Control", "no-store");
        connection.setRequestProperty("X-TCR-App-Version", BuildConfig.VERSION_NAME);
        if (authenticated) connection.setRequestProperty("Authorization", "Bearer " + store.token());
        if (body != null) {
            byte[] bytes = body.toString().getBytes(StandardCharsets.UTF_8);
            connection.setDoOutput(true);
            connection.setFixedLengthStreamingMode(bytes.length);
            connection.setRequestProperty("Content-Type", "application/json; charset=utf-8");
            try (OutputStream output = connection.getOutputStream()) { output.write(bytes); }
        }
        int status = connection.getResponseCode();
        InputStream raw = status >= 200 && status < 300 ? connection.getInputStream() : connection.getErrorStream();
        String text = read(raw);
        connection.disconnect();
        JSONObject response;
        try { response = text.isEmpty() ? new JSONObject() : new JSONObject(text); }
        catch (Exception malformed) { throw new IllegalStateException("The store returned an unreadable response."); }
        if (status < 200 || status >= 300) {
            if (status == 401 && authenticated) {
                throw new IllegalStateException("The store rejected the saved pairing, but it was not erased. Retry when the site is available; re-pair only if this continues.");
            }
            throw new IllegalStateException(response.optString("message", "The store rejected this request (" + status + ")."));
        }
        return response;
    }

    static String normalizeOrigin(String input) throws Exception {
        String value = input == null ? "" : input.trim();
        if (!value.contains("://")) value = "https://" + value;
        URI uri = new URI(value);
        if (!"https".equalsIgnoreCase(uri.getScheme()) || uri.getHost() == null || uri.getUserInfo() != null
                || uri.getPort() != -1 || uri.getQuery() != null || uri.getFragment() != null) {
            throw new IllegalArgumentException("Enter the secure store address, for example https://thechocolaterabbit.net");
        }
        String path = uri.getPath();
        if (path != null && !path.isEmpty() && !"/".equals(path)) throw new IllegalArgumentException("Enter only the store address, without a page path.");
        return "https://" + uri.getHost().toLowerCase();
    }

    private static String read(InputStream input) throws Exception {
        if (input == null) return "";
        try (InputStream stream = input; ByteArrayOutputStream output = new ByteArrayOutputStream()) {
            byte[] buffer = new byte[8192]; int count;
            while ((count = stream.read(buffer)) != -1) {
                if (output.size() + count > MAX_RESPONSE) throw new IllegalStateException("The store response was too large.");
                output.write(buffer, 0, count);
            }
            return StandardCharsets.UTF_8.newDecoder().decode(java.nio.ByteBuffer.wrap(output.toByteArray())).toString();
        }
    }
}
