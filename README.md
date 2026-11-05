# Bohol Health Watch

A phone-first health news and alerts app for Bohol.

```
web/app.html      The app screens, shared by the web and Android versions
data/seed.json    The checked starting reports and health tips
build.py          Puts the data into the app. Run it after editing either file
backend/          PHP news collector, feed API and admin page
android/          Android Studio project
```

## What has been tested

| Part | Status |
|---|---|
| App screens | Tested in Chromium at Pixel 5 size, in light and dark |
| Collector and classifier | `php backend/tests/run.php` passes |
| Feed API, admin page, app reading live data | Tested together on PHP with SQLite |
| MySQL | **Not tested.** No MySQL server was available. The SQL is kept portable |
| Live feed fetch by the collector | **Not tested from the build machine,** which has no outbound network. Both feed URLs were read successfully with a separate tool on 5 Nov 2026 |
| Android project | **Not compiled.** No Android SDK was available. Java syntax and XML were checked. Expect to fix small issues on the first build |

## 1. Run the Android app

1. Open the `android` folder in Android Studio and let Gradle sync.
2. Press Run. The app opens with its bundled reports.

The app shows those bundled reports and sends no notifications until you set `BHW_API_BASE`.

## 2. Put the backend online

You need PHP 8.1 or newer with PDO, cURL and SimpleXML, plus a MySQL database and HTTPS.

1. Upload `backend/`. Point the web root at `backend/public` so `config.php` can't be reached from the web.
2. Copy `config.sample.php` to `config.php` and fill in the database details.
3. Run `php bin/hash-password.php` and paste the result into `admin_password_hash`.
4. Run `php bin/install.php` once.
5. Add a cron job:
   `*/20 * * * * /usr/bin/php /path/to/backend/bin/collect.php >> /path/to/collect.log 2>&1`
6. Set `BHW_API_BASE=https://yourdomain.com` in `android/gradle.properties`, then rebuild.

To try it locally with no MySQL, use `'dsn' => 'sqlite:' . __DIR__ . '/data/bhw.sqlite'`, then run `php -S localhost:8080 -t public`.

## How reports flow

1. Every 20 minutes, the collector reads the feeds in `config.php`.
2. It keeps health stories only and skips repeated URLs and near-identical headlines.
3. Routine stories are published with a **News report** label.
4. Stories mentioning an outbreak, death or emergency wait in `admin.php` for review.
5. Only a person can mark a report **Official**, set an alert status or send a notification.

The collector does not extract case counts and does not declare outbreaks.

## Where this differs from the original plan

| Original plan | This build | Why |
|---|---|---|
| Flutter | Web screens in an Android WebView | Flutter isn't available on the build machine, so Flutter code couldn't have been run. This version was tested and fits a Java and WebView workflow |
| Firebase Cloud Messaging | Background check every 15 minutes | Needs no Firebase project and still works with the app closed. Alerts can arrive up to about 15 minutes late, or later when Android is saving battery |
| OpenAI analysis | Keyword rules | No API key or running cost. The admin page checks serious reports |
| Automatic Facebook collection | Admin pastes the post by hand | Reading Pages you don't manage needs Meta's approval, and scraping breaks platform rules |
| Optional login | No accounts | Nothing needs one yet. Settings stay on the phone |

## Sources to add

- PIA (`pia.gov.ph`) blocks automated readers with a bot check.
- The Bohol Times `/feed/` URL returned a normal web page instead of a feed.
- The Provincial Health Office website showed no feed or dated announcements.

Add these through the admin page's manual form, or ask each office for a feed.

## Before a public release

- Have the Provincial Health Office or a health professional review the Health Tips.
- Check the town positions in `web/app.html`. They are approximate, and the coastline is a sketch.
- Replace the placeholder app icon, and add your release signing key.
