package ca.thechocolaterabbit.onlineorders;

import android.webkit.JavascriptInterface;
import android.webkit.WebView;
import org.json.JSONObject;
import java.net.URLEncoder;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

public final class OrdersBridge {
    private final MainActivity activity;
    private final WebView webView;
    private final ApiClient api;
    private final UpdateManager updates;
    private final ExecutorService network = Executors.newSingleThreadExecutor();
    private volatile boolean destroyed;

    OrdersBridge(MainActivity activity, WebView webView, UpdateManager updates) {
        this.activity = activity; this.webView = webView; this.updates = updates; this.api = new ApiClient(activity);
    }

    @JavascriptInterface public void request(String requestId, String action, String rawPayload) {
        if (destroyed || requestId == null || !requestId.matches("[A-Za-z0-9_-]{1,50}")) return;
        if ("checkUpdate".equals(action)) {
            activity.runOnUiThread(updates::checkManually);
            reply(requestId, true, new JSONObject());
            return;
        }
        network.execute(() -> {
            try {
                JSONObject payload = rawPayload == null || rawPayload.isEmpty() ? new JSONObject() : new JSONObject(rawPayload);
                JSONObject result = handle(action, payload);
                reply(requestId, true, result);
            } catch (Exception error) {
                JSONObject failure = new JSONObject();
                try { failure.put("message", cleanMessage(error)); } catch (Exception ignored) { }
                reply(requestId, false, failure);
            }
        });
    }

    private JSONObject handle(String action, JSONObject payload) throws Exception {
        switch (action == null ? "" : action) {
            case "state": return new JSONObject().put("paired", api.store().isPaired()).put("storeUrl", api.store().storeUrl())
                    .put("version", BuildConfig.VERSION_NAME);
            case "pair": {
                JSONObject paired = api.pair("https://thechocolaterabbit.net", payload.getString("code"));
                PushManager.configure(activity);
                return paired.put("paired", true).put("storeUrl", api.store().storeUrl());
            }
            case "logout": api.store().clear(); return new JSONObject().put("paired", false);
            case "orders": {
                int page = Math.max(1, Math.min(1000, payload.optInt("page", 1)));
                String status = allowed(payload.optString("status", "any"), "any", "pending", "processing", "on-hold", "completed", "cancelled", "refunded", "failed");
                String search = payload.optString("search", "").trim();
                if (search.length() > 80) search = search.substring(0, 80);
                return api.get("/wp-json/tcr-orders/v1/orders?page=" + page + "&status=" + encode(status) + "&search=" + encode(search));
            }
            case "order": return api.get("/wp-json/tcr-orders/v1/orders/" + orderId(payload));
            case "capture": return api.post("/wp-json/tcr-orders/v1/orders/" + orderId(payload) + "/capture", new JSONObject());
            case "status": return api.post("/wp-json/tcr-orders/v1/orders/" + orderId(payload) + "/status",
                    new JSONObject().put("status", allowed(payload.getString("status"), "pending", "processing", "on-hold", "completed", "cancelled", "refunded", "failed")));
            case "note": {
                String note = payload.getString("note").trim();
                if (note.isEmpty() || note.length() > 1000) throw new IllegalArgumentException("Enter a note up to 1,000 characters.");
                return api.post("/wp-json/tcr-orders/v1/orders/" + orderId(payload) + "/notes", new JSONObject().put("note", note));
            }
            case "refund": {
                String amount = payload.getString("amount").trim();
                if (!amount.matches("[0-9]{1,7}(?:\\.[0-9]{1,2})?")) throw new IllegalArgumentException("Enter a valid refund amount.");
                String reason = payload.optString("reason", "").trim();
                if (reason.length() > 500) throw new IllegalArgumentException("Keep the refund reason under 500 characters.");
                return api.post("/wp-json/tcr-orders/v1/orders/" + orderId(payload) + "/refunds",
                        new JSONObject().put("amount", amount).put("reason", reason).put("refundPayment", payload.optBoolean("refundPayment", true)));
            }
            default: throw new IllegalArgumentException("Unknown app action.");
        }
    }

    private static long orderId(JSONObject payload) throws Exception {
        long id = payload.getLong("id");
        if (id < 1 || id > 999999999999L) throw new IllegalArgumentException("Invalid order.");
        return id;
    }
    private static String allowed(String value, String... options) {
        for (String option : options) if (option.equals(value)) return value;
        throw new IllegalArgumentException("That option is not available.");
    }
    private static String encode(String value) throws Exception { return URLEncoder.encode(value, "UTF-8"); }
    private static String cleanMessage(Exception error) {
        String message = error.getMessage();
        if (message == null || message.trim().isEmpty()) return "The request could not be completed.";
        String cleaned = message.replaceAll("[\\r\\n]+", " ");
        return cleaned.substring(0, Math.min(300, cleaned.length()));
    }
    private void reply(String requestId, boolean success, JSONObject payload) {
        if (destroyed) return;
        String script = "window.onNativeResult(" + JSONObject.quote(requestId) + "," + success + "," + JSONObject.quote(payload.toString()) + ")";
        activity.runOnUiThread(() -> { if (!destroyed) webView.evaluateJavascript(script, null); });
    }
    void destroy() { destroyed = true; network.shutdownNow(); }
}
