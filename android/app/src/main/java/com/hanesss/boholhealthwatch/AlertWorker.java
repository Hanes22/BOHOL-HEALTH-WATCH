package com.hanesss.boholhealthwatch;

import android.Manifest;
import android.app.NotificationChannel;
import android.app.NotificationManager;
import android.app.PendingIntent;
import android.content.Context;
import android.content.Intent;
import android.content.SharedPreferences;
import android.content.pm.PackageManager;
import android.os.Build;

import androidx.annotation.NonNull;
import androidx.core.app.NotificationCompat;
import androidx.core.app.NotificationManagerCompat;
import androidx.work.Constraints;
import androidx.work.ExistingPeriodicWorkPolicy;
import androidx.work.ExistingWorkPolicy;
import androidx.work.NetworkType;
import androidx.work.OneTimeWorkRequest;
import androidx.work.PeriodicWorkRequest;
import androidx.work.WorkManager;
import androidx.work.Worker;
import androidx.work.WorkerParameters;

import org.json.JSONArray;
import org.json.JSONException;
import org.json.JSONObject;

import java.io.BufferedReader;
import java.io.IOException;
import java.io.InputStreamReader;
import java.net.HttpURLConnection;
import java.net.URL;
import java.nio.charset.StandardCharsets;
import java.util.concurrent.TimeUnit;

/** Checks the live feed about every 15 minutes, even with the app closed, and posts notifications. */
public class AlertWorker extends Worker {
    static final String KEY_PREFS = "prefs_json";
    private static final int MAX_PER_CHECK = 5;
    private static final String KEY_LAST_SEQ = "last_seq";
    private static final String CHANNEL = "health_alerts";
    private static final String WORK_NAME = "bhw_alert_check";

    public AlertWorker(@NonNull Context context, @NonNull WorkerParameters params) {
        super(context, params);
    }

    static SharedPreferences prefs(Context context) {
        return context.getSharedPreferences("bhw", Context.MODE_PRIVATE);
    }

