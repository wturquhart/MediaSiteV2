# frame — PHP video-sharing site

Frame is a PHP/MySQL video-sharing website with a dark responsive theme, built-in HTML5 player, user accounts, uploads, and an administrator portal. This version runs directly in the PHP hosting already managed by Virtualmin; it does not need .NET, Kestrel, Nginx, or a separate systemd service.

## Open in Visual Studio

Open `mediasite.sln` to browse the PHP source and this documentation in Visual Studio. This is a PHP application, not a .NET project, so Visual Studio does not build it with MSBuild. For PHP language services and debugging, install a PHP extension such as PHP Tools for Visual Studio and configure it to use PHP 8.0 or newer. The local PHP server command is documented below.

## Install on Ubuntu 24.04 with Virtualmin

These steps assume you administer the VPS and have a Virtualmin domain ready. Virtualmin should continue managing Apache, PHP-FPM, SSL certificates, and the domain's document root.

### 1. Prepare your domain and database

In Virtualmin:

1. Select the domain that will host the site. Confirm its DNS points to the VPS.
2. Enable HTTPS for the domain using Virtualmin's Let's Encrypt/SSL page. Remote setup is available only over HTTPS.
3. Create a new, empty MySQL or MariaDB database and a database user that has access to that database. Record the exact database name, username, password, and host. Virtualmin may prefix the database and username with the domain account name; use the complete names Virtualmin shows. A local database host is commonly `localhost`.

Do not use a database that contains application data. The installer refuses to overwrite an existing Frame database.

### 2. Confirm PHP is enabled

For the Virtualmin domain, enable PHP execution and use its PHP-FPM handler (Ubuntu 24.04 commonly provides PHP 8.3). The app needs PHP 8.0 or newer with PDO MySQL and `fileinfo`; `mbstring` is recommended. Enable the PHP cURL extension to support URL imports and the PHP ZIP extension to upload ZIP archives. Virtualmin installations commonly have these available already. Ask the VPS administrator to enable any missing PHP modules in the domain's selected PHP version.

PHP-FPM and Apache impose server-side request size and execution limits that the application cannot bypass, including for administrators. Browser uploads are sent as sequential 1 MiB parts and interrupted parts are retried; configure `upload_max_filesize` to at least 2M and `post_max_size` plus Apache `LimitRequestBody` to at least 2 MiB for each part and multipart overhead. PHP processing timeouts still apply while validating, thumbnailing, or transcoding a completed video. Example settings (the app can accept videos larger than the request limits because they are chunked):

```ini
upload_max_filesize = 2M
post_max_size = 4M
max_execution_time = 1800
max_input_time = 1800
```

Also ensure temporary and private storage have enough disk space. Restart/reload PHP-FPM and Apache through Virtualmin or the VPS administrator after changing settings.

### 3. Upload the website files

On your Windows computer, extract the project archive. Use an SFTP client (such as WinSCP) or `scp` to copy **the contents of the `mediasite` folder** to the domain's Virtualmin `public_html` directory. The web root should contain `index.php`, `install.php`, `login.php`, `register.php`, `logout.php`, and `admin.php` directly.

Do not upload Visual Studio metadata (`.vs`) or build output (`bin`, `obj`), and do not make the private data directory web-accessible. You do not need to configure a reverse proxy or change Virtualmin's document root.

### 4. Set private storage permissions

The PHP app's default private data directory is one level above `public_html`, named `mediasite-data`. For a typical Virtualmin domain this is similar to `/home/ACCOUNT/mediasite-data`; the uploads are stored in its `uploads` subdirectory. This keeps database credentials and video files outside the public web root.

In Virtualmin, find the domain's home directory and make a private data folder beside `public_html`. Set the folder owner to the domain's PHP-FPM user and permissions to `750`. Create an `uploads` subfolder with the same owner and permissions. Example only—replace `ACCOUNT` with the actual domain account:

