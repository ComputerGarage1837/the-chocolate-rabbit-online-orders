package ca.thechocolaterabbit.onlineorders;

import android.app.Application;

public final class OrdersApplication extends Application {
    @Override public void onCreate() {
        super.onCreate();
        if (new SecureStore(this).isPaired()) PushManager.configure(this);
    }
}

