package ca.thechocolaterabbit.onlineorders;

import android.content.Context;
import com.google.firebase.FirebaseApp;
import com.google.firebase.FirebaseOptions;
import com.google.firebase.messaging.FirebaseMessaging;
import org.json.JSONObject;
import java.util.concurrent.ExecutorService;
import java.util.concurrent.Executors;

final class PushManager {
    private static final ExecutorService NETWORK = Executors.newSingleThreadExecutor();
    private PushManager() { }

    static void configure(Context context) {
        Context app = context.getApplicationContext();
        NETWORK.execute(() -> {
            try {
                ApiClient api = new ApiClient(app);
                if (!api.store().isPaired()) return;
                JSONObject config = api.get("/wp-json/tcr-orders/v1/config");
                if (!config.optBoolean("firebaseConfigured")) return;
                JSONObject firebase = config.getJSONObject("firebase");
                String apiKey = firebase.getString("apiKey"), applicationId = firebase.getString("applicationId");
                String projectId = firebase.getString("projectId"), senderId = firebase.getString("senderId");
                if (!apiKey.matches("AIza[0-9A-Za-z_-]{35}") || !senderId.matches("[0-9]{6,20}")
                        || !applicationId.matches("1:" + senderId + ":android:[0-9a-fA-F]{8,64}")
                        || !projectId.matches("[a-z][a-z0-9-]{4,61}[a-z0-9]")) return;
                if (FirebaseApp.getApps(app).isEmpty()) {
                    FirebaseOptions options = new FirebaseOptions.Builder().setApiKey(apiKey).setApplicationId(applicationId)
                            .setProjectId(projectId).setGcmSenderId(senderId).build();
                    FirebaseApp.initializeApp(app, options);
                }
                FirebaseMessaging.getInstance().setAutoInitEnabled(true);
                FirebaseMessaging.getInstance().getToken().addOnSuccessListener(token -> register(app, token));
            } catch (Exception ignored) { }
        });
    }

    static void register(Context context, String token) {
        if (token == null || token.length() < 20) return;
        NETWORK.execute(() -> {
            try { new ApiClient(context).post("/wp-json/tcr-orders/v1/device/push", new JSONObject().put("token", token)); }
            catch (Exception ignored) { }
        });
    }
}

