package ca.thechocolaterabbit.onlineorders;

import android.Manifest;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.os.Build;
import androidx.core.app.NotificationCompat;
import androidx.core.content.ContextCompat;
import com.google.firebase.messaging.FirebaseMessagingService;
import com.google.firebase.messaging.RemoteMessage;

public final class OrdersMessagingService extends FirebaseMessagingService {
    private static final String CHANNEL = "new_orders";
    @Override public void onNewToken(String token) { PushManager.register(this, token); }

    @Override public void onMessageReceived(RemoteMessage message) {
        String orderId = message.getData().getOrDefault("orderId", "");
        String title = message.getData().getOrDefault("title", "New online order");
        String body = message.getData().getOrDefault("body", "A new Chocolate Rabbit order is ready to review.");
        NotificationManager manager = getSystemService(NotificationManager.class);
        if (Build.VERSION.SDK_INT >= 26) manager.createNotificationChannel(new NotificationChannel(CHANNEL, "New orders", NotificationManager.IMPORTANCE_HIGH));
        if (Build.VERSION.SDK_INT >= 33 && ContextCompat.checkSelfPermission(this, Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) return;
        Intent open = new Intent(this, MainActivity.class).addFlags(Intent.FLAG_ACTIVITY_CLEAR_TOP | Intent.FLAG_ACTIVITY_SINGLE_TOP).putExtra("orderId", orderId);
        PendingIntent pending = PendingIntent.getActivity(this, orderId.hashCode(), open, PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);
        NotificationCompat.Builder notification = new NotificationCompat.Builder(this, CHANNEL)
                .setSmallIcon(android.R.drawable.ic_dialog_info).setContentTitle(title).setContentText(body)
                .setStyle(new NotificationCompat.BigTextStyle().bigText(body)).setAutoCancel(true).setPriority(NotificationCompat.PRIORITY_HIGH).setContentIntent(pending);
        manager.notify(orderId.isEmpty() ? (int) System.currentTimeMillis() : orderId.hashCode(), notification.build());
    }
}