```bash
sudo install -d -o ACCOUNT -g ACCOUNT -m 0750 /home/ACCOUNT/mediasite-data
sudo install -d -o ACCOUNT -g ACCOUNT -m 0750 /home/ACCOUNT/mediasite-data/uploads
```

On some Virtualmin setups, PHP-FPM runs under a different user/group. Use the domain's configured PHP-FPM owner, not `www-data` by assumption; the PHP process must be able to write to the data and uploads directories.

### 5. Keep uploaded videos private on disk

No Apache alias is needed. The site serves uploaded videos through its PHP playback endpoint, which sets the video MIME type and supports byte-range requests for browser playback/seeking. Keep `mediasite-data/uploads` outside `public_html`; do not create an Apache alias or move uploads into the public web root. The PHP-FPM user needs read access to existing video files and write access to this directory.

### 6. Run the setup page

The setup page is accessible to anyone while the site is uninitialized. Upload the site only when you are ready to configure it, and complete setup promptly.

Open `https://YOUR-DOMAIN/install.php` in your browser. Enter the database connection details from Step 1 and the first administrator's name, email, and a strong password (12 or more characters). Submit the form. It tests the database, creates the tables and first administrator, and signs you in to the admin portal.

The installer locks itself after saving the database configuration. Remove `install.php` after confirming the site works. Never submit database credentials over plain HTTP.

### Troubleshooting

- **503 or blank error page:** In Virtualmin, inspect the domain's Apache error log and PHP-FPM log. Confirm PHP-FPM is running and PHP is enabled for the domain.
- **Installer says it cannot connect:** Verify the database host, full prefixed name/user, password, and that the database user has privileges on that database.
- **Installer blocks remote setup:** Confirm HTTPS is enabled and you opened the HTTPS address.
- **Cannot create data/config files:** Confirm `mediasite-data` is outside `public_html`, owned by the domain PHP-FPM user, and writable by that user.
- **Uploaded video does not play:** Confirm the site is serving the current `index.php`, PHP-FPM can read the private uploads directory, and the video file exists under `mediasite-data/uploads`. Uploaded media is served by the PHP endpoint with byte-range support; no Apache `/uploads/` alias is needed.
- **Large upload fails:** Check that `upload_max_filesize`, `post_max_size`, and Apache's request-body limit each allow at least a 1 MiB part plus multipart overhead (2 MiB or more is recommended). Also check private/temp disk space and PHP execution timeouts for final video processing.
- **URL import fails:** Confirm PHP cURL is enabled and the URL is public. URL imports support direct MP4, WebM, or Ogg files and pages exposing a downloadable video through standard HTML5 video/source tags or Open Graph video metadata. Protected or streaming-only embeds are not supported. Private/local addresses and nonstandard ports are blocked. The server must be able to reach the URL and have enough temporary storage and execution time.

## Features and limits

