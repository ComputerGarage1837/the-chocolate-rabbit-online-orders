package ca.thechocolaterabbit.onlineorders;

import android.Manifest;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Bundle;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;
import androidx.activity.ComponentActivity;
import androidx.core.app.ActivityCompat;

public final class MainActivity extends ComponentActivity {
    private WebView webView;
    private OrdersBridge bridge;
    private UpdateManager updates;

    @Override protected void onCreate(Bundle state) {
        super.onCreate(state);
        webView = new WebView(this);
        setContentView(webView);
        WebSettings settings = webView.getSettings();
        settings.setJavaScriptEnabled(true);
        settings.setDomStorageEnabled(false);
        settings.setAllowFileAccess(true);
        settings.setAllowContentAccess(false);
        settings.setMixedContentMode(WebSettings.MIXED_CONTENT_NEVER_ALLOW);
        settings.setSaveFormData(false);
        webView.setWebViewClient(new WebViewClient() {
            @Override public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri url = request.getUrl();
                return !("file".equals(url.getScheme()) && "/android_asset/index.html".equals(url.getPath()));
            }
        });
        updates = new UpdateManager(this);
        bridge = new OrdersBridge(this, webView, updates);
        webView.addJavascriptInterface(bridge, "OrdersApp");
        webView.loadUrl("file:///android_asset/index.html");
        webView.setWebChromeClient(new android.webkit.WebChromeClient());
        if (android.os.Build.VERSION.SDK_INT >= 33 && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            ActivityCompat.requestPermissions(this, new String[]{Manifest.permission.POST_NOTIFICATIONS}, 41);
        }
        webView.postDelayed(() -> openOrderFrom(getIntent()), 800);
    }

    @Override protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        openOrderFrom(intent);
    }

    private void openOrderFrom(Intent intent) {
        if (webView == null || intent == null) return;
        String id = intent.getStringExtra("orderId");
        if (id != null && id.matches("[0-9]{1,12}")) webView.evaluateJavascript("window.openOrderFromNotification(" + id + ")", null);
    }

    @Override protected void onResume() { super.onResume(); if (updates != null) updates.onResume(); }
    @Override protected void onDestroy() {
        if (bridge != null) bridge.destroy();
        if (updates != null) updates.destroy();
        if (webView != null) { webView.removeJavascriptInterface("OrdersApp"); webView.destroy(); }
        super.onDestroy();
    }
}