    static void schedule(Context context) {
        if (BuildConfig.FEED_URL.isEmpty()) {
            return;
        }
        PeriodicWorkRequest request = new PeriodicWorkRequest.Builder(AlertWorker.class, 15, TimeUnit.MINUTES)
                .setConstraints(new Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build();
        WorkManager.getInstance(context).enqueueUniquePeriodicWork(WORK_NAME, ExistingPeriodicWorkPolicy.KEEP, request);
    }

    /** Checks once right away, so news that arrived while the phone was off isn't delayed. */
    static void checkNow(Context context) {
        if (BuildConfig.FEED_URL.isEmpty()) {
            return;
        }
        OneTimeWorkRequest request = new OneTimeWorkRequest.Builder(AlertWorker.class)
                .setConstraints(new Constraints.Builder().setRequiredNetworkType(NetworkType.CONNECTED).build())
                .build();
        WorkManager.getInstance(context).enqueueUniqueWork(WORK_NAME + "_now", ExistingWorkPolicy.KEEP, request);
    }

    static void postTest(Context context) {
        try {
            post(context, new JSONObject()
                    .put("id", "test")
                    .put("title", "Bohol Health Watch test")
                    .put("summary", "Notifications are working."));
        } catch (JSONException ignored) {
            // These fixed values can't fail.
        }
    }

    @NonNull
    @Override
    public Result doWork() {
        SharedPreferences store = prefs(getApplicationContext());
        long last = store.getLong(KEY_LAST_SEQ, -1);
        try {
            String url = BuildConfig.FEED_URL;
            JSONObject feed = fetch(url + (url.contains("?") ? "&" : "?") + "t=" + System.currentTimeMillis());
            long max = feed.optLong("max_seq", Math.max(last, 0));
            // The first run only records a starting point, so a new install isn't flooded with old reports.
            if (last >= 0) {
                JSONObject user = new JSONObject(store.getString(KEY_PREFS, "{}"));
                JSONArray items = feed.getJSONArray("items");
                int posted = 0;
                for (int i = 0; i < items.length() && posted < MAX_PER_CHECK; i++) {
                    JSONObject item = items.getJSONObject(i);
                    if (item.optLong("seq", 0) > last && shouldNotify(item, user)) {
                        post(getApplicationContext(), item);
                        posted++;
                    }
                }
            }
            store.edit().putLong(KEY_LAST_SEQ, Math.max(max, Math.max(last, 0))).apply();
            return Result.success();
        } catch (IOException | JSONException e) {
            return Result.retry();
        }
    }

    /** Mirrors the Profile tab: town, province-wide and topic choices. Only reports flagged notify are sent. */
    static boolean shouldNotify(JSONObject item, JSONObject user) {
        if (!item.optBoolean("notify", false)) {
            return false;
        }
        boolean good = "good".equals(item.optString("tone"));
        if (good && !user.optBoolean("good", true)) {
            return false;
        }
        JSONArray cats = user.optJSONArray("cats");
        if (cats != null && !contains(cats, item.optString("category"))) {
            return false;
        }
        String town = user.optString("town", "");
        boolean named = !town.isEmpty() && contains(item.optJSONArray("municipalities"), town);
        if (named && user.optBoolean("local", true)) {
            return true;
        }
        String scope = item.optString("scope");
        boolean wide = "province".equals(scope) || "region".equals(scope) || "national".equals(scope);
        if (wide) {
            return user.optBoolean("province", true);
        }
        // With no town chosen the reader follows all of Bohol, so local reports count as province-wide news.
        return town.isEmpty() && user.optBoolean("province", true);
    }

    private static boolean contains(JSONArray array, String value) {
        if (array == null) {
            return false;
        }
        for (int i = 0; i < array.length(); i++) {
            if (value.equals(array.optString(i))) {
                return true;
            }
        }
        return false;
    }

    private static JSONObject fetch(String address) throws IOException, JSONException {
        HttpURLConnection c = (HttpURLConnection) new URL(address).openConnection();
        c.setConnectTimeout(15000);
        c.setReadTimeout(20000);
        try {
            if (c.getResponseCode() != 200) {
                throw new IOException("HTTP " + c.getResponseCode());
            }
            StringBuilder body = new StringBuilder();
            try (BufferedReader r = new BufferedReader(new InputStreamReader(c.getInputStream(), StandardCharsets.UTF_8))) {
                String line;
                while ((line = r.readLine()) != null) {
                    body.append(line);
                }
            }
            return new JSONObject(body.toString());
        } finally {
            c.disconnect();
        }
    }

    private static void post(Context context, JSONObject item) {
        if (Build.VERSION.SDK_INT >= 33
                && context.checkSelfPermission(Manifest.permission.POST_NOTIFICATIONS) != PackageManager.PERMISSION_GRANTED) {
            return;
        }
        NotificationManager manager = context.getSystemService(NotificationManager.class);
        manager.createNotificationChannel(new NotificationChannel(
                CHANNEL, context.getString(R.string.channel_alerts), NotificationManager.IMPORTANCE_HIGH));

        String slug = item.optString("id");
        String alert = item.optString("alert");
        String prefix = "active".equals(alert) ? "Health alert: " : "watch".equals(alert) ? "Health watch: " : "good".equals(item.optString("tone")) ? "Good news: " : "";
        Intent open = new Intent(context, MainActivity.class).putExtra(MainActivity.EXTRA_REPORT, slug);
        PendingIntent tap = PendingIntent.getActivity(context, slug.hashCode(), open,
                PendingIntent.FLAG_UPDATE_CURRENT | PendingIntent.FLAG_IMMUTABLE);

        NotificationCompat.Builder n = new NotificationCompat.Builder(context, CHANNEL)
                .setSmallIcon(R.drawable.ic_notification)
                .setContentTitle(prefix + item.optString("title"))
                .setContentText(item.optString("summary"))
                .setStyle(new NotificationCompat.BigTextStyle().bigText(item.optString("summary")))
                .setPriority(NotificationCompat.PRIORITY_HIGH)
                .setContentIntent(tap)
                .setAutoCancel(true);
        NotificationManagerCompat.from(context).notify(slug.hashCode(), n.build());
    }
}
