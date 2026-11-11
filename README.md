# Bohol Health Watch

A phone-first health news and alerts app for Bohol.

```
web/app.html      The app screens, shared by the web and Android versions
data/seed.json    The checked starting reports and health tips
build.py          Puts the data into the app. Run it after editing either file
build-apk-windows.bat   Builds the APK on Windows
.github/workflows/      Builds the APK on GitHub
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
| Android project | Compiled on GitHub Actions (run #3), before the live-news changes. Not yet tested on a phone |
| Live feed flow | Tested locally with sample feeds. Not yet run on GitHub or against the real sites |

## 1. Get the APK and live news (GitHub)

This repo must stay **public**, because the app reads its news from it.

1. Push or upload this folder to `main`, including `.github`, `live` and `android/app/debug.keystore`.
2. **Build APK** runs by itself. Each build is published at
   `https://github.com/Hanes22/BOHOL-HEALTH-WATCH/releases/latest/download/BoholHealthWatch.apk`
3. **Update news** is scheduled about every 5 minutes and writes `live/feed.json`. You can also start it manually from the app's **Collect Page posts** link or from the Actions tab.
4. If "Update news" can't push, set Settings > Actions > General > Workflow permissions to "Read and write".

The app reads the feed when opened, when reopened, and about every minute while open. Android background checks run about every 15 minutes. GitHub may delay scheduled runs, so updates are not guaranteed to arrive on an exact minute.

### Change figures or alerts
Edit `data/seed.json` on GitHub. The next "Update news" run applies it to everyone, with no new APK. Figures, alert status and manual Official labels come only from that file.

### What publishes automatically
Stories from the feeds in `backend/config.github.php` publish without review, including serious ones. They are labeled News report, except posts from the Provincial Government's site, which are labeled Official.

- **Alerts:** a Bohol story about an outbreak, a rise in cases or a health advisory becomes a Watch. Only an official source can make an Active alert. A single incident, such as one death, stays news. A Watch ends after 14 days with no update.
- **Good news:** free services, recoveries, falling cases and new facilities get a Good news label. Stories about deaths don't.
- **Images:** taken from the feed or the article's sharing image. Stories about deaths or outbreaks get no image. Images load from the publisher and remain their property.
- **Notifications:** stories naming Bohol or its towns can notify phones, subject to each person's Profile choices.

### Trusted Facebook pages
The **Update news** workflow checks the configured Facebook Page on scheduled runs (about every 5 minutes) and adds new health posts to the same feed used by the mobile app. The app's **Check feed** button only reloads the published feed; use **Collect Page posts** to start a fresh Facebook collection, then return and tap **Check feed**. It needs a Meta app and a Page access token with permission to read posts from that Page. A Page you manage can be tested with your app role; using the integration for other users or public Pages may require Meta App Review and Page Public Content Access.

1. The configured Page is **BOHOL news** (ID `1448497761679246`). In the GitHub repository, you can override its name with Actions variable `FB_PAGE_NAME`. You can optionally set `FB_PAGE_TRUST=official` only when it is an official government health Page; other Pages are labeled News report.
2. Add the Page access token as an Actions secret named `FB_ACCESS_TOKEN`. Never commit or share the token in chat.
3. Run **Actions > Update news > Run workflow**. Check its log for `Facebook <page>: {…}` or `FAILED Facebook`. The app's **Feed diagnostics** shows the last feed fetch, the Facebook collector result stored in that feed, and the number of Page posts currently shown. New health posts appear after the workflow finishes and you tap **Check feed**; phones can notify based on each user's Profile choices.

Set `FB_PAGE_ID` as an Actions variable to override the configured Page. Facebook posts from this configured health page are imported. Revoke and replace the token if it is exposed, and renew it if Meta expires it.

### Other ways to build
`build-apk-windows.bat` or Android Studio also work, using the `android` folder.

## 2. Optional: your own server with admin review

Use this instead of the GitHub feed if you want a person to review serious stories before they appear.

You need PHP 8.1 or newer with PDO, cURL and SimpleXML, plus a MySQL database and HTTPS.

1. Upload `backend/`. Point the web root at `backend/public` so `config.php` can't be reached from the web.
2. Copy `config.sample.php` to `config.php` and fill in the database details.
3. Run `php bin/hash-password.php` and paste the result into `admin_password_hash`.
4. Run `php bin/install.php` once.
5. Add a cron job:
   `*/20 * * * * /usr/bin/php /path/to/backend/bin/collect.php >> /path/to/collect.log 2>&1`
6. Set `BHW_FEED_URL=https://yourdomain.com/feed.php?limit=100` in `android/gradle.properties`, then rebuild.

To try it locally with no MySQL, use `'dsn' => 'sqlite:' . __DIR__ . '/data/bhw.sqlite'`, then run `php -S localhost:8080 -t public`.

## Keeping the dashboard current

The dengue totals and national trend are not collected automatically, because a wrong number here would mislead people. Update them under "Dashboard figures" in `admin.php` from a report you have read. The app shows each figure's dates, age and source.

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
- Replace the placeholder app icon. Sign release builds with a private key, because `android/app/debug.keystore` is public.
- Add a review step for serious stories, or use the option 2 server.