- The home page presents videos in a responsive gallery, with the most recently uploaded videos first. When no videos are available, it shows an empty-state message and an upload or sign-in action where applicable. Use **Browse** to view the full video catalog, search titles and creators, or filter by category. Selecting a video opens its player.
- Registration, sign-in, and admin pages are provided by `register.php`, `login.php`, and `admin.php`; `logout.php` signs out. Signed-in users can edit their public display name and short bio from their profile, which lists their visible uploaded videos. Video creator names link to the owning account's profile.
- MySQL persistence uses InnoDB. The app has CSRF protection and rate limits for account creation and sign-in; the hourly upload rate limit applies to regular users, not administrators.
- Uploads require an account. Regular users are limited to 1 GB per file, 10 videos and 5 GB per account, and a default 50 GB site-wide application quota. Administrators are exempt from these application upload-size, count, storage, and hourly-rate limits, including the FFmpeg output-size cap. Server-side PHP/Apache request limits, processing timeouts, available disk space, and hardware capacity still apply to every account; configure those separately. Choose MP4, WebM, or Ogg videos from the device, or upload a ZIP archive containing up to 50 such videos. Archives are extracted server-side and each video receives the same validation, quota checks, thumbnail generation, and optional transcoding as an individual upload. Regular accounts are limited to 1 GB per video and 1 GB per ZIP archive; ZIP extraction is capped at 10 GB total for every account. ZIP entry paths are never used as filesystem paths. You can also import one public HTTP(S) video URL (up to 1 GB) at a time. The URL can point directly to an MP4, WebM, or Ogg media file, or to a page exposing a downloadable video through standard HTML5 video/source tags or Open Graph video metadata (including a simple embedded frame). Protected or streaming-only video platforms are not supported. Private/local network destinations, nonstandard ports, and redirects or embedded media pointing to non-public hosts are blocked. URL imports require PHP cURL and are downloaded by the server, so their transfer speed is not shown in the browser. Device files and ZIP archives upload sequentially in 1 MiB parts with automatic retries and monotonic progress across the whole batch. Abandoned temporary upload parts and extracted archive videos are pruned after seven days when that account starts another device upload. The queue stops if a file fails; earlier successful uploads remain live. After transfer reaches 100%, PHP still validates and saves each video; files identified as MPEG transport stream are converted by FFmpeg, so the progress can pause while the server processes them. MP4 validation recognizes common PHP MIME aliases and verifies that the MP4 has both its file-type and movie-index atoms before accepting it; incomplete MP4s without a `moov` atom are rejected with a repair hint. The admin portal separates site settings, categories, accounts, and video moderation into tabs. Administrators can pause uploads, create member or administrator accounts, manage accounts, update site settings, create/rename/delete video categories, change each video's category, hide/restore videos, and select multiple sample or uploaded videos in the video moderation tab for deletion from the catalog (uploaded media files are removed too). Admin-created accounts require a unique email and a 12-72-byte password; share the initial password securely. Renaming updates existing videos; deleting a category moves its videos to Community.
- The server automatically generates a JPEG thumbnail for every uploaded video using FFmpeg; the browser does not capture or upload thumbnail images, and the admin portal has no manual thumbnail regeneration control. Install FFmpeg (Ubuntu: `sudo apt install ffmpeg`), verify `/usr/bin/ffmpeg` is executable by the PHP-FPM user, and make sure PHP has not disabled `proc_open`. An upload reports an error if the server cannot generate its thumbnail. FFmpeg samples a larger part of the opening footage to avoid choosing a black first frame. Thumbnails are stored outside the public web root and served by the application for catalog cards and the player poster.
- Optional FFmpeg transcoding also requires FFmpeg and `proc_open`; setting `FRAME_REQUIRE_TRANSCODE=1` enables conversion of supported uploads. Files PHP identifies as MPEG transport stream (`video/MP2T`) are automatically converted to MP4 so a mislabeled `.mp4` file is never stored in the wrong container. Transcoding is CPU-intensive and begins after the browser finishes sending the file, so the progress indicator can remain in its processing state for a while, especially on a small VPS. Without FFmpeg, convert that file to a standard MP4 before uploading. Other supported uploads are stored as supplied; playback depends on the viewer's browser supporting the codec.
- Email verification, password recovery, transactional email, automated moderation, malware scanning, and managed backups are not included. Review privacy, retention, legal, and abuse requirements before inviting the public.

## Local development

For local-only testing, use PHP 8.0+ with PDO MySQL. From the directory containing `mediasite.sln`, create a fresh database and run:

```powershell
$env:FRAME_DATA_DIR = Join-Path $PWD 'mediasite-data'
php -S 127.0.0.1:8080 -t .
```

Open `http://127.0.0.1:8080/install.php`. The built-in PHP server is for local testing only, not public hosting. On a VPS, use Virtualmin's PHP-FPM and HTTPS as described above.
