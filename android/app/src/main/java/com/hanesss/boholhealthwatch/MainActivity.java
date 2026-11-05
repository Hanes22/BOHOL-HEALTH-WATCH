package com.hanesss.boholhealthwatch;

import android.Manifest;
import android.annotation.SuppressLint;
import android.app.Activity;
import android.content.ActivityNotFoundException;
import android.content.Context;
import android.content.Intent;
import android.content.pm.PackageManager;
import android.net.Uri;
import android.os.Build;
import android.os.Bundle;
import android.provider.Settings;
import android.webkit.JavascriptInterface;
import android.webkit.WebResourceRequest;
import android.webkit.WebSettings;
import android.webkit.WebView;
import android.webkit.WebViewClient;

import androidx.core.app.NotificationManagerCompat;

public class MainActivity extends Activity {
    static final String EXTRA_REPORT = "report";
    private static final String KEY_ASKED = "asked_notifications";
    private static final String HOME = "file:///android_asset/www/index.html";
    private WebView web;

    @SuppressLint({"SetJavaScriptEnabled", "AddJavascriptInterface"})
    @Override
    protected void onCreate(Bundle savedInstanceState) {
        super.onCreate(savedInstanceState);
        web = new WebView(this);
        setContentView(web);

        WebSettings s = web.getSettings();
        s.setJavaScriptEnabled(true);
        s.setDomStorageEnabled(true);
        s.setAllowFileAccess(false);
        s.setAllowContentAccess(false);

        // Only the bundled page stays in the WebView, so the bridge is never exposed to a website.
        web.setWebViewClient(new WebViewClient() {
            @Override
            public boolean shouldOverrideUrlLoading(WebView view, WebResourceRequest request) {
                Uri uri = request.getUrl();
                if (uri.toString().startsWith(HOME)) {
                    return false;
                }
                String scheme = uri.getScheme();
                if ("https".equals(scheme) || "http".equals(scheme)) {
                    try {
                        startActivity(new Intent(Intent.ACTION_VIEW, uri));
                    } catch (ActivityNotFoundException ignored) {
                        // No browser is installed, so the tap does nothing.
                    }
                }
                return true;
            }
        });
        web.addJavascriptInterface(new Bridge(this), "BHWNative");

        if (savedInstanceState != null) {
            web.restoreState(savedInstanceState);
        } else {
            web.loadUrl(urlFor(getIntent()));
        }

        if (Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            askForNotifications();
        }
        AlertWorker.schedule(this);
        AlertWorker.checkNow(this);
    }

    private void askForNotifications() {
        AlertWorker.prefs(this).edit().putBoolean(KEY_ASKED, true).apply();
        requestPermissions(new String[]{Manifest.permission.POST_NOTIFICATIONS}, 1);
    }

    /** Asks Android when it can still show the prompt, otherwise opens this app's notification settings. */
    void turnOnNotifications() {
        boolean canPrompt = Build.VERSION.SDK_INT >= 33
                && checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED
                && (!AlertWorker.prefs(this).getBoolean(KEY_ASKED, false)
                || shouldShowRequestPermissionRationale(Manifest.permission.POST_NOTIFICATIONS));
        if (canPrompt) {
            askForNotifications();
        } else {
            startActivity(new Intent(Settings.ACTION_APP_NOTIFICATION_SETTINGS)
                    .putExtra(Settings.EXTRA_APP_PACKAGE, getPackageName()));
        }
    }

    @Override
    public void onRequestPermissionsResult(int requestCode, String[] permissions, int[] grantResults) {
        super.onRequestPermissionsResult(requestCode, permissions, grantResults);
        web.evaluateJavascript("window.bhwNotifyChanged&&window.bhwNotifyChanged()", null);
    }

    private String urlFor(Intent intent) {
        String report = intent == null ? null : intent.getStringExtra(EXTRA_REPORT);
        return report == null ? HOME : HOME + "#report-" + Uri.encode(report);
    }

    @Override
    protected void onNewIntent(Intent intent) {
        super.onNewIntent(intent);
        setIntent(intent);
        if (intent.hasExtra(EXTRA_REPORT)) {
            web.loadUrl(urlFor(intent));
        }
    }

    @Override
    protected void onResume() {
        super.onResume();
        // Show the latest news whenever the app comes back to the screen.
        web.evaluateJavascript("window.bhwRefresh&&window.bhwRefresh();window.bhwNotifyChanged&&window.bhwNotifyChanged()", null);
    }

    @Override
    protected void onSaveInstanceState(Bundle outState) {
        super.onSaveInstanceState(outState);
        web.saveState(outState);
    }

    @SuppressWarnings("deprecation")
    @Override
    public void onBackPressed() {
        if (web.canGoBack()) {
            web.goBack();
        } else {
            super.onBackPressed();
        }
    }

    /** Methods the page calls as window.BHWNative. */
    static final class Bridge {
        private final MainActivity activity;
        private final Context context;

        Bridge(MainActivity activity) {
            this.activity = activity;
            this.context = activity.getApplicationContext();
        }

        @JavascriptInterface
        public String getNotificationStatus() {
            return NotificationManagerCompat.from(context).areNotificationsEnabled() ? "on" : "off";
        }

        @JavascriptInterface
        public void requestNotifications() {
            activity.runOnUiThread(activity::turnOnNotifications);
        }

        @JavascriptInterface
        public void sendTestNotification() {
            AlertWorker.postTest(context);
        }

        @JavascriptInterface
        public String getFeedUrl() {
            return BuildConfig.FEED_URL;
        }

        @JavascriptInterface
        public int getVersionCode() {
            return BuildConfig.VERSION_CODE;
        }

        @JavascriptInterface
        public void setPrefs(String json) {
            AlertWorker.prefs(context).edit().putString(AlertWorker.KEY_PREFS, json).apply();
        }
    }
}
