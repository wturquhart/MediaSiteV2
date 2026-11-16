<?php
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
	'httponly' => true,
	'samesite' => 'Lax',
	'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('Permissions-Policy: camera=(), microphone=(), geolocation=()');
header("Content-Security-Policy: default-src 'self'; img-src 'self' data: https://images.unsplash.com; media-src 'self' blob: https://interactive-examples.mdn.mozilla.net; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-ancestors 'none'");
header('Cache-Control: no-store');

const MAX_UPLOAD_BYTES = 1024 * 1024 * 1024;
const UPLOAD_CHUNK_BYTES = 1024 * 1024;
const MAX_ARCHIVE_ENTRIES = 1000;
const MAX_ARCHIVE_VIDEO_FILES = 50;
const MAX_ARCHIVE_EXTRACTED_BYTES = 10 * 1024 * 1024 * 1024;
const MAX_ACCOUNT_UPLOADS = 10;
const MAX_ACCOUNT_STORAGE_BYTES = 5 * 1024 * 1024 * 1024;

$seedVideos = [
	[
		'id' => 'quiet-morning',
		'title' => 'A quiet morning in the mountains',
		'creator' => 'Northbound',
		'category' => 'Nature',
		'duration' => '0:18',
		'views' => '24.8K',
		'description' => 'A little reminder to slow down, take a breath, and enjoy the view.',
		'thumbnail' => 'https://images.unsplash.com/photo-1470770841072-f978cf4d019e?auto=format&fit=crop&w=960&q=85',
		'video_url' => 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
		'uploaded_at' => 3,
	],
	[
		'id' => 'ocean-light',
		'title' => 'Where the ocean meets the light',
		'creator' => 'Maya Chen',
		'category' => 'Travel',
		'duration' => '0:18',
		'views' => '18.2K',
		'description' => 'Golden hour, salt in the air, and nowhere else to be.',
		'thumbnail' => 'https://images.unsplash.com/photo-1500375592092-40eb2168fd21?auto=format&fit=crop&w=960&q=85',
		'video_url' => 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
		'uploaded_at' => 2,
	],
	[
		'id' => 'creative-process',
		'title' => 'Finding a little creative rhythm',
		'creator' => 'Studio Sunday',
		'category' => 'Creative',
		'duration' => '0:18',
		'views' => '9.6K',
		'description' => 'A peek at the small moments that make a creative day.',
		'thumbnail' => 'https://images.unsplash.com/photo-1455390582262-044cdead277a?auto=format&fit=crop&w=960&q=85',
		'video_url' => 'https://interactive-examples.mdn.mozilla.net/media/cc0-videos/flower.mp4',
		'uploaded_at' => 1,
	],
];
$seedVideoIds = array_fill_keys(array_column($seedVideos, 'id'), true);

if (empty($_SESSION['csrf_token'])) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function escapeHtml($value): string
{
	return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function limitText(string $value, int $maxLength): string
{
	if (function_exists('mb_substr')) {
		return mb_substr($value, 0, $maxLength, 'UTF-8');
	}
	if (preg_match('/^.{0,' . $maxLength . '}/us', $value, $matches) === 1) {
		return $matches[0];
	}
	return substr($value, 0, $maxLength);
}

function videoThumbnailUrl(array $video, bool $isAdminPreview = false): string
{
	$thumbnail = (string) ($video['thumbnail'] ?? '');
	if (preg_match('/^uploads\/([a-f0-9]{24}-thumb\.(?:jpg|png|webp))$/D', $thumbnail, $matches) === 1
		&& ((string) ($video['id'] ?? '') . '-thumb.' . pathinfo($matches[1], PATHINFO_EXTENSION)) === $matches[1]) {
		$version = (int) ($video['thumbnail_updated_at'] ?? $video['uploaded_at'] ?? 0);
		return (string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php') . '?thumbnail=' . rawurlencode((string) $video['id']) . '&v=' . $version . ($isAdminPreview ? '&preview=1' : '');
	}
	return $thumbnail;
}

function renderVideoCard(array $video, array $selectedVideo): void
{
	$isCurrent = ($video['id'] ?? '') === ($selectedVideo['id'] ?? '');
	$scriptName = (string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php');
	?>
	<a class="video-card<?= $isCurrent ? ' current' : '' ?>" href="<?= escapeHtml($scriptName) ?>?v=<?= rawurlencode((string) ($video['id'] ?? '')) ?>" data-title="<?= escapeHtml(strtolower((string) ($video['title'] ?? '') . ' ' . (string) ($video['creator'] ?? ''))) ?>" data-category="<?= escapeHtml(strtolower((string) ($video['category'] ?? 'Community'))) ?>" aria-current="<?= $isCurrent ? 'true' : 'false' ?>">
		<div class="thumb">
			<?php if (videoThumbnailUrl($video) !== ''): ?>
				<img src="<?= escapeHtml(videoThumbnailUrl($video)) ?>" alt="" loading="lazy" onerror="this.style.display='none'">
			<?php else: ?>
				<div class="thumb-fallback">Fresh upload</div>
			<?php endif; ?>
			<span class="duration"><?= escapeHtml($video['duration'] ?? 'New') ?></span>
		</div>
		<div class="card-copy">
			<h3><?= escapeHtml($video['title'] ?? 'Untitled video') ?></h3>
			<p><?= escapeHtml($video['creator'] ?? 'Unknown creator') ?> · <?= escapeHtml($video['views'] ?? '0') ?> views</p>
			<span class="category-label"><?= escapeHtml($video['category'] ?? 'Community') ?></span>
		</div>
	</a>
	<?php
}

function isMp4Container(string $path): bool
{
	$fileSize = filesize($path);
	if ($fileSize === false || $fileSize < 16) {
		return false;
	}
	$handle = fopen($path, 'rb');
	if ($handle === false) {
		return false;
	}

	$hasFileType = false;
	$hasMovieIndex = false;
	$offset = 0;
	try {
		while ($offset < $fileSize) {
			$remaining = $fileSize - $offset;
			if ($remaining < 8 || fseek($handle, $offset) !== 0) {
				return false;
			}
			$header = fread($handle, 8);
			if ($header === false || strlen($header) !== 8) {
				return false;
			}
			$boxSize = unpack('N', substr($header, 0, 4))[1];
			$boxType = substr($header, 4, 4);
			$boxHeaderSize = 8;
			if ($boxSize === 1) {
				$extendedHeader = fread($handle, 8);
				if ($extendedHeader === false || strlen($extendedHeader) !== 8) {
					return false;
				}
				$extendedSize = unpack('Nhigh/Nlow', $extendedHeader);
				$boxSize = $extendedSize['high'] * 4294967296 + $extendedSize['low'];
				$boxHeaderSize = 16;
			} elseif ($boxSize === 0) {
				$boxSize = $remaining;
			}
			if ($boxSize < $boxHeaderSize || $boxSize > $remaining) {
				return false;
			}
			if ($boxType === 'ftyp' && $boxSize >= $boxHeaderSize + 8) {
				$hasFileType = true;
			}
			if ($boxType === 'moov' && $boxSize > $boxHeaderSize) {
				$hasMovieIndex = true;
			}
			$offset += $boxSize;
		}
	} finally {
		fclose($handle);
	}

	return $hasFileType && $hasMovieIndex;
}

function dataDirectory(): string
{
	$configured = getenv('FRAME_DATA_DIR');
	return $configured !== false && $configured !== ''
		? rtrim($configured, DIRECTORY_SEPARATOR)
		: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mediasite-data';
}

function uploadDirectory(): string
{
	$configured = getenv('FRAME_UPLOAD_DIR');
	return $configured !== false && $configured !== ''
		? rtrim($configured, DIRECTORY_SEPARATOR)
		: dataDirectory() . DIRECTORY_SEPARATOR . 'uploads';
}

function uploadChunkDirectory(string $userId): string
{
	return dataDirectory() . DIRECTORY_SEPARATOR . 'upload-chunks' . DIRECTORY_SEPARATOR . hash('sha256', $userId);
}

function downloadVideoFromUrl(string $url, string $target, int $maximumBytes): string
{
	if (!function_exists('curl_init')) {
		throw new RuntimeException('URL uploads require the PHP cURL extension. Contact the site administrator.');
	}
	if (strlen($url) > 2048 || preg_match('/[\x00-\x20\x7F]/', $url) === 1) {
		throw new RuntimeException('Enter a valid public video URL.');
	}

	$resolveAddress = static function (string $host): ?string {
		$host = trim($host, '[]');
		$flags = FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE;
		if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
			return filter_var($host, FILTER_VALIDATE_IP, $flags) !== false ? $host : null;
		}
		if (filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) === false
			|| preg_match('/^(?:0x[0-9a-f]+|[0-9]+)(?:\.(?:0x[0-9a-f]+|[0-9]+))*$/i', $host) === 1) {
			return null;
		}
		$records = dns_get_record($host, DNS_A | DNS_AAAA);
		if (!is_array($records) || $records === []) {
			return null;
		}
		$addresses = [];
		foreach ($records as $record) {
			$address = (string) ($record['ip'] ?? $record['ipv6'] ?? '');
			if ($address === '') {
				continue;
			}
			if (filter_var($address, FILTER_VALIDATE_IP, $flags) === false) {
				return null;
			}
			$addresses[] = $address;
		}
		return $addresses[0] ?? null;
	};
	$validateUrl = static function (string $candidate) use ($resolveAddress): array {
		$parts = parse_url($candidate);
		if (!is_array($parts) || !in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
			|| !isset($parts['host']) || isset($parts['user']) || isset($parts['pass'])) {
			throw new RuntimeException('Use a direct public HTTP or HTTPS video URL.');
		}
		$scheme = strtolower((string) $parts['scheme']);
		$rawHost = (string) $parts['host'];
		$host = trim($rawHost, '[]');
		if (str_starts_with($rawHost, '[') && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
			throw new RuntimeException('Use a valid public video URL.');
		}
		$port = (int) ($parts['port'] ?? ($scheme === 'https' ? 443 : 80));
		if (($scheme === 'https' && $port !== 443) || ($scheme === 'http' && $port !== 80)) {
			throw new RuntimeException('The video URL uses an unsupported network port.');
		}
		$address = $resolveAddress($host);
		if ($address === null) {
			throw new RuntimeException('The video URL host could not be resolved to a public server. Private or local network addresses are not allowed.');
		}
		return ['parts' => $parts, 'host' => strtolower($host), 'port' => $port, 'address' => $address];
	};
	$resolveLocation = static function (string $currentUrl, string $location): string {
		$location = trim($location);
		if ($location === '' || preg_match('/[\x00-\x20\x7F]/', $location) === 1) {
			throw new RuntimeException('The video host returned an invalid redirect.');
		}
		if (preg_match('/^https?:\/\//i', $location) === 1) {
			return $location;
		}
		$parts = parse_url($currentUrl);
		if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
			throw new RuntimeException('The video host returned an invalid redirect.');
		}
		$origin = $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
		if (str_starts_with($location, '//')) {
			return $parts['scheme'] . ':' . $location;
		}
		if (str_starts_with($location, '/')) {
			return $origin . $location;
		}
		$path = (string) ($parts['path'] ?? '/');
		if (str_starts_with($location, '?')) {
			return $origin . $path . $location;
		}
		$directory = substr($path, 0, (int) strrpos($path, '/') + 1);
		return $origin . $directory . $location;
	};
	$extractEmbeddedUrl = static function (string $html, string $pageUrl) use ($resolveLocation): ?string {
		if (preg_match_all('/<\\s*(video|source|iframe|meta)\\b([^>]*)>/is', $html, $tags, PREG_SET_ORDER) === false) {
			return null;
		}
		$mediaUrls = [];
		$frameUrls = [];
		$attributePattern = "/([a-zA-Z_:][a-zA-Z0-9_:.-]*)\\s*=\\s*(\\\"[^\\\"]*\\\"|'[^']*'|[^\\s>]+)/";
		foreach ($tags as $tag) {
			$attributes = [];
			if (preg_match_all($attributePattern, $tag[2], $matches, PREG_SET_ORDER) === false) {
				continue;
			}
			foreach ($matches as $attribute) {
				$attributes[strtolower($attribute[1])] = trim($attribute[2], "\\\"'");
			}
			$tagName = strtolower($tag[1]);
			if ($tagName === 'meta') {
				$property = strtolower((string) ($attributes['property'] ?? $attributes['name'] ?? ''));
				if (in_array($property, ['og:video', 'og:video:url', 'og:video:secure_url', 'twitter:player:stream'], true)
					&& isset($attributes['content'])) {
					$mediaUrls[] = $attributes['content'];
				}
				continue;
			}
			$sourceUrl = (string) ($attributes['src'] ?? $attributes['data-src'] ?? '');
			if ($sourceUrl === '') {
				continue;
			}
			if ($tagName === 'iframe') {
				$frameUrls[] = $sourceUrl;
			} else {
				$mediaUrls[] = $sourceUrl;
			}
		}
		foreach (array_merge($mediaUrls, $frameUrls) as $candidate) {
			$candidate = html_entity_decode(trim($candidate), ENT_QUOTES | ENT_HTML5, 'UTF-8');
			if ($candidate !== '') {
				return $resolveLocation($pageUrl, $candidate);
			}
		}
		return null;
	};

	$output = fopen($target, 'wb');
	if ($output === false) {
		throw new RuntimeException('The server could not create a private temporary file for this URL upload.');
	}
	chmod($target, 0600);
	$currentUrl = $url;
	$visitedUrls = [];
	try {
		for ($redirectCount = 0; $redirectCount <= 5; $redirectCount++) {
			if (isset($visitedUrls[$currentUrl])) {
				throw new RuntimeException('The video URL redirected in a loop.');
			}
			$visitedUrls[$currentUrl] = true;
			$destination = $validateUrl($currentUrl);
			if (!ftruncate($output, 0) || fseek($output, 0) !== 0) {
				throw new RuntimeException('The server could not prepare temporary storage for this URL upload.');
			}
			$responseStatus = 0;
			$redirectLocation = '';
			$responseContentType = '';
			$downloadedBytes = 0;
			$exceededLimit = false;
			$pageExceededLimit = false;
			$curl = curl_init($currentUrl);
			if ($curl === false) {
				throw new RuntimeException('The server could not start the URL download.');
			}
			$resolveEntry = $destination['host'] . ':' . $destination['port'] . ':';
			$resolveEntry .= str_contains($destination['address'], ':') ? '[' . $destination['address'] . ']' : $destination['address'];
			$options = [
				CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
				CURLOPT_FOLLOWLOCATION => false,
				CURLOPT_CONNECTTIMEOUT => 15,
				CURLOPT_TIMEOUT => 0,
				CURLOPT_LOW_SPEED_LIMIT => 1024,
				CURLOPT_LOW_SPEED_TIME => 60,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_PROXY => '',
				CURLOPT_USERAGENT => 'FrameVideoImporter/1.0',
				CURLOPT_RESOLVE => filter_var($destination['host'], FILTER_VALIDATE_IP) === false ? [$resolveEntry] : [],
				CURLOPT_HEADERFUNCTION => static function ($handle, string $line) use (&$responseStatus, &$redirectLocation, &$responseContentType): int {
					if (preg_match('/^HTTP\/\S+\s+(\d{3})/', $line, $matches) === 1) {
						$responseStatus = (int) $matches[1];
						$redirectLocation = '';
						$responseContentType = '';
					} elseif (stripos($line, 'Location:') === 0) {
						$redirectLocation = trim(substr($line, strlen('Location:')));
					} elseif (stripos($line, 'Content-Type:') === 0) {
						$responseContentType = trim(substr($line, strlen('Content-Type:')));
					}
					return strlen($line);
				},
				CURLOPT_WRITEFUNCTION => static function ($handle, string $contents) use ($output, &$downloadedBytes, &$exceededLimit, &$pageExceededLimit, &$responseStatus, &$responseContentType, $maximumBytes): int {
					if ($responseStatus !== 200) {
						return strlen($contents);
					}
					$length = strlen($contents);
					$isHtmlPage = preg_match('/^(?:text\/html|application\/xhtml\\+xml)\\b/i', $responseContentType) === 1;
					$byteLimit = $isHtmlPage ? min($maximumBytes, 2 * 1024 * 1024) : $maximumBytes;
					if ($downloadedBytes + $length > $byteLimit) {
						$exceededLimit = true;
						$pageExceededLimit = $isHtmlPage;
						return 0;
					}
					$written = 0;
					while ($written < $length) {
						$count = fwrite($output, substr($contents, $written));
						if ($count === false || $count === 0) {
							return 0;
						}
						$written += $count;
					}
					$downloadedBytes += $length;
					return $length;
				},
			];
			try {
				if (!curl_setopt_array($curl, $options)) {
					throw new RuntimeException('The server could not configure the URL download.');
				}
				$result = curl_exec($curl);
				$responseStatus = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
			} finally {
				curl_close($curl);
			}
			if ($exceededLimit) {
				throw new RuntimeException($pageExceededLimit ? 'The video page is too large to inspect for an embedded video.' : 'URL uploads cannot be larger than 1 GB.');
			}
			if ($result === false) {
				throw new RuntimeException('The server could not complete the video download. Confirm the URL is public and contains a downloadable video.');
			}
			if (in_array($responseStatus, [301, 302, 303, 307, 308], true) && $redirectLocation !== '') {
				if ($redirectCount === 5) {
					throw new RuntimeException('The video URL redirected too many times.');
				}
				$currentUrl = $resolveLocation($currentUrl, $redirectLocation);
				continue;
			}
			if ($responseStatus !== 200) {
				throw new RuntimeException('The video URL returned HTTP ' . $responseStatus . '. Use a direct public link to a video file.');
			}
			if ($downloadedBytes <= 0 || !fflush($output)) {
				throw new RuntimeException('The video URL returned an empty file or could not be saved.');
			}
			if (preg_match('/^(?:text\/html|application\/xhtml\\+xml)\\b/i', $responseContentType) === 1) {
				$pageContents = file_get_contents($target);
				$embeddedUrl = $pageContents === false ? null : $extractEmbeddedUrl($pageContents, $currentUrl);
				if ($embeddedUrl === null) {
					throw new RuntimeException('This page does not expose a downloadable embedded video. Protected or streaming-only video pages are not supported; use a direct video file URL.');
				}
				$currentUrl = $embeddedUrl;
				continue;
			}
			break;
		}
		if ($redirectCount > 5) {
			throw new RuntimeException('The video URL redirected too many times.');
		}
	} catch (Throwable $exception) {
		fclose($output);
		if (is_file($target) && !unlink($target)) {
			error_log('Failed to remove a rejected URL upload: ' . $target);
		}
		if ($exception instanceof RuntimeException) {
			throw $exception;
		}
		throw new RuntimeException('The server could not download this video URL.');
	}
	fclose($output);
	$parts = parse_url($currentUrl);
	$fileName = basename(rawurldecode((string) ($parts['path'] ?? '')));
	$fileName = trim(str_replace('\\', '', $fileName));
	$fileName = (string) preg_replace('/[\x00-\x1F\x7F]/', '', $fileName);
	return $fileName !== '' ? substr($fileName, 0, 255) : 'video';
}

function database(): PDO
{
	static $connection = null;
	if ($connection instanceof PDO) {
		return $connection;
	}

	$directory = dataDirectory();
	if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
		throw new RuntimeException('The private data folder could not be created.');
	}
	$configPath = $directory . DIRECTORY_SEPARATOR . 'database-config.json';
	$storedConfig = [];
	if (is_file($configPath)) {
		$configContents = file_get_contents($configPath);
		$storedConfig = json_decode($configContents === false ? '' : $configContents, true);
		if (!is_array($storedConfig)) {
			throw new RuntimeException('The private database configuration is invalid.');
		}
	}
	$environmentValue = static function (string $key, string $environmentName, string $default = '') use ($storedConfig): string {
		$value = getenv($environmentName);
		return $value !== false && $value !== '' ? $value : (string) ($storedConfig[$key] ?? $default);
	};
	$host = $environmentValue('host', 'FRAME_DB_HOST', '127.0.0.1');
	$port = $environmentValue('port', 'FRAME_DB_PORT', '3306');
	$name = $environmentValue('name', 'FRAME_DB_NAME');
	$user = $environmentValue('user', 'FRAME_DB_USER');
	$password = $environmentValue('password', 'FRAME_DB_PASSWORD');
	if (!preg_match('/^[A-Za-z0-9.-]+$/', $host) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535
		|| !preg_match('/^[A-Za-z0-9_]+$/', $name)
		|| !preg_match('/^[A-Za-z0-9_]+$/', $user)
		|| $password === '') {
		throw new RuntimeException('MySQL is not configured. Complete setup at install.php or set FRAME_DB_HOST, FRAME_DB_PORT, FRAME_DB_NAME, FRAME_DB_USER, and FRAME_DB_PASSWORD.');
	}
	$connection = new PDO('mysql:host=' . $host . ';port=' . $port . ';dbname=' . $name . ';charset=utf8mb4', $user, $password, [
		PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
		PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
		PDO::ATTR_EMULATE_PREPARES => false,
	]);
	$connection->exec('CREATE TABLE IF NOT EXISTS app_data (data_key VARCHAR(191) NOT NULL PRIMARY KEY, data_value LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
	$connection->exec('CREATE TABLE IF NOT EXISTS rate_limits (rate_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, attempts INT NOT NULL, window_started BIGINT NOT NULL) ENGINE=InnoDB');

	$legacyPublicVideoFile = __DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'videos.json';
	if (is_file($legacyPublicVideoFile)) {
		$legacyPrivateFile = $directory . DIRECTORY_SEPARATOR . 'legacy-videos.json';
		if (is_file($legacyPrivateFile)) {
			$legacyPrivateFile = $directory . DIRECTORY_SEPARATOR . 'legacy-videos-public.json';
		}
		if (!rename($legacyPublicVideoFile, $legacyPrivateFile)) {
			throw new RuntimeException('The existing video index could not be moved to private storage.');
		}
	}
	migrateSqliteDatabase($connection, $directory);
	acquireDatabaseLock($connection, 'frame_json_migration');
	try {
		$migrationQuery = $connection->prepare('SELECT 1 FROM app_data WHERE data_key = :key');
		$migrationQuery->execute(['key' => '__legacy_json_migrated__']);
		if ($migrationQuery->fetchColumn() === false) {
			$connection->beginTransaction();
			try {
				foreach (['users.json', 'settings.json', 'videos.json', 'legacy-videos.json', 'legacy-videos-public.json'] as $legacyName) {
					$legacyPath = $directory . DIRECTORY_SEPARATOR . $legacyName;
					if (!is_file($legacyPath)) {
						continue;
					}
					$legacyContents = file_get_contents($legacyPath);
					$legacyValue = json_decode($legacyContents === false ? '' : $legacyContents, true);
					if (!is_array($legacyValue)) {
						throw new RuntimeException('The existing ' . $legacyName . ' data file is invalid.');
					}
					$key = str_starts_with($legacyName, 'legacy-videos') ? 'videos.json' : $legacyName;
					$existingQuery = $connection->prepare('SELECT data_value FROM app_data WHERE data_key = :key');
					$existingQuery->execute(['key' => $key]);
					$existingValue = $existingQuery->fetchColumn();
					if ($existingValue !== false && $key === 'videos.json') {
						$existingVideos = json_decode((string) $existingValue, true);
						if (is_array($existingVideos)) {
							$knownIds = array_column($existingVideos, 'id');
							$legacyValue = array_merge($existingVideos, array_values(array_filter($legacyValue, static fn(array $video): bool => !in_array($video['id'] ?? '', $knownIds, true))));
						}
					}
					$write = $connection->prepare('INSERT INTO app_data (data_key, data_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE data_value = VALUES(data_value)');
					$write->execute(['key' => $key, 'value' => json_encode($legacyValue, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
				}
				$marker = $connection->prepare('INSERT INTO app_data (data_key, data_value) VALUES (:key, :value)');
				$marker->execute(['key' => '__legacy_json_migrated__', 'value' => '[]']);
				$connection->commit();
			} catch (Throwable $exception) {
				if ($connection->inTransaction()) {
					$connection->rollBack();
				}
				throw $exception;
			}
		}
	} finally {
		releaseDatabaseLock($connection, 'frame_json_migration');
	}

	return $connection;
}

function acquireDatabaseLock(PDO $connection, string $name): void
{
	$statement = $connection->prepare('SELECT GET_LOCK(:lock_name, 10)');
	$statement->execute(['lock_name' => $name]);
	if ((int) $statement->fetchColumn() !== 1) {
		throw new RuntimeException('The MySQL database is busy. Please retry the request.');
	}
}

function releaseDatabaseLock(PDO $connection, string $name): void
{
	$statement = $connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
	$statement->execute(['lock_name' => $name]);
}

function migrateSqliteDatabase(PDO $connection, string $directory): void
{
	$legacyPath = $directory . DIRECTORY_SEPARATOR . 'frame.sqlite';
	if (!is_file($legacyPath)) {
		return;
	}
	acquireDatabaseLock($connection, 'frame_sqlite_import');
	try {
		$markerQuery = $connection->prepare('SELECT 1 FROM app_data WHERE data_key = :key');
		$markerQuery->execute(['key' => '__sqlite_imported__']);
		if ($markerQuery->fetchColumn() !== false) {
			return;
		}
		if (!in_array('sqlite', PDO::getAvailableDrivers(), true)) {
			throw new RuntimeException('An existing SQLite database was found, but PDO SQLite is unavailable for importing it.');
		}
		$legacy = new PDO('sqlite:' . $legacyPath, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
		$connection->beginTransaction();
		try {
			$hasApplicationData = (int) $connection->query('SELECT COUNT(*) FROM app_data')->fetchColumn() > 0;
			if (!$hasApplicationData && $legacy->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'app_data'")->fetchColumn() !== false) {
				$copy = $connection->prepare('INSERT INTO app_data (data_key, data_value) VALUES (:key, :value)');
				foreach ($legacy->query('SELECT data_key, data_value FROM app_data') as $row) {
					$copy->execute(['key' => $row['data_key'], 'value' => $row['data_value']]);
				}
				if ($legacy->query("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = 'rate_limits'")->fetchColumn() !== false) {
					$copyRate = $connection->prepare('INSERT INTO rate_limits (rate_key, attempts, window_started) VALUES (:key, :attempts, :started)');
					foreach ($legacy->query('SELECT rate_key, attempts, window_started FROM rate_limits') as $row) {
						$copyRate->execute(['key' => $row['rate_key'], 'attempts' => $row['attempts'], 'started' => $row['window_started']]);
					}
				}
			}
			$marker = $connection->prepare('INSERT INTO app_data (data_key, data_value) VALUES (:key, :value)');
			$marker->execute(['key' => '__sqlite_imported__', 'value' => $hasApplicationData ? 'skipped' : 'imported']);
			$connection->commit();
		} catch (Throwable $exception) {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
			throw $exception;
		}
	} finally {
		releaseDatabaseLock($connection, 'frame_sqlite_import');
	}
}

function loadUploads(): array
{
	return readJsonFile('videos.json');
}

function streamUploadedVideo(array $video): void
{
	$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
	if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
		header('Allow: GET, HEAD');
		http_response_code(405);
		exit;
	}
	$url = (string) ($video['video_url'] ?? '');
	if (preg_match('/^uploads\/([a-f0-9]{24}\.(mp4|webm|ogv))$/D', $url, $matches) !== 1) {
		http_response_code(404);
		exit;
	}

	$uploadRoot = realpath(uploadDirectory());
	$filePath = realpath(uploadDirectory() . DIRECTORY_SEPARATOR . $matches[1]);
	if ($uploadRoot === false || $filePath === false || !is_file($filePath)
		|| !str_starts_with(strtolower($filePath), strtolower(rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
		http_response_code(404);
		exit;
	}

	$fileSize = filesize($filePath);
	if ($fileSize === false || $fileSize <= 0) {
		http_response_code(404);
		exit;
	}

	$contentTypes = ['mp4' => 'video/mp4', 'webm' => 'video/webm', 'ogv' => 'video/ogg'];
	$extension = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));
	$start = 0;
	$end = $fileSize - 1;
	$statusCode = 200;
	$range = (string) ($_SERVER['HTTP_RANGE'] ?? '');
	if ($range !== '') {
		if (preg_match('/^bytes=(\d*)-(\d*)$/', $range, $rangeMatch) !== 1 || ($rangeMatch[1] === '' && $rangeMatch[2] === '')) {
			header('Content-Range: bytes */' . $fileSize);
			http_response_code(416);
			exit;
		}
		if ($rangeMatch[1] === '') {
			$suffixLength = (int) $rangeMatch[2];
			if ($suffixLength <= 0) {
				header('Content-Range: bytes */' . $fileSize);
				http_response_code(416);
				exit;
			}
			$start = max(0, $fileSize - $suffixLength);
		} else {
			$start = (int) $rangeMatch[1];
			$end = $rangeMatch[2] === '' ? $end : min((int) $rangeMatch[2], $end);
		}
		if ($start >= $fileSize || $end < $start) {
			header('Content-Range: bytes */' . $fileSize);
			http_response_code(416);
			exit;
		}
		$statusCode = 206;
	}

	if (session_status() === PHP_SESSION_ACTIVE) {
		session_write_close();
	}
	set_time_limit(0);
	while (ob_get_level() > 0) {
		ob_end_clean();
	}
	ini_set('zlib.output_compression', '0');
	header('Content-Type: ' . $contentTypes[$extension]);
	header('X-Content-Type-Options: nosniff');
	header('Accept-Ranges: bytes');
	header('Cache-Control: public, max-age=3600');
	header('Content-Length: ' . ($end - $start + 1));
	if ($statusCode === 206) {
		http_response_code(206);
		header('Content-Range: bytes ' . $start . '-' . $end . '/' . $fileSize);
	}
	if ($requestMethod === 'HEAD') {
		exit;
	}

	$handle = fopen($filePath, 'rb');
	if ($handle === false || fseek($handle, $start) !== 0) {
		if (is_resource($handle)) {
			fclose($handle);
		}
		exit;
	}
	$remaining = $end - $start + 1;
	while ($remaining > 0 && !feof($handle)) {
		$chunk = fread($handle, min(1024 * 1024, $remaining));
		if ($chunk === false || $chunk === '') {
			break;
		}
		echo $chunk;
		$remaining -= strlen($chunk);
		flush();
	}
	fclose($handle);
	exit;
}

function streamUploadedThumbnail(array $video): void
{
	$requestMethod = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
	if (!in_array($requestMethod, ['GET', 'HEAD'], true)) {
		header('Allow: GET, HEAD');
		http_response_code(405);
		exit;
	}
	$thumbnail = (string) ($video['thumbnail'] ?? '');
	$videoId = (string) ($video['id'] ?? '');
	if (preg_match('/^uploads\/([a-f0-9]{24}-thumb\.(jpg|png|webp))$/D', $thumbnail, $matches) !== 1
		|| ($videoId . '-thumb.' . $matches[2]) !== $matches[1]) {
		http_response_code(404);
		exit;
	}
	$uploadRoot = realpath(uploadDirectory());
	$filePath = realpath(uploadDirectory() . DIRECTORY_SEPARATOR . $matches[1]);
	if ($uploadRoot === false || $filePath === false || !is_file($filePath)
		|| !str_starts_with(strtolower($filePath), strtolower(rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
		http_response_code(404);
		exit;
	}
	$fileSize = filesize($filePath);
	if ($fileSize === false || $fileSize <= 0) {
		http_response_code(404);
		exit;
	}
	$contentTypes = ['jpg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'];
	if (session_status() === PHP_SESSION_ACTIVE) {
		session_write_close();
	}
	header('Content-Type: ' . $contentTypes[$matches[2]]);
	header('X-Content-Type-Options: nosniff');
	header('Cache-Control: public, max-age=3600');
	header('Content-Length: ' . $fileSize);
	if ($requestMethod === 'HEAD') {
		exit;
	}
	readfile($filePath);
	exit;
}

function generateRequiredVideoThumbnail(string $videoSource, string $videoId): string
{
	$failureReason = '';
	$thumbnailUrl = generateVideoThumbnail($videoSource, $videoId, $failureReason);
	if ($thumbnailUrl === '') {
		throw new RuntimeException('Thumbnail generation failed: ' . ($failureReason !== '' ? $failureReason : 'the generated image could not be verified.'));
	}
	return $thumbnailUrl;
}

function generateVideoThumbnail(string $videoSource, string $videoId, ?string &$failureReason = null): string
{
	$failureReason = '';
	$binary = getenv('FRAME_FFMPEG_BINARY');
	$binary = $binary === false || $binary === '' ? '/usr/bin/ffmpeg' : $binary;
	if (!function_exists('proc_open')) {
		$failureReason = 'PHP has disabled proc_open. Enable it for the PHP-FPM/web-server configuration.';
		return '';
	}
	if (!is_executable($binary)) {
		$failureReason = 'FFmpeg is missing or not executable by PHP at ' . $binary . '. Install FFmpeg or set FRAME_FFMPEG_BINARY to its executable path.';
		return '';
	}
	$uploadDirectory = uploadDirectory();
	$filename = $videoId . '-thumb.jpg';
	$target = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
	$logFile = tempnam(dataDirectory(), 'thumbnail-ffmpeg-');
	if ($logFile === false) {
		throw new RuntimeException('A private thumbnail-processing log could not be created.');
	}
	$command = [
		$binary, '-nostdin', '-y', '-hide_banner', '-loglevel', 'error', '-i', $videoSource,
		'-map', '0:v:0', '-vf', 'thumbnail=300,scale=1280:720:force_original_aspect_ratio=decrease', '-frames:v', '1',
		'-q:v', '4', '-an', '-sn', '-dn', '-f', 'image2', $target,
	];
	$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes);
	if (!is_resource($process)) {
		unlink($logFile);
		throw new RuntimeException('PHP could not start FFmpeg. Check the PHP-FPM user and server execution policy.');
	}
	$startedAt = time();
	$exitCode = null;
	while (true) {
		$status = proc_get_status($process);
		if (!$status['running']) {
			$exitCode = (int) $status['exitcode'];
			break;
		}
		if (time() - $startedAt > 60) {
			proc_terminate($process, 9);
			proc_close($process);
			unlink($logFile);
			if (is_file($target)) {
				unlink($target);
			}
			$failureReason = 'FFmpeg took longer than 60 seconds to read this video. Check server load and the video file.';
			return '';
		}
		usleep(100000);
	}
	proc_close($process);
	$diagnostic = trim((string) @file_get_contents($logFile));
	unlink($logFile);
	if ($exitCode !== 0 || !is_file($target) || (int) @filesize($target) <= 0) {
		if (is_file($target)) {
			unlink($target);
		}
		$diagnostic = trim((string) preg_replace('/[\r\n\t]+/', ' ', $diagnostic));
		$failureReason = 'FFmpeg could not read this video (exit code ' . $exitCode . ').' . ($diagnostic !== '' ? ' ' . limitText($diagnostic, 300) : ' Verify the video file and PHP-FPM storage permissions.');
		return '';
	}
	return 'uploads/' . $filename;
}

function storedVideoBytes(array $video): int
{
	$url = (string) ($video['video_url'] ?? '');
	if (preg_match('/^uploads\\/([a-f0-9]{24}\\.(?:mp4|webm|ogv))$/D', $url, $matches) !== 1) {
		return 0;
	}
	$path = uploadDirectory() . DIRECTORY_SEPARATOR . $matches[1];
	$size = is_file($path) ? filesize($path) : false;
	return $size === false ? max(0, (int) ($video['size_bytes'] ?? 0)) : $size;
}

function storeUploadedVideo(string $source, string $target, string $id, bool $transcode, bool $isTransportStream, bool $allowLargeOutput): bool
{
	if (!$transcode) {
		if (is_uploaded_file($source)) {
			return move_uploaded_file($source, $target);
		}
		if (!copy($source, $target)) {
			return false;
		}
		if (!unlink($source)) {
			unlink($target);
			return false;
		}
		return true;
	}

	$binary = getenv('FRAME_FFMPEG_BINARY');
	$binary = $binary === false || $binary === '' ? '/usr/bin/ffmpeg' : $binary;
	if (!is_executable($binary) || !function_exists('proc_open')) {
		if ($isTransportStream) {
			throw new RuntimeException('This upload is an MPEG transport stream rather than an MP4 file. Install FFmpeg on the server to convert it during upload, or convert the video to a standard MP4 before uploading.');
		}
		throw new RuntimeException('Video processing is unavailable. Contact the site administrator.');
	}
	$processingDirectory = dataDirectory() . DIRECTORY_SEPARATOR . 'processing';
	if (!is_dir($processingDirectory) && !mkdir($processingDirectory, 0700, true) && !is_dir($processingDirectory)) {
		throw new RuntimeException('The private video-processing folder could not be created.');
	}
	$temporaryOutput = tempnam($processingDirectory, $id . '-');
	if ($temporaryOutput === false) {
		throw new RuntimeException('A temporary video-processing file could not be created.');
	}
	$logFile = tempnam($processingDirectory, 'ffmpeg-');
	if ($logFile === false) {
		unlink($temporaryOutput);
		throw new RuntimeException('A private video-processing log could not be created.');
	}
	$command = [
		$binary, '-nostdin', '-y', '-hide_banner', '-loglevel', 'error', '-i', $source,
		'-map', '0:v:0', '-map', '0:a:0?', '-sn', '-dn',
		'-vf', "scale=w='min(1920,iw)':h='min(1080,ih)':force_original_aspect_ratio=decrease:force_divisible_by=2",
		'-c:v', 'libx264', '-preset', 'veryfast', '-crf', '23', '-pix_fmt', 'yuv420p', '-threads', '2',
		'-c:a', 'aac', '-b:a', '128k', '-movflags', '+faststart',
	];
	if (!$allowLargeOutput) {
		$command[] = '-fs';
		$command[] = (string) MAX_UPLOAD_BYTES;
	}
	array_push($command, '-f', 'mp4', $temporaryOutput);
	$process = proc_open($command, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $logFile, 'a'], 2 => ['file', $logFile, 'a']], $pipes);
	if (!is_resource($process)) {
		unlink($temporaryOutput);
		unlink($logFile);
		throw new RuntimeException('The video-processing process could not be started.');
	}
	$startedAt = time();
	$exitCode = null;
	while (true) {
		$status = proc_get_status($process);
		if (!$status['running']) {
			$exitCode = (int) $status['exitcode'];
			break;
		}
		if (time() - $startedAt > 1800) {
			proc_terminate($process, 9);
			proc_close($process);
			unlink($temporaryOutput);
			unlink($logFile);
			throw new RuntimeException('Video processing exceeded the 30-minute limit.');
		}
		usleep(250000);
	}
	proc_close($process);
	$validOutput = $exitCode === 0 && is_file($temporaryOutput) && filesize($temporaryOutput) > 0;
	unlink($logFile);
	if (!$validOutput) {
		unlink($temporaryOutput);
		throw new RuntimeException('This video could not be processed. Try a standard video file or contact the site administrator.');
	}
	if (!rename($temporaryOutput, $target)) {
		unlink($temporaryOutput);
		throw new RuntimeException('The processed video could not be moved into storage.');
	}
	return true;
}

function extractVideoArchive(string $archivePath, string $temporaryDirectory, bool $isAdmin): array
{
	if (!class_exists(ZipArchive::class)) {
		throw new RuntimeException('ZIP uploads require the PHP ZIP extension. Contact the site administrator.');
	}
	if (!is_dir($temporaryDirectory) && !mkdir($temporaryDirectory, 0700, true) && !is_dir($temporaryDirectory)) {
		throw new RuntimeException('The server could not create private temporary upload storage.');
	}
	$archive = new ZipArchive();
	$opened = $archive->open($archivePath, ZipArchive::CHECKCONS);
	if ($opened !== true) {
		throw new RuntimeException('The uploaded ZIP archive is invalid or could not be opened.');
	}

	$stagedFiles = [];
	$successful = false;
	try {
		if ($archive->numFiles > MAX_ARCHIVE_ENTRIES) {
			throw new RuntimeException('The ZIP archive contains too many entries.');
		}
		$seenNames = [];
		$totalDeclaredBytes = 0;
		for ($index = 0; $index < $archive->numFiles; $index++) {
			$entry = $archive->statIndex($index);
			if (!is_array($entry) || !is_string($entry['name'] ?? null)) {
				throw new RuntimeException('The ZIP archive contains an invalid entry.');
			}
			$entryName = $entry['name'];
			if (isset($seenNames[$entryName])) {
				throw new RuntimeException('The ZIP archive contains duplicate file names.');
			}
			$seenNames[$entryName] = true;
			$normalizedName = str_replace('\\', '/', $entryName);
			$fileName = basename($normalizedName);
			if ($fileName === '' || str_ends_with($normalizedName, '/')) {
				continue;
			}
			$extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
			if (!in_array($extension, ['mp4', 'webm', 'ogv', 'ogg'], true)) {
				continue;
			}
			if (strlen($fileName) > 255 || preg_match('/[\\x00-\\x1F\\x7F]/', $fileName) === 1 || preg_match('//u', $fileName) !== 1) {
				throw new RuntimeException('A video in the ZIP archive has an invalid file name.');
			}
			if (count($stagedFiles) >= MAX_ARCHIVE_VIDEO_FILES) {
				throw new RuntimeException('A ZIP archive can contain no more than ' . MAX_ARCHIVE_VIDEO_FILES . ' supported video files.');
			}
			$entrySize = $entry['size'] ?? null;
			if (!is_int($entrySize) || $entrySize <= 0) {
				throw new RuntimeException('A video in the ZIP archive is empty or has an invalid size.');
			}
			if (!$isAdmin && $entrySize > MAX_UPLOAD_BYTES) {
				throw new RuntimeException('A video in the ZIP archive exceeds the 1 GB per-video limit.');
			}
			if ($entrySize > MAX_ARCHIVE_EXTRACTED_BYTES - $totalDeclaredBytes) {
				throw new RuntimeException('The ZIP archive expands beyond the 10 GB extraction limit.');
			}
			$totalDeclaredBytes += $entrySize;
			$input = $archive->getStream($entryName);
			if ($input === false) {
				throw new RuntimeException('A video in the ZIP archive could not be read.');
			}
			$temporaryPath = tempnam($temporaryDirectory, 'archive-video-');
			if ($temporaryPath === false) {
				fclose($input);
				throw new RuntimeException('The server could not create temporary storage for an archive video.');
			}
			chmod($temporaryPath, 0600);
			$output = fopen($temporaryPath, 'wb');
			if ($output === false) {
				fclose($input);
				unlink($temporaryPath);
				throw new RuntimeException('The server could not write an archive video to temporary storage.');
			}
			$writtenBytes = 0;
			$entryError = '';
			try {
				while (!feof($input)) {
					$contents = fread($input, 1024 * 1024);
					if ($contents === false) {
						$entryError = 'A video in the ZIP archive could not be read.';
						break;
					}
					if ($contents === '') {
						if (!feof($input)) {
							$entryError = 'A video in the ZIP archive could not be read completely.';
						}
						break;
					}
					$length = strlen($contents);
					if ($writtenBytes + $length > $entrySize || $writtenBytes + $length > MAX_ARCHIVE_EXTRACTED_BYTES) {
						$entryError = 'A video in the ZIP archive expands beyond its declared size.';
						break;
					}
					$offset = 0;
					while ($offset < $length) {
						$count = fwrite($output, substr($contents, $offset));
						if ($count === false || $count === 0) {
							$entryError = 'The server could not write an archive video to temporary storage.';
							break 2;
						}
						$offset += $count;
					}
					$writtenBytes += $length;
				}
				if ($entryError === '' && ($writtenBytes !== $entrySize || !fflush($output))) {
					$entryError = 'A video in the ZIP archive is incomplete or could not be saved.';
				}
			} finally {
				fclose($input);
				fclose($output);
			}
			if ($entryError !== '') {
				unlink($temporaryPath);
				throw new RuntimeException($entryError);
			}
			$stagedFiles[] = ['name' => $fileName, 'tmp_name' => $temporaryPath, 'size' => $writtenBytes];
		}
		if ($stagedFiles === []) {
			throw new RuntimeException('No supported video files were found in the ZIP archive. Include MP4, WebM, or Ogg videos.');
		}
		$successful = true;
		return $stagedFiles;
	} finally {
		$archive->close();
		if (!$successful) {
			foreach ($stagedFiles as $stagedFile) {
				if (is_file($stagedFile['tmp_name']) && !unlink($stagedFile['tmp_name'])) {
					error_log('Failed to remove a rejected archive video: ' . $stagedFile['tmp_name']);
				}
			}
		}
	}
}

function saveUploadedVideo(array $file, array $currentUser, string $uploadCategory, string $title, string $creator): string
{
	if ((int) ($file['size'] ?? 0) <= 0) {
		throw new RuntimeException('Choose a non-empty video file.');
	}
	$isAdmin = ($currentUser['role'] ?? '') === 'admin';
	if (!$isAdmin && (int) $file['size'] > MAX_UPLOAD_BYTES) {
		throw new RuntimeException('Choose a video file no larger than 1 GB.');
	}
	$mimeTypes = [
		'video/mp4' => 'mp4',
		'application/mp4' => 'mp4',
		'video/x-m4v' => 'mp4',
		'video/webm' => 'webm',
		'video/ogg' => 'ogv',
		'application/ogg' => 'ogv',
		'video/mp2t' => 'ts',
	];
	$detectedMime = strtolower((string) (new finfo(FILEINFO_MIME_TYPE))->file((string) $file['tmp_name']));
	$originalExtension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
	$videoExtension = $mimeTypes[$detectedMime] ?? null;
	$isMp4Candidate = $videoExtension === 'mp4' || ($videoExtension === null && $originalExtension === 'mp4');
	if ($isMp4Candidate && !isMp4Container((string) $file['tmp_name'])) {
		throw new RuntimeException('This MP4 file appears incomplete or damaged (its required moov/index atom is missing). Re-export or remux it as a standard MP4 and upload it again.');
	}
	if ($isMp4Candidate) {
		$videoExtension = 'mp4';
	}
	if ($videoExtension === null) {
		throw new RuntimeException('Use a supported MP4, WebM, or Ogg video file. The server identified this file as ' . ($detectedMime ?: 'an unknown format') . '.');
	}

	$incomingBytes = (int) $file['size'];
	$quotaError = uploadQuotaError((string) $currentUser['id'], $incomingBytes, $isAdmin);
	if ($quotaError !== '') {
		throw new RuntimeException($quotaError);
	}
	$uploadDirectory = uploadDirectory();
	if (!is_dir($uploadDirectory) && !mkdir($uploadDirectory, 0750, true) && !is_dir($uploadDirectory)) {
		throw new RuntimeException('The upload folder could not be created. Check the configured upload directory permissions.');
	}
	$id = bin2hex(random_bytes(12));
	$transcoding = getenv('FRAME_REQUIRE_TRANSCODE') === '1' || $videoExtension === 'ts';
	$filename = $id . '.' . ($transcoding ? 'mp4' : $videoExtension);
	$target = $uploadDirectory . DIRECTORY_SEPARATOR . $filename;
	$thumbnailTarget = null;
	try {
		if (!storeUploadedVideo((string) $file['tmp_name'], $target, $id, $transcoding, $videoExtension === 'ts', $isAdmin)) {
			throw new RuntimeException('The video could not be saved. Check the configured storage permissions and try again.');
		}
		$savedBytes = filesize($target);
		if ($savedBytes === false || $savedBytes <= 0) {
			throw new RuntimeException('The saved video is empty or could not be verified.');
		}
		$thumbnailUrl = generateRequiredVideoThumbnail($target, $id);
		$thumbnailTarget = $uploadDirectory . DIRECTORY_SEPARATOR . basename($thumbnailUrl);
		$video = [
			'id' => $id,
			'user_id' => $currentUser['id'],
			'size_bytes' => $savedBytes,
			'title' => limitText($title !== '' ? $title : 'Untitled video', 100),
			'creator' => limitText($creator !== '' ? $creator : (string) $currentUser['name'], 50),
			'category' => $uploadCategory,
			'duration' => 'New',
			'views' => '0',
			'description' => 'Shared with the community.',
			'thumbnail' => $thumbnailUrl,
			'video_url' => 'uploads/' . $filename,
			'uploaded_at' => time(),
		];
		saveUpload($video, $isAdmin);
		return 'index.php?v=' . rawurlencode($id) . '&uploaded=1';
	} catch (Throwable $exception) {
		if (is_file($target) && !unlink($target)) {
			error_log('Failed to remove an incomplete uploaded video: ' . $target);
		}
		if ($thumbnailTarget !== null && is_file($thumbnailTarget) && !unlink($thumbnailTarget)) {
			error_log('Failed to remove an incomplete uploaded thumbnail: ' . $thumbnailTarget);
		}
		throw $exception;
	}
}

function saveUpload(array $video, bool $isAdmin): void
{
	updateJsonFile('videos.json', static function (array $videos) use ($video, $isAdmin): array {
		$quotaError = uploadQuotaError((string) $video['user_id'], (int) $video['size_bytes'], $isAdmin);
		if ($quotaError !== '') {
			throw new RuntimeException($quotaError);
		}
		$videos[] = $video;
		return $videos;
	});
}

function uploadQuotaError(string $userId, int $incomingBytes, bool $isAdmin = false): string
{
	if ($isAdmin) {
		return '';
	}

	$accountUploads = 0;
	$accountBytes = 0;
	$totalBytes = 0;
	foreach (loadUploads() as $video) {
		$bytes = storedVideoBytes($video);
		$totalBytes += $bytes;
		if (($video['user_id'] ?? '') === $userId) {
			$accountUploads++;
			$accountBytes += $bytes;
		}
	}
	$storageLimit = getenv('FRAME_STORAGE_LIMIT_BYTES');
	$storageLimit = $storageLimit === false || $storageLimit === '' ? 53687091200 : (int) $storageLimit;
	if ($accountUploads >= MAX_ACCOUNT_UPLOADS) {
		return 'You have reached the limit of ' . MAX_ACCOUNT_UPLOADS . ' uploaded videos.';
	}
	if ($accountBytes + $incomingBytes > MAX_ACCOUNT_STORAGE_BYTES) {
		return 'This upload would exceed your 5 GB video-storage allowance.';
	}
	if ($storageLimit <= 0 || $totalBytes + $incomingBytes > $storageLimit) {
		return 'The site has reached its video-storage limit. Contact the site administrator.';
	}
	return '';
}

function readJsonFile(string $filename): array
{
	$statement = database()->prepare('SELECT data_value FROM app_data WHERE data_key = :key');
	$statement->execute(['key' => $filename]);
	$contents = $statement->fetchColumn();
	if ($contents === false) {
		return [];
	}
	$decoded = json_decode((string) $contents, true);
	if (!is_array($decoded)) {
		throw new RuntimeException('The ' . $filename . ' data is invalid.');
	}
	return $decoded;
}

function categoryNameLength(string $name): int
{
	if (function_exists('mb_strlen')) {
		return mb_strlen($name, 'UTF-8');
	}
	$count = preg_match_all('/./us', $name, $matches);
	return $count === false ? PHP_INT_MAX : $count;
}

function resolveCategoryAlias(string $category, array $aliases): string
{
	$visited = [];
	while (isset($aliases[$category]) && is_string($aliases[$category]) && !isset($visited[$category])) {
		$visited[$category] = true;
		$category = $aliases[$category];
	}
	return $category;
}

function effectiveVideoCategories(array $configured, array $videos, array $seedVideos, array $aliases): array
{
	$categories = [];
	foreach (array_merge($configured, array_column($videos, 'category'), array_column($seedVideos, 'category')) as $category) {
		if (!is_string($category)) {
			continue;
		}
		$category = trim(resolveCategoryAlias($category, $aliases));
		$alreadyListed = false;
		foreach ($categories as $listedCategory) {
			if (strcasecmp($listedCategory, $category) === 0) {
				$alreadyListed = true;
				break;
			}
		}
		if ($category === '' || $alreadyListed) {
			continue;
		}
		$categories[] = $category;
	}
	$hasCommunity = false;
	foreach ($categories as $index => $category) {
		if (strcasecmp($category, 'Community') === 0) {
			$categories[$index] = 'Community';
			$hasCommunity = true;
			break;
		}
	}
	if (!$hasCommunity) {
		array_unshift($categories, 'Community');
	}
	return $categories;
}

function updateVideoAndSettingsData(callable $update): array
{
	$connection = database();
	$lockNames = array_map(static fn(string $filename): string => 'frame_data_' . substr(hash('sha256', $filename), 0, 48), ['settings.json', 'videos.json']);
	sort($lockNames, SORT_STRING);
	$acquiredLocks = [];
	try {
		foreach ($lockNames as $lockName) {
			acquireDatabaseLock($connection, $lockName);
			$acquiredLocks[] = $lockName;
		}
		$connection->beginTransaction();
		try {
			$records = [];
			$read = $connection->prepare('SELECT data_value FROM app_data WHERE data_key = :key');
			foreach (['settings.json', 'videos.json'] as $filename) {
				$read->execute(['key' => $filename]);
				$contents = $read->fetchColumn();
				$records[$filename] = $contents === false ? [] : json_decode((string) $contents, true);
				if (!is_array($records[$filename])) {
					throw new RuntimeException('The ' . $filename . ' data is invalid.');
				}
			}
			$updated = $update($records['settings.json'], $records['videos.json']);
			if (!is_array($updated['settings'] ?? null) || !is_array($updated['videos'] ?? null)) {
				throw new RuntimeException('The video and settings update returned invalid data.');
			}
			$write = $connection->prepare('INSERT INTO app_data (data_key, data_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE data_value = VALUES(data_value)');
			foreach (['settings.json' => $updated['settings'], 'videos.json' => $updated['videos']] as $filename => $value) {
				$write->execute(['key' => $filename, 'value' => json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
			}
			$connection->commit();
			return $updated;
		} catch (Throwable $exception) {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
			throw $exception;
		}
	} finally {
		foreach (array_reverse($acquiredLocks) as $lockName) {
			releaseDatabaseLock($connection, $lockName);
		}
	}
}

function updateJsonFile(string $filename, callable $update): array
{
	$connection = database();
	$lockName = 'frame_data_' . substr(hash('sha256', $filename), 0, 48);
	acquireDatabaseLock($connection, $lockName);
	try {
		$connection->beginTransaction();
		try {
			$statement = $connection->prepare('SELECT data_value FROM app_data WHERE data_key = :key');
			$statement->execute(['key' => $filename]);
			$contents = $statement->fetchColumn();
			$records = $contents === false ? [] : json_decode((string) $contents, true);
			if (!is_array($records)) {
				throw new RuntimeException('The ' . $filename . ' data is invalid.');
			}
			$updated = $update($records);
			if (!is_array($updated)) {
				throw new RuntimeException('The ' . $filename . ' update returned invalid data.');
			}
			$json = json_encode($updated, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
			$write = $connection->prepare('INSERT INTO app_data (data_key, data_value) VALUES (:key, :value) ON DUPLICATE KEY UPDATE data_value = VALUES(data_value)');
			$write->execute(['key' => $filename, 'value' => $json]);
			$connection->commit();
			return $updated;
		} catch (Throwable $exception) {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
			throw $exception;
		}
	} finally {
		releaseDatabaseLock($connection, $lockName);
	}
}

function rateLimitExceeded(string $action, string $subject, int $maximumAttempts, int $windowSeconds): bool
{
	$clientAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
	$key = hash('sha256', $action . "\0" . $clientAddress . "\0" . strtolower($subject));
	$now = time();
	$connection = database();
	$lockName = 'frame_rate_' . substr($key, 0, 48);
	acquireDatabaseLock($connection, $lockName);
	try {
		$connection->beginTransaction();
		try {
			$statement = $connection->prepare('SELECT attempts, window_started FROM rate_limits WHERE rate_key = :key');
			$statement->execute(['key' => $key]);
			$record = $statement->fetch();
			$attempts = !$record || $now - (int) $record['window_started'] >= $windowSeconds ? 1 : (int) $record['attempts'] + 1;
			$windowStarted = !$record || $now - (int) $record['window_started'] >= $windowSeconds ? $now : (int) $record['window_started'];
			$write = $connection->prepare('INSERT INTO rate_limits (rate_key, attempts, window_started) VALUES (:key, :attempts, :started) ON DUPLICATE KEY UPDATE attempts = VALUES(attempts), window_started = VALUES(window_started)');
			$write->execute(['key' => $key, 'attempts' => $attempts, 'started' => $windowStarted]);
			$connection->commit();
			return $attempts > $maximumAttempts;
		} catch (Throwable $exception) {
			if ($connection->inTransaction()) {
				$connection->rollBack();
			}
			throw $exception;
		}
	} finally {
		releaseDatabaseLock($connection, $lockName);
	}
}

function redirectTo(string $page = 'home', string $notice = '', string $tab = ''): void
{
	$location = 'index.php';
	$query = [];
	if ($page !== 'home') {
		$query['page'] = $page;
	}
	if ($tab !== '') {
		$query['tab'] = $tab;
	}
	if ($notice !== '') {
		$query['notice'] = $notice;
	}
	header('Location: ' . $location . ($query ? '?' . http_build_query($query) : ''));
	exit;
}

function makeUser(string $name, string $email, string $password, string $role): array
{
	return [
		'id' => bin2hex(random_bytes(12)),
		'name' => $name,
		'email' => strtolower($email),
		'password_hash' => password_hash($password, PASSWORD_DEFAULT),
		'role' => $role,
		'created_at' => time(),
	];
}

$users = readJsonFile('users.json');
$defaultVideoCategories = ['Community', 'Nature', 'Travel', 'Creative', 'Music', 'People', 'Education'];
$settings = array_merge([
	'site_name' => 'frame',
	'announcement' => '',
	'uploads_enabled' => true,
	'hidden_videos' => [],
	'deleted_seed_videos' => [],
	'seed_video_categories' => [],
	'categories' => $defaultVideoCategories,
	'category_aliases' => [],
], readJsonFile('settings.json'));
if (!is_array($settings['categories'])) {
	$settings['categories'] = $defaultVideoCategories;
}
$settings['categories'] = array_values(array_filter($settings['categories'], static fn($category): bool => is_string($category) && trim($category) !== ''));
if (!is_array($settings['category_aliases'])) {
	$settings['category_aliases'] = [];
}
$deletedSeedVideoIds = is_array($settings['deleted_seed_videos']) ? $settings['deleted_seed_videos'] : [];
$seedVideoCategories = is_array($settings['seed_video_categories']) ? $settings['seed_video_categories'] : [];
$seedVideos = array_values(array_filter($seedVideos, static fn(array $video): bool => !in_array($video['id'], $deletedSeedVideoIds, true)));
$categoryOptions = effectiveVideoCategories($settings['categories'], loadUploads(), $seedVideos, $settings['category_aliases']);
$currentUser = null;
foreach ($users as $user) {
	if (isset($user['id']) && hash_equals((string) $user['id'], (string) ($_SESSION['user_id'] ?? ''))) {
		$currentUser = $user;
		break;
	}
}
if ($currentUser === null) {
	unset($_SESSION['user_id']);
}

$page = (string) ($_GET['page'] ?? 'home');
$adminTab = (string) ($_GET['tab'] ?? 'settings');
if (!in_array($adminTab, ['settings', 'categories', 'accounts', 'videos'], true)) {
	$adminTab = 'settings';
}
$isHomeGallery = $page === 'home' && !isset($_GET['v']);
$actionError = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
	$postedToken = (string) ($_POST['csrf_token'] ?? '');
	if (!hash_equals($_SESSION['csrf_token'] ?? '', $postedToken)) {
		$actionError = 'Your session expired. Refresh the page and try again.';
	} else {
		$action = (string) ($_POST['action'] ?? '');
		try {
			if ($action === 'register') {
				if (rateLimitExceeded($action, 'all', 10, 3600)) {
					throw new RuntimeException('Too many account requests. Try again in about an hour.');
				}
				$name = trim((string) ($_POST['name'] ?? ''));
				$email = strtolower(trim((string) ($_POST['email'] ?? '')));
				$password = (string) ($_POST['password'] ?? '');
				if (strlen($name) < 2 || strlen($name) > 40 || !preg_match('/^[\\p{L}\\p{N} _.-]+$/u', $name)) {
					throw new RuntimeException('Use a name between 2 and 40 letters, numbers, spaces, dots, underscores, or hyphens.');
				}
				if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
					throw new RuntimeException('Enter a valid email address.');
				}
				if (strlen($password) < 12 || strlen($password) > 72) {
					throw new RuntimeException('Use a password between 12 and 72 bytes.');
				}
				$users = updateJsonFile('users.json', static function (array $storedUsers) use ($email, $name, $password): array {
					foreach ($storedUsers as $storedUser) {
						if (strtolower((string) ($storedUser['email'] ?? '')) === $email) {
							throw new RuntimeException('An account with that email already exists.');
						}
					}
					$storedUsers[] = makeUser($name, $email, $password, 'member');
					return $storedUsers;
				});
				$newUser = end($users);
				session_regenerate_id(true);
				$_SESSION['user_id'] = $newUser['id'];
				redirectTo('home', 'registered');
			} elseif ($action === 'login') {
				if (rateLimitExceeded('login', 'all', 10, 900)) {
					throw new RuntimeException('Too many sign-in attempts. Try again in 15 minutes.');
				}
				$email = strtolower(trim((string) ($_POST['email'] ?? '')));
				$password = (string) ($_POST['password'] ?? '');
				$matchedUser = null;
				foreach ($users as $user) {
					if (strtolower((string) ($user['email'] ?? '')) === $email && password_verify($password, (string) ($user['password_hash'] ?? ''))) {
						$matchedUser = $user;
						break;
					}
				}
				if ($matchedUser === null) {
					throw new RuntimeException('Email or password is incorrect.');
				}
				session_regenerate_id(true);
				$_SESSION['user_id'] = $matchedUser['id'];
				redirectTo(($matchedUser['role'] ?? '') === 'admin' ? 'admin' : 'home', 'logged_in');
			} elseif ($action === 'logout') {
				$_SESSION = [];
				session_regenerate_id(true);
				$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
				$currentUser = null;
				redirectTo('home', 'logged_out');
			} elseif ($action === 'save_profile') {
				if ($currentUser === null) {
					throw new RuntimeException('Sign in before editing your profile.');
				}
				$nameValue = $_POST['name'] ?? '';
				$bioValue = $_POST['bio'] ?? '';
				if (!is_string($nameValue) || !is_string($bioValue)) {
					throw new RuntimeException('Enter a valid profile name and bio.');
				}
				$name = trim($nameValue);
				$bio = trim($bioValue);
				if (strlen($name) < 2 || strlen($name) > 40 || preg_match('/^[\\p{L}\\p{N} _.-]+$/u', $name) !== 1) {
					throw new RuntimeException('Use a profile name between 2 and 40 letters, numbers, spaces, dots, underscores, or hyphens.');
				}
				if (strlen($bio) > 1120 || preg_match('//u', $bio) !== 1 || preg_match('/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F\\x7F]/', $bio) === 1 || categoryNameLength($bio) > 280) {
					throw new RuntimeException('Keep your profile bio to 280 characters and do not include control characters.');
				}
				$userId = (string) $currentUser['id'];
				$users = updateJsonFile('users.json', static function (array $storedUsers) use ($userId, $name, $bio): array {
					foreach ($storedUsers as &$storedUser) {
						if (($storedUser['id'] ?? '') === $userId) {
							$storedUser['name'] = $name;
							$storedUser['bio'] = $bio;
							unset($storedUser);
							return $storedUsers;
						}
					}
					unset($storedUser);
					throw new RuntimeException('That account no longer exists. Sign in again and retry.');
				});
				foreach ($users as $user) {
					if (($user['id'] ?? '') === $userId) {
						$currentUser = $user;
						break;
					}
				}
				header('Location: index.php?page=profile&id=' . rawurlencode($userId) . '&notice=profile_saved', true, 303);
				exit;
			} elseif ($action === 'save_settings' || str_starts_with($action, 'admin_')) {
				if (($currentUser['role'] ?? '') !== 'admin') {
					throw new RuntimeException('Administrator access is required.');
				}
				if ($action === 'admin_category') {
					$mode = (string) ($_POST['category_mode'] ?? '');
					$oldCategory = trim((string) ($_POST['category'] ?? ''));
					$newCategory = trim((string) ($_POST['name'] ?? ''));
					if (!in_array($mode, ['add', 'rename', 'delete'], true)) {
						throw new RuntimeException('Choose a valid category action.');
					}
					if (in_array($mode, ['add', 'rename'], true)
						&& ($newCategory === '' || strlen($newCategory) > 120 || preg_match('//u', $newCategory) !== 1 || preg_match('/[\p{C}]/u', $newCategory) === 1 || categoryNameLength($newCategory) > 30)) {
						throw new RuntimeException('Enter a category name of 1 to 30 characters without control characters.');
					}
					$noticeKey = 'category_added';
					$updatedData = updateVideoAndSettingsData(static function (array $storedSettings, array $storedVideos) use ($mode, $oldCategory, $newCategory, $seedVideos, &$noticeKey): array {
						$categories = is_array($storedSettings['categories'] ?? null) ? $storedSettings['categories'] : ['Community', 'Nature', 'Travel', 'Creative', 'Music', 'People', 'Education'];
						$categories = array_values(array_map('trim', array_filter($categories, static fn($category): bool => is_string($category) && trim($category) !== '')));
						$aliases = is_array($storedSettings['category_aliases'] ?? null) ? $storedSettings['category_aliases'] : [];
						$effective = effectiveVideoCategories($categories, $storedVideos, $seedVideos, $aliases);
						if ($mode === 'add') {
							foreach ($effective as $category) {
								if (strcasecmp($category, $newCategory) === 0) {
									throw new RuntimeException('A category with that name already exists.');
								}
							}
							$categories[] = $newCategory;
						} else {
							$matchedCategory = null;
							foreach ($effective as $category) {
								if (strcasecmp($category, $oldCategory) === 0) {
									$matchedCategory = $category;
									break;
								}
							}
							if ($matchedCategory === null) {
								throw new RuntimeException('That category no longer exists. Refresh the page and try again.');
							}
							if (strcasecmp($matchedCategory, 'Community') === 0) {
								throw new RuntimeException('The built-in Community category cannot be renamed or deleted.');
							}
							$replacement = 'Community';
							if ($mode === 'rename') {
								foreach ($effective as $category) {
									if (strcasecmp($category, $matchedCategory) !== 0 && strcasecmp($category, $newCategory) === 0) {
										throw new RuntimeException('A category with that name already exists.');
									}
								}
								$replacement = $newCategory;
								$noticeKey = 'category_renamed';
							} else {
								$noticeKey = 'category_deleted';
							}
							$foundConfiguredCategory = false;
							$updatedCategories = [];
							foreach ($categories as $category) {
								if (strcasecmp($category, $matchedCategory) !== 0) {
									$updatedCategories[] = $category;
									continue;
								}
								$foundConfiguredCategory = true;
								if ($mode === 'rename') {
									$updatedCategories[] = $replacement;
								}
							}
							if ($mode === 'rename' && !$foundConfiguredCategory) {
								$updatedCategories[] = $replacement;
							}
							$categories = $updatedCategories;
							foreach ($storedVideos as &$video) {
								if (strcasecmp(trim((string) ($video['category'] ?? '')), $matchedCategory) === 0) {
									$video['category'] = $replacement;
								}
							}
							unset($video);
							foreach ($aliases as $alias => $target) {
								if (is_string($target) && strcasecmp($target, $matchedCategory) === 0) {
									$aliases[$alias] = $replacement;
								}
							}
							$aliases[$matchedCategory] = $replacement;
						}
						$storedSettings['categories'] = $categories;
						$storedSettings['category_aliases'] = $aliases;
						return ['settings' => $storedSettings, 'videos' => $storedVideos];
					});
					$settings = $updatedData['settings'];
					redirectTo('admin', $noticeKey, 'categories');
				} elseif ($action === 'save_settings') {
					$siteName = trim((string) ($_POST['site_name'] ?? ''));
					$announcement = trim((string) ($_POST['announcement'] ?? ''));
					if ($siteName === '' || strlen($siteName) > 40 || strlen($announcement) > 240) {
						throw new RuntimeException('Enter a site name (up to 40 characters) and an announcement up to 240 characters.');
					}
					$settings = updateJsonFile('settings.json', static function (array $stored) use ($siteName, $announcement): array {
						$stored['site_name'] = $siteName;
						$stored['announcement'] = $announcement;
						$stored['uploads_enabled'] = isset($_POST['uploads_enabled']);
						return $stored;
					});
					redirectTo('admin', 'settings_saved', 'settings');
				} elseif ($action === 'admin_create_user') {
					$name = trim((string) ($_POST['name'] ?? ''));
					$email = strtolower(trim((string) ($_POST['email'] ?? '')));
					$password = (string) ($_POST['password'] ?? '');
					$role = (string) ($_POST['role'] ?? 'member');
					if (strlen($name) < 2 || strlen($name) > 40 || preg_match('/^[\\p{L}\\p{N} _.-]+$/u', $name) !== 1) {
						throw new RuntimeException('Use a name between 2 and 40 letters, numbers, spaces, dots, underscores, or hyphens.');
					}
					if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 254) {
						throw new RuntimeException('Enter a valid email address.');
					}
					if (strlen($password) < 12 || strlen($password) > 72) {
						throw new RuntimeException('Use a password between 12 and 72 bytes.');
					}
					if (!in_array($role, ['admin', 'member'], true)) {
						throw new RuntimeException('Choose a valid account role.');
					}
					$users = updateJsonFile('users.json', static function (array $storedUsers) use ($email, $name, $password, $role): array {
						foreach ($storedUsers as $storedUser) {
							if (strtolower((string) ($storedUser['email'] ?? '')) === $email) {
								throw new RuntimeException('An account with that email already exists.');
							}
						}
						$storedUsers[] = makeUser($name, $email, $password, $role);
						return $storedUsers;
					});
					redirectTo('admin', 'user_created', 'accounts');
				} elseif ($action === 'admin_role') {
					$targetId = (string) ($_POST['user_id'] ?? '');
					$role = (string) ($_POST['role'] ?? '');
					if (!in_array($role, ['admin', 'member'], true)) {
						throw new RuntimeException('Choose a valid account role.');
					}
					$users = updateJsonFile('users.json', static function (array $storedUsers) use ($targetId, $role): array {
						$target = null;
						foreach ($storedUsers as $index => $user) {
							if (($user['id'] ?? '') === $targetId) {
								$target = $index;
							}
						}
						if ($target === null) {
							throw new RuntimeException('That account no longer exists.');
						}
						if ($storedUsers[$target]['role'] === 'admin' && $role !== 'admin' && count(array_filter($storedUsers, static fn(array $user): bool => ($user['role'] ?? '') === 'admin')) <= 1) {
							throw new RuntimeException('The last administrator cannot be demoted.');
						}
						$storedUsers[$target]['role'] = $role;
						return $storedUsers;
					});
					redirectTo('admin', 'users_saved', 'accounts');
				} elseif ($action === 'admin_delete_user') {
					$targetId = (string) ($_POST['user_id'] ?? '');
					if ($targetId === (string) ($currentUser['id'] ?? '')) {
						throw new RuntimeException('You cannot delete the account you are currently using.');
					}
					$users = updateJsonFile('users.json', static function (array $storedUsers) use ($targetId): array {
						$target = null;
						foreach ($storedUsers as $index => $user) {
							if (($user['id'] ?? '') === $targetId) {
								$target = $index;
							}
						}
						if ($target === null) {
							throw new RuntimeException('That account no longer exists.');
						}
						if (($storedUsers[$target]['role'] ?? '') === 'admin' && count(array_filter($storedUsers, static fn(array $user): bool => ($user['role'] ?? '') === 'admin')) <= 1) {
							throw new RuntimeException('The last administrator cannot be deleted.');
						}
						array_splice($storedUsers, $target, 1);
						return $storedUsers;
					});
					redirectTo('admin', 'user_deleted', 'accounts');
				} elseif ($action === 'admin_delete_videos') {
					$requestedIds = $_POST['video_ids'] ?? null;
					if (!is_array($requestedIds) || $requestedIds === []) {
						throw new RuntimeException('Select at least one video to delete.');
					}
					$videoIds = [];
					foreach ($requestedIds as $requestedId) {
						if (!is_string($requestedId) || preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $requestedId) !== 1) {
							throw new RuntimeException('The selected video list is invalid. Refresh the page and try again.');
						}
						$videoIds[] = $requestedId;
					}
					$videoIds = array_values(array_unique($videoIds));
					$selectedIds = array_fill_keys($videoIds, true);
					$deletedVideos = [];
					$updatedData = updateVideoAndSettingsData(static function (array $storedSettings, array $storedVideos) use ($selectedIds, $seedVideoIds, &$deletedVideos): array {
						$selectedUploadIds = array_diff_key($selectedIds, $seedVideoIds);
						$remainingVideos = [];
						foreach ($storedVideos as $storedVideo) {
							$storedId = (string) ($storedVideo['id'] ?? '');
							if (isset($selectedUploadIds[$storedId])) {
								$deletedVideos[] = $storedVideo;
							} else {
								$remainingVideos[] = $storedVideo;
							}
						}
						$deletedIds = array_fill_keys(array_map(static fn(array $video): string => (string) ($video['id'] ?? ''), $deletedVideos), true);
						if (array_diff_key($selectedUploadIds, $deletedIds) !== []) {
							throw new RuntimeException('One or more selected uploaded videos no longer exist. Refresh the page and try again.');
						}
						$hidden = is_array($storedSettings['hidden_videos'] ?? null) ? $storedSettings['hidden_videos'] : [];
						$storedSettings['hidden_videos'] = array_values(array_filter($hidden, static fn($id): bool => !isset($selectedIds[(string) $id])));
						$deletedSeedIds = is_array($storedSettings['deleted_seed_videos'] ?? null) ? $storedSettings['deleted_seed_videos'] : [];
						$deletedSeedIds = array_filter($deletedSeedIds, static fn($id): bool => is_string($id) && isset($seedVideoIds[$id]));
						$storedSettings['deleted_seed_videos'] = array_values(array_unique(array_merge($deletedSeedIds, array_keys(array_intersect_key($selectedIds, $seedVideoIds)))));
						return ['settings' => $storedSettings, 'videos' => $remainingVideos];
					});
					$settings = $updatedData['settings'];
					$filesToDelete = [];
					foreach ($deletedVideos as $deletedVideo) {
						$videoId = (string) ($deletedVideo['id'] ?? '');
						$videoUrl = (string) ($deletedVideo['video_url'] ?? '');
						if (preg_match('/^uploads\/([a-f0-9]{24}\.(?:mp4|webm|ogv))$/D', $videoUrl, $matches) === 1
							&& $videoId === pathinfo($matches[1], PATHINFO_FILENAME)) {
							$filesToDelete[] = $matches[1];
						}
						$thumbnail = (string) ($deletedVideo['thumbnail'] ?? '');
						if (preg_match('/^uploads\/([a-f0-9]{24}-thumb\.(?:jpg|png|webp))$/D', $thumbnail, $matches) === 1
							&& $videoId . '-thumb.' . pathinfo($matches[1], PATHINFO_EXTENSION) === $matches[1]) {
							$filesToDelete[] = $matches[1];
						}
					}
					if ($filesToDelete !== []) {
						$uploadRoot = realpath(uploadDirectory());
						if ($uploadRoot === false) {
							throw new RuntimeException('Video records were deleted, but their private media folder could not be found.');
						}
						foreach (array_unique($filesToDelete) as $filename) {
							$filePath = realpath($uploadRoot . DIRECTORY_SEPARATOR . $filename);
							if ($filePath === false) {
								continue;
							}
							if (!is_file($filePath) || !str_starts_with(strtolower($filePath), strtolower(rtrim($uploadRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR))) {
								throw new RuntimeException('Video records were deleted, but a media file path could not be verified.');
							}
							if (!unlink($filePath)) {
								throw new RuntimeException('Video records were deleted, but a media file could not be removed. Check private storage permissions.');
							}
						}
					}
					redirectTo('admin', 'videos_deleted', 'videos');
				} elseif ($action === 'admin_video_category') {
					$videoId = (string) ($_POST['video_id'] ?? '');
					$newCategory = trim((string) ($_POST['category'] ?? ''));
					if (preg_match('/^[A-Za-z0-9_-]{1,64}$/D', $videoId) !== 1) {
						throw new RuntimeException('The selected video is invalid. Refresh the page and try again.');
					}
					$updatedData = updateVideoAndSettingsData(static function (array $storedSettings, array $storedVideos) use ($videoId, $newCategory, $seedVideos): array {
						$categories = is_array($storedSettings['categories'] ?? null) ? $storedSettings['categories'] : ['Community', 'Nature', 'Travel', 'Creative', 'Music', 'People', 'Education'];
						$aliases = is_array($storedSettings['category_aliases'] ?? null) ? $storedSettings['category_aliases'] : [];
						$availableCategories = effectiveVideoCategories($categories, $storedVideos, $seedVideos, $aliases);
						if (!in_array($newCategory, $availableCategories, true)) {
							throw new RuntimeException('Choose one of the available video categories.');
						}
						$videoFound = false;
						foreach ($storedVideos as &$storedVideo) {
							if (($storedVideo['id'] ?? '') === $videoId) {
								$storedVideo['category'] = $newCategory;
								$videoFound = true;
								break;
							}
						}
						unset($storedVideo);
						if (!$videoFound) {
							foreach ($seedVideos as $seedVideo) {
								if (($seedVideo['id'] ?? '') === $videoId) {
									$seedOverrides = is_array($storedSettings['seed_video_categories'] ?? null) ? $storedSettings['seed_video_categories'] : [];
									$seedOverrides[$videoId] = $newCategory;
									$storedSettings['seed_video_categories'] = $seedOverrides;
									$videoFound = true;
									break;
								}
							}
						}
						if (!$videoFound) {
							throw new RuntimeException('That video no longer exists. Refresh the page and try again.');
						}
						return ['settings' => $storedSettings, 'videos' => $storedVideos];
					});
					$settings = $updatedData['settings'];
					redirectTo('admin', 'video_category_saved', 'videos');
				} elseif ($action === 'admin_video') {
					$videoId = (string) ($_POST['video_id'] ?? '');
					$hide = (string) ($_POST['visibility'] ?? '') === 'hide';
					$settings = updateJsonFile('settings.json', static function (array $stored) use ($videoId, $hide): array {
						$hidden = is_array($stored['hidden_videos'] ?? null) ? $stored['hidden_videos'] : [];
						$hidden = array_values(array_filter($hidden, static fn($id): bool => $id !== $videoId));
						if ($hide) {
							$hidden[] = $videoId;
						}
						$stored['hidden_videos'] = $hidden;
						return $stored;
					});
					redirectTo('admin', 'video_saved', 'videos');
				}
			}
		} catch (RuntimeException $exception) {
			$actionError = $exception->getMessage();
		}
	}
}

if (isset($_SESSION['csrf_token']) === false) {
	$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$uploadError = $actionError;
$postMaxSize = trim((string) ini_get('post_max_size'));
$postMaxBytes = 0;
if (preg_match('/^(\d+)\s*([KMG])?$/i', $postMaxSize, $postMaxMatch) === 1) {
	$postMaxPower = match (strtoupper($postMaxMatch[2] ?? '')) {
		'K' => 1,
		'M' => 2,
		'G' => 3,
		default => 0,
	};
	$postMaxBytes = (int) $postMaxMatch[1] * (1024 ** $postMaxPower);
}
$contentLength = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_POST) && empty($_FILES)) {
	$uploadError = 'The server rejected a video part because the request exceeded PHP post_max_size (' . $postMaxSize . '). Allow at least 2M for each 1 MB part, including request overhead.';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_chunk') {
	header('Content-Type: application/json; charset=utf-8');
	$respondToChunk = static function (string $message, int $status = 400): void {
		http_response_code($status);
		echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
		exit;
	};
	$postedString = static function (string $key, string $default = ''): string|false {
		$value = $_POST[$key] ?? $default;
		return is_string($value) ? $value : false;
	};
	$postedInteger = static function (string $key): int|false {
		$value = $_POST[$key] ?? null;
		if (!is_string($value) && !is_int($value)) {
			return false;
		}
		$validated = filter_var($value, FILTER_VALIDATE_INT);
		return $validated === false ? false : $validated;
	};
	$postedToken = $postedString('csrf_token');
	if ($postedToken === false || !hash_equals((string) $_SESSION['csrf_token'], $postedToken)) {
		$respondToChunk('Your upload session expired. Refresh the page and try again.', 403);
	}
	if ($currentUser === null) {
		$respondToChunk('Sign in before sharing a video.', 403);
	}
	if (empty($settings['uploads_enabled'])) {
		$respondToChunk('Video uploads are temporarily disabled by the site administrator.', 403);
	}
	$uploadId = $postedString('upload_id');
	$chunkIndex = $postedInteger('chunk_index');
	$chunkCount = $postedInteger('chunk_count');
	$totalFileSize = $postedInteger('file_size');
	$uploadCategory = $postedString('category', 'Community');
	$fileName = $postedString('file_name');
	$title = $postedString('title');
	$creator = $postedString('creator');
	if ($uploadId === false || $uploadCategory === false || $fileName === false || $title === false || $creator === false
		|| preg_match('/^[a-f0-9]{32}$/D', $uploadId) !== 1
		|| $chunkIndex === false || $chunkCount === false || $totalFileSize === false
		|| $chunkIndex < 0 || $chunkCount < 1 || $totalFileSize < 1
		|| $chunkCount !== (int) ceil($totalFileSize / UPLOAD_CHUNK_BYTES)
		|| $chunkIndex >= $chunkCount) {
		$respondToChunk('The upload request is invalid. Refresh the page and try again.');
	}
	$uploadCategory = trim($uploadCategory);
	$uploadCategory = $uploadCategory !== '' ? $uploadCategory : 'Community';
	if (!in_array($uploadCategory, $categoryOptions, true)) {
		$respondToChunk('Choose one of the available video categories.');
	}
	if ($fileName === '' || strlen($fileName) > 255 || preg_match('/[\x00-\x1F\x7F]/', $fileName) === 1 || strlen($title) > 400 || strlen($creator) > 200) {
		$respondToChunk('The video details are invalid. Refresh the page and try again.');
	}
	if (!isset($_FILES['chunk']) || !is_array($_FILES['chunk'])) {
		$respondToChunk('A video part did not reach the server. Check the connection and try again.', 503);
	}
	$chunkUploadError = $_FILES['chunk']['error'] ?? UPLOAD_ERR_NO_FILE;
	if (!is_int($chunkUploadError)) {
		$respondToChunk('The video part request is invalid. Refresh the page and try again.');
	}
	if ($chunkUploadError !== UPLOAD_ERR_OK) {
		$chunkErrorMessage = match ($chunkUploadError) {
			UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The server upload_max_filesize must allow a 1 MB video part. Ask the site administrator to increase it to at least 2M.',
			UPLOAD_ERR_PARTIAL => 'A video part arrived incomplete. Please retry the part.',
			UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its temporary upload folder. Contact the site administrator.',
			UPLOAD_ERR_CANT_WRITE => 'The server could not write a video part. Check temporary storage and disk space.',
			default => 'A video part did not reach the server. Check the connection and try again.',
		};
		$respondToChunk($chunkErrorMessage, $chunkUploadError === UPLOAD_ERR_PARTIAL ? 503 : 400);
	}
	$expectedOffset = $chunkIndex * UPLOAD_CHUNK_BYTES;
	$expectedChunkSize = min(UPLOAD_CHUNK_BYTES, $totalFileSize - $expectedOffset);
	$chunkTemporaryFile = $_FILES['chunk']['tmp_name'] ?? '';
	$actualChunkSize = $_FILES['chunk']['size'] ?? null;
	if (!is_string($chunkTemporaryFile) || !is_int($actualChunkSize) || !is_uploaded_file($chunkTemporaryFile)) {
		$respondToChunk('The video part request is invalid. Refresh the page and try again.');
	}
	$chunkContents = file_get_contents($chunkTemporaryFile);
	if (!is_string($chunkContents) || strlen($chunkContents) !== $expectedChunkSize || $actualChunkSize !== $expectedChunkSize) {
		$respondToChunk('A video part was incomplete. The upload will retry it automatically.', 503);
	}
	$userId = (string) $currentUser['id'];
	$chunkDirectory = uploadChunkDirectory($userId);
	if (!is_dir($chunkDirectory) && !mkdir($chunkDirectory, 0700, true) && !is_dir($chunkDirectory)) {
		$respondToChunk('The server could not create private temporary upload storage. Contact the site administrator.', 500);
	}
	if ($chunkIndex === 0) {
		$expirationTime = time() - 7 * 86400;
		$chunkEntries = scandir($chunkDirectory);
		if ($chunkEntries === false) {
			$respondToChunk('The server could not inspect temporary upload storage. Check its permissions.', 500);
		}
		foreach ($chunkEntries as $chunkEntry) {
			if (preg_match('/^(?:[a-f0-9]{32}\.(?:part|json|done\.json)|archive-video-[A-Za-z0-9]+)$/D', $chunkEntry) === 1) {
				$stalePath = $chunkDirectory . DIRECTORY_SEPARATOR . $chunkEntry;
				$modifiedAt = is_file($stalePath) ? filemtime($stalePath) : false;
				if ($modifiedAt !== false && $modifiedAt < $expirationTime && !unlink($stalePath)) {
					error_log('Failed to remove a stale upload temporary file: ' . $stalePath);
				}
			}
		}
	}
	$partPath = $chunkDirectory . DIRECTORY_SEPARATOR . $uploadId . '.part';
	$manifestPath = $chunkDirectory . DIRECTORY_SEPARATOR . $uploadId . '.json';
	$receiptPath = $chunkDirectory . DIRECTORY_SEPARATOR . $uploadId . '.done.json';
	if (is_file($receiptPath)) {
		$receipt = json_decode((string) file_get_contents($receiptPath), true);
		if (is_array($receipt) && is_string($receipt['redirect'] ?? null)) {
			echo json_encode($receipt, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
			exit;
		}
	}
	$manifest = [
		'file_size' => $totalFileSize,
		'chunk_count' => $chunkCount,
		'file_name' => $fileName,
		'title' => $title,
		'creator' => $creator,
		'category' => $uploadCategory,
	];
	$isNewUpload = !is_file($manifestPath);
	if ($isNewUpload && $chunkIndex !== 0) {
		$respondToChunk('The first video part is missing. Start the upload again.');
	}
	if ($isNewUpload && $chunkIndex === 0) {
		$isAdmin = ($currentUser['role'] ?? '') === 'admin';
		if (!$isAdmin && rateLimitExceeded('upload', $userId, 10, 3600)) {
			$respondToChunk('You have reached the hourly upload limit. Try again later.', 429);
		}
		$isZipArchive = strtolower(pathinfo($fileName, PATHINFO_EXTENSION)) === 'zip';
		if (!$isAdmin && $totalFileSize > MAX_UPLOAD_BYTES) {
			$respondToChunk($isZipArchive ? 'Choose a ZIP archive no larger than 1 GB.' : 'Choose a video file no larger than 1 GB.');
		}
		if (!$isZipArchive) {
			$quotaError = uploadQuotaError($userId, $totalFileSize, $isAdmin);
			if ($quotaError !== '') {
				$respondToChunk($quotaError, 413);
			}
		}
	}
	$handle = fopen($partPath, 'c+b');
	if ($handle === false) {
		$respondToChunk('The server could not open temporary upload storage. Check its permissions.', 500);
	}
	$chunkError = '';
	$chunkReceived = false;
	if (!flock($handle, LOCK_EX)) {
		fclose($handle);
		$respondToChunk('The server could not lock temporary upload storage. Please try again.', 500);
	}
	try {
		if (is_file($manifestPath)) {
			$storedManifest = json_decode((string) file_get_contents($manifestPath), true);
			if (!is_array($storedManifest) || $storedManifest !== $manifest) {
				throw new RuntimeException('The upload details changed during transfer. Start the upload again.');
			}
		} else {
			$manifestContents = json_encode($manifest, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
			if (file_put_contents($manifestPath, $manifestContents, LOCK_EX) !== strlen($manifestContents)) {
				throw new RuntimeException('The server could not save upload details. Check private storage permissions.');
			}
			chmod($manifestPath, 0600);
		}
		$storedSize = (int) (fstat($handle)['size'] ?? 0);
		if ($storedSize < $expectedOffset) {
			throw new RuntimeException('A previous video part is missing. Start the upload again.');
		}
		if ($storedSize >= $expectedOffset + $expectedChunkSize) {
			if (fseek($handle, $expectedOffset) !== 0 || fread($handle, $expectedChunkSize) !== $chunkContents) {
				throw new RuntimeException('A retried video part did not match the saved part. Start the upload again.');
			}
		} else {
			if ($storedSize > $expectedOffset && !ftruncate($handle, $expectedOffset)) {
				throw new RuntimeException('The server could not recover an incomplete video part.');
			}
			if (fseek($handle, $expectedOffset) !== 0) {
				throw new RuntimeException('The server could not continue saving the video.');
			}
			$written = 0;
			while ($written < $expectedChunkSize) {
				$bytesWritten = fwrite($handle, substr($chunkContents, $written));
				if ($bytesWritten === false || $bytesWritten === 0) {
					throw new RuntimeException('The server could not write a video part. Check available disk space and permissions.');
				}
				$written += $bytesWritten;
			}
			if (!fflush($handle)) {
				throw new RuntimeException('The server could not finish saving a video part.');
			}
		}
		if ($chunkIndex === $chunkCount - 1 && (int) (fstat($handle)['size'] ?? 0) !== $totalFileSize) {
			throw new RuntimeException('The complete video size could not be verified. Start the upload again.');
		}
		$chunkReceived = true;
	} catch (RuntimeException $exception) {
		$chunkError = $exception->getMessage();
	} finally {
		flock($handle, LOCK_UN);
		fclose($handle);
	}
	if ($chunkError !== '') {
		$respondToChunk($chunkError, 500);
	}
	if (!$chunkReceived) {
		$respondToChunk('The server did not confirm the video part. Please try again.', 500);
	}
	if ($chunkIndex < $chunkCount - 1) {
		echo json_encode(['chunk_received' => true], JSON_THROW_ON_ERROR);
		exit;
	}
	$_POST['action'] = 'upload';
	$_POST['chunked_upload_id'] = $uploadId;
	$_FILES['video'] = [
		'name' => $fileName,
		'type' => 'application/octet-stream',
		'tmp_name' => $partPath,
		'error' => UPLOAD_ERR_OK,
		'size' => $totalFileSize,
		'chunked_source' => $partPath,
		'chunked_manifest' => $manifestPath,
		'chunked_receipt' => $receiptPath,
	];
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload_url') {
	$respondToUrlUpload = static function (string $message, int $status = 400): void {
		header('Content-Type: application/json; charset=utf-8');
		http_response_code($status);
		echo json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
		exit;
	};
	$postedToken = $_POST['csrf_token'] ?? '';
	if (!is_string($postedToken) || !hash_equals((string) ($_SESSION['csrf_token'] ?? ''), $postedToken)) {
		$respondToUrlUpload('Your upload session expired. Refresh the page and try again.', 403);
	}
	if ($currentUser === null) {
		$respondToUrlUpload('Sign in before sharing a video.', 403);
	}
	if (empty($settings['uploads_enabled'])) {
		$respondToUrlUpload('Video uploads are temporarily disabled by the site administrator.', 403);
	}
	if (($currentUser['role'] ?? '') !== 'admin' && rateLimitExceeded('upload', (string) $currentUser['id'], 10, 3600)) {
		$respondToUrlUpload('You have reached the hourly upload limit. Try again later.', 429);
	}
	$videoUrl = $_POST['video_url'] ?? '';
	$uploadCategory = trim((string) ($_POST['category'] ?? 'Community'));
	$uploadCategory = $uploadCategory !== '' ? $uploadCategory : 'Community';
	if (!is_string($videoUrl) || !in_array($uploadCategory, $categoryOptions, true)) {
		$respondToUrlUpload('Enter a video URL and choose one of the available categories.');
	}
	$chunkDirectory = uploadChunkDirectory((string) $currentUser['id']);
	if (!is_dir($chunkDirectory) && !mkdir($chunkDirectory, 0700, true) && !is_dir($chunkDirectory)) {
		$respondToUrlUpload('The server could not create private temporary upload storage. Contact the site administrator.', 500);
	}
	$remoteUploadPath = $chunkDirectory . DIRECTORY_SEPARATOR . bin2hex(random_bytes(16)) . '.part';
	try {
		$fileName = downloadVideoFromUrl($videoUrl, $remoteUploadPath, MAX_UPLOAD_BYTES);
		$fileSize = filesize($remoteUploadPath);
		if ($fileSize === false || $fileSize <= 0) {
			throw new RuntimeException('The video URL returned an empty file.');
		}
		$quotaError = uploadQuotaError((string) $currentUser['id'], $fileSize, ($currentUser['role'] ?? '') === 'admin');
		if ($quotaError !== '') {
			throw new RuntimeException($quotaError);
		}
		$_POST['action'] = 'upload';
		$_POST['category'] = $uploadCategory;
		if (trim((string) ($_POST['title'] ?? '')) === '') {
			$_POST['title'] = pathinfo($fileName, PATHINFO_FILENAME);
		}
		$_FILES['video'] = [
			'name' => $fileName,
			'type' => 'application/octet-stream',
			'tmp_name' => $remoteUploadPath,
			'error' => UPLOAD_ERR_OK,
			'size' => $fileSize,
			'remote_source' => $remoteUploadPath,
		];
	} catch (Throwable $exception) {
		if (is_file($remoteUploadPath) && !unlink($remoteUploadPath)) {
			error_log('Failed to remove a rejected URL upload: ' . $remoteUploadPath);
		}
		if ($exception instanceof RuntimeException) {
			$respondToUrlUpload($exception->getMessage(), 400);
		}
		error_log('Video URL upload failed: ' . $exception->getMessage());
		$respondToUrlUpload('The video could not be downloaded because of a server error. Please try again later.', 500);
	}
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'upload') {
	$uploadCategory = trim((string) ($_POST['category'] ?? 'Community'));
	$uploadCategory = $uploadCategory !== '' ? $uploadCategory : 'Community';
	$postedToken = (string) ($_POST['csrf_token'] ?? '');
	if (!hash_equals($_SESSION['csrf_token'], $postedToken)) {
		$uploadError = 'Your upload session expired. Refresh the page and try again.';
	} elseif ($currentUser === null) {
		$uploadError = 'Sign in before sharing a video.';
	} elseif (empty($settings['uploads_enabled'])) {
		$uploadError = 'Video uploads are temporarily disabled by the site administrator.';
	} elseif (!isset($_FILES['video']['chunked_source']) && !isset($_FILES['video']['remote_source']) && ($currentUser['role'] ?? '') !== 'admin' && rateLimitExceeded('upload', (string) $currentUser['id'], 10, 3600)) {
		$uploadError = 'You have reached the hourly upload limit. Try again later.';
	} elseif (!in_array($uploadCategory, $categoryOptions, true)) {
		$uploadError = 'Choose one of the available video categories.';
	} elseif (!isset($_FILES['video']) || !is_array($_FILES['video'])) {
		$uploadError = 'Choose a video file or ZIP archive to upload.';
	} else {
		$file = $_FILES['video'];
		$uploadErrorCode = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
		$isZipArchive = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION)) === 'zip';
		if ($uploadErrorCode !== UPLOAD_ERR_OK) {
			$uploadError = match ($uploadErrorCode) {
				UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file exceeds a PHP or web-server upload limit. Ask the site administrator to increase the server upload limits.',
				UPLOAD_ERR_PARTIAL => 'The connection stopped before the whole file arrived. Check your connection and try again.',
				UPLOAD_ERR_NO_TMP_DIR => 'The server is missing its temporary upload folder. Contact the site administrator.',
				UPLOAD_ERR_CANT_WRITE => 'The server could not write the uploaded file. Contact the site administrator.',
				UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the upload. Contact the site administrator.',
				default => 'The upload did not complete. Please choose a file and try again.',
			};
		} elseif ((int) ($file['size'] ?? 0) <= 0 || (($currentUser['role'] ?? '') !== 'admin' && (int) $file['size'] > MAX_UPLOAD_BYTES)) {
			$uploadError = (int) ($file['size'] ?? 0) <= 0 ? 'Choose a non-empty file.' : ($isZipArchive ? 'Choose a ZIP archive no larger than 1 GB.' : 'Choose a video file no larger than 1 GB.');
		} else {
			$stagedFiles = [];
			$uploadedCount = 0;
			try {
				$customTitle = trim((string) ($_POST['title'] ?? ''));
				$creator = trim((string) ($_POST['creator'] ?? ''));
				if ($isZipArchive) {
					$stagedFiles = extractVideoArchive((string) $file['tmp_name'], uploadChunkDirectory((string) $currentUser['id']), ($currentUser['role'] ?? '') === 'admin');
				} else {
					$stagedFiles = [$file];
				}
				$redirect = '';
				foreach ($stagedFiles as $stagedFile) {
					$entryTitle = $customTitle;
					if ($isZipArchive && $entryTitle === '') {
						$entryTitle = pathinfo((string) $stagedFile['name'], PATHINFO_FILENAME);
					}
					try {
						$redirect = saveUploadedVideo($stagedFile, $currentUser, $uploadCategory, $entryTitle, $creator);
						$uploadedCount++;
					} catch (RuntimeException $exception) {
						if ($isZipArchive) {
							throw new RuntimeException('Video "' . (string) $stagedFile['name'] . '": ' . $exception->getMessage(), 0, $exception);
						}
						throw $exception;
					} finally {
						if ($isZipArchive && is_file((string) $stagedFile['tmp_name']) && !unlink((string) $stagedFile['tmp_name'])) {
							error_log('Failed to remove an extracted archive video: ' . $stagedFile['tmp_name']);
						}
					}
				}
				$chunkedSource = (string) ($file['chunked_source'] ?? '');
				$chunkedManifest = (string) ($file['chunked_manifest'] ?? '');
				$chunkedReceipt = (string) ($file['chunked_receipt'] ?? '');
				$remoteSource = (string) ($file['remote_source'] ?? '');
				foreach ([$chunkedSource, $remoteSource, $chunkedManifest] as $temporaryPath) {
					if ($temporaryPath !== '' && is_file($temporaryPath) && !unlink($temporaryPath)) {
						error_log('Failed to remove a completed upload temporary file: ' . $temporaryPath);
					}
				}
				if ($chunkedReceipt !== '') {
					$receiptContents = json_encode(['redirect' => $redirect], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
					if (file_put_contents($chunkedReceipt, $receiptContents, LOCK_EX) !== strlen($receiptContents)) {
						error_log('Failed to save upload completion receipt: ' . $chunkedReceipt);
					} else {
						chmod($chunkedReceipt, 0600);
					}
				}
				if (($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'XMLHttpRequest') {
					header('Content-Type: application/json; charset=utf-8');
					echo json_encode(['redirect' => $redirect], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
					exit;
				}
				header('Location: ' . $redirect, true, 303);
				exit;
			} catch (Throwable $exception) {
				foreach ($stagedFiles as $stagedFile) {
					$temporaryPath = (string) ($stagedFile['tmp_name'] ?? '');
					if ($isZipArchive && $temporaryPath !== '' && is_file($temporaryPath) && !unlink($temporaryPath)) {
						error_log('Failed to remove an incomplete archive video: ' . $temporaryPath);
					}
				}
				$chunkedSource = (string) ($file['chunked_source'] ?? '');
				$chunkedManifest = (string) ($file['chunked_manifest'] ?? '');
				$remoteSource = (string) ($file['remote_source'] ?? '');
				foreach ([$chunkedSource, $chunkedManifest, $remoteSource] as $temporaryPath) {
					if ($temporaryPath !== '' && is_file($temporaryPath) && !unlink($temporaryPath)) {
						error_log('Failed to remove an incomplete upload temporary file: ' . $temporaryPath);
					}
				}
				$message = $exception instanceof RuntimeException ? $exception->getMessage() : 'The video could not be saved because of a server error. Please try again later.';
				if (!$exception instanceof RuntimeException) {
					error_log('Video upload failed: ' . $exception->getMessage());
				}
				$uploadError = $uploadedCount > 0
					? 'Uploaded ' . $uploadedCount . ' video' . ($uploadedCount === 1 ? '' : 's') . ', but the next video failed: ' . $message
					: $message;
			}
		}
	}
}
if ($uploadError !== '' && (isset($_FILES['video']['chunked_source']) || isset($_FILES['video']['remote_source']))) {
	$chunkedSource = (string) ($_FILES['video']['chunked_source'] ?? '');
	$chunkedManifest = (string) ($_FILES['video']['chunked_manifest'] ?? '');
	$remoteSource = (string) ($_FILES['video']['remote_source'] ?? '');
	foreach ([$chunkedSource, $chunkedManifest, $remoteSource] as $temporaryPath) {
		if ($temporaryPath !== '' && is_file($temporaryPath) && !unlink($temporaryPath)) {
			error_log('Failed to remove a rejected upload temporary file: ' . $temporaryPath);
		}
	}
}

foreach ($seedVideos as &$seedVideo) {
	$seedCategory = (string) ($seedVideoCategories[$seedVideo['id']] ?? $seedVideo['category']);
	$seedVideo['category'] = resolveCategoryAlias($seedCategory, $settings['category_aliases']);
}
unset($seedVideo);
$allVideos = array_merge($seedVideos, loadUploads());
usort($allVideos, static fn(array $a, array $b): int => ($b['uploaded_at'] ?? 0) <=> ($a['uploaded_at'] ?? 0));
$hiddenVideoIds = is_array($settings['hidden_videos'] ?? null) ? $settings['hidden_videos'] : [];
$videos = array_values(array_filter($allVideos, static fn(array $video): bool => !in_array($video['id'] ?? '', $hiddenVideoIds, true)));
$isAdminPreview = ($currentUser['role'] ?? '') === 'admin' && ($_GET['preview'] ?? '') === '1';
$displayVideos = $isAdminPreview ? $allVideos : $videos;
if (isset($_GET['media'])) {
	$mediaId = (string) $_GET['media'];
	if (preg_match('/^[a-f0-9]{24}$/D', $mediaId) === 1) {
		foreach ($displayVideos as $video) {
			if (($video['id'] ?? '') === $mediaId && str_starts_with((string) ($video['video_url'] ?? ''), 'uploads/')) {
				streamUploadedVideo($video);
			}
		}
	}
	http_response_code(404);
	exit;
}
if (isset($_GET['thumbnail'])) {
	$thumbnailId = (string) $_GET['thumbnail'];
	if (preg_match('/^[a-f0-9]{24}$/D', $thumbnailId) === 1) {
		foreach ($displayVideos as $video) {
			if (($video['id'] ?? '') === $thumbnailId) {
				streamUploadedThumbnail($video);
			}
		}
	}
	http_response_code(404);
	exit;
}
$videoById = [];
foreach ($displayVideos as $video) {
	if (isset($video['id'])) {
		$videoById[$video['id']] = $video;
	}
}
$requestedId = (string) ($_GET['v'] ?? '');
$selectedVideo = $videoById[$requestedId] ?? $videoById['quiet-morning'] ?? ($displayVideos[0] ?? [
	'id' => '',
	'title' => 'No videos available',
	'creator' => '',
	'category' => '',
	'duration' => '',
	'views' => '0',
	'description' => '',
	'thumbnail' => '',
	'video_url' => '',
	'uploaded_at' => 0,
]);
$selectedVideoOwner = null;
foreach ($users as $user) {
	if (isset($selectedVideo['user_id']) && ($user['id'] ?? '') === $selectedVideo['user_id']) {
		$selectedVideoOwner = $user;
		break;
	}
}
$profileUser = null;
$profileVideos = [];
if ($page === 'profile') {
	$profileId = $_GET['id'] ?? ($currentUser['id'] ?? '');
	if (is_string($profileId) && preg_match('/^[a-f0-9]{24}$/D', $profileId) === 1) {
		foreach ($users as $user) {
			if (($user['id'] ?? '') === $profileId) {
				$profileUser = $user;
				break;
			}
		}
	}
	if ($profileUser !== null) {
		$profileVideos = array_values(array_filter($videos, static fn(array $video): bool => ($video['user_id'] ?? '') === $profileId));
	} else {
		http_response_code(404);
	}
}
$selectedVideoUrl = (string) $selectedVideo['video_url'];
if (str_starts_with($selectedVideoUrl, 'uploads/')) {
	$selectedVideoUrl = (string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php') . '?media=' . rawurlencode((string) $selectedVideo['id']) . ($isAdminPreview ? '&preview=1' : '');
}
$categories = $categoryOptions;
$shareUrl = strtok($_SERVER['REQUEST_URI'] ?? '/', '?') . '?v=' . rawurlencode($selectedVideo['id']);
$siteName = (string) $settings['site_name'];
$noticeMessages = [
	'registered' => 'Your account is ready. Welcome to frame!',
	'logged_in' => 'You are signed in.',
	'logged_out' => 'You are signed out.',
	'profile_saved' => 'Your profile has been updated.',
	'settings_saved' => 'Site settings saved.',
	'users_saved' => 'Account role updated.',
	'user_created' => 'Account created. Share the temporary password with the user securely.',
	'user_deleted' => 'Account deleted.',
	'video_saved' => 'Video visibility updated.',
	'video_category_saved' => 'Video category updated.',
	'videos_deleted' => 'Selected videos and their uploaded files were deleted.',
	'category_added' => 'Category added.',
	'category_renamed' => 'Category renamed and existing videos updated.',
	'category_deleted' => 'Category deleted; its videos were moved to Community.',
];
$notice = $noticeMessages[(string) ($_GET['notice'] ?? '')] ?? '';

if ($page === 'setup') {
	header('Location: install.php', true, 302);
	exit;
}
if (in_array($page, ['login', 'register'], true)):
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#101114"><title><?= $page === 'register' ? 'Create account' : 'Sign in' ?> — <?= escapeHtml($siteName) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#191a1f;--line:#303139;--text:#f4f3f0;--muted:#a0a0a8;--accent:#b9f36a;--ink:#17200f}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 70% 8%,#24271f,transparent 42%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;display:grid;place-items:center;padding:24px}.card{width:min(440px,100%);background:rgba(25,26,31,.94);border:1px solid var(--line);border-radius:20px;padding:32px;box-shadow:0 28px 80px #0006}.brand{display:inline-flex;align-items:center;gap:10px;font-weight:800;font-size:21px;letter-spacing:-.8px;margin-bottom:28px}.mark{width:31px;height:31px;border-radius:10px;background:var(--accent);color:var(--ink);display:grid;place-items:center}.mark svg{width:18px}h1{font-size:25px;letter-spacing:-.8px;margin:0 0 6px}.sub{color:var(--muted);font-size:13px;margin:0 0 22px}.field{display:block;margin:0 0 15px}.field span{display:block;font-size:12px;font-weight:650;margin-bottom:7px}.field input{width:100%;height:44px;background:#111216;color:#fff;border:1px solid #393a42;border-radius:10px;padding:0 12px;outline:none}.field input:focus{border-color:#9bb97c}.primary{width:100%;height:44px;border:0;border-radius:10px;background:var(--accent);color:var(--ink);font-weight:750;cursor:pointer;margin-top:3px}.message{border-radius:10px;padding:11px 13px;margin:0 0 16px;font-size:13px;background:#20291a;color:#c9f39d}.error{background:#301c1d;color:#ffc1ba}.bottom{border-top:1px solid var(--line);margin-top:22px;padding-top:18px;color:var(--muted);font-size:13px}.bottom a,.back{color:var(--accent);font-weight:650}.hint{font-size:12px;color:#92939b;margin:14px 0 0}.unavailable{border:1px solid var(--line);border-radius:10px;padding:13px;color:var(--muted);font-size:13px;margin-top:16px}.back{display:inline-block;margin-top:17px;font-size:13px}
</style></head><body><main class="card">
<a class="brand" href="./"><span class="mark"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.7c0-.8.9-1.3 1.6-.9l9.1 5.3c.7.4.7 1.4 0 1.8l-9.1 5.3c-.7.4-1.6-.1-1.6-.9V5.7Z"/></svg></span><?= escapeHtml($siteName) ?></a>
<h1><?= $page === 'register' ? 'Join the community' : 'Welcome back' ?></h1>
<p class="sub"><?= $page === 'register' ? 'Create an account to share videos.' : 'Sign in to your account.' ?></p>
<?php if ($actionError !== ''): ?><div class="message error" role="alert"><?= escapeHtml($actionError) ?></div><?php elseif ($notice !== ''): ?><div class="message" role="status"><?= escapeHtml($notice) ?></div><?php endif; ?>
<form method="post" autocomplete="on">
	<input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
	<input type="hidden" name="action" value="<?= $page === 'register' ? 'register' : 'login' ?>">
	<?php if ($page === 'register'): ?>
			<label class="field"><span>Your name</span><input name="name" type="text" minlength="2" maxlength="40" required autocomplete="name"></label>
		<?php endif; ?>
			<label class="field"><span>Email</span><input name="email" type="email" maxlength="254" required autocomplete="email"></label>
			<label class="field"><span>Password</span><input name="password" type="password" minlength="12" maxlength="72" required autocomplete="<?= $page === 'login' ? 'current-password' : 'new-password' ?>"></label>
			<?php if ($page === 'register'): ?><p class="hint">Use at least 12 characters. Passwords are securely hashed before storage.</p><?php endif; ?>
			<button class="primary" type="submit"><?= $page === 'register' ? 'Create account' : 'Sign in' ?></button>
		</form>
<div class="bottom"><?php if ($page === 'login'): ?>New here? <a href="register.php">Create an account</a><?php else: ?>Already have an account? <a href="login.php">Sign in</a><?php endif; ?></div><a class="back" href="./">← Back to videos</a>
</main></body></html>
<?php exit; endif; ?>

<?php if ($page === 'admin'): ?>
<?php if (($currentUser['role'] ?? '') !== 'admin'): ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#101114"><title>Admin — <?= escapeHtml($siteName) ?></title><style>body{margin:0;min-height:100vh;display:grid;place-items:center;background:#101114;color:#f4f3f0;font:15px system-ui;padding:20px}.card{max-width:440px;padding:30px;border:1px solid #303139;border-radius:18px;background:#191a1f}.card p{color:#aaa}.card a{color:#b9f36a}</style></head><body><main class="card"><h1>Administrator access required</h1><p>Sign in with an administrator account to open this portal.</p><a href="login.php">Sign in</a> · <a href="./">Back to videos</a></main></body></html>
<?php exit; endif; ?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><meta name="theme-color" content="#101114"><title>Admin portal — <?= escapeHtml($siteName) ?></title>
<style>
:root{color-scheme:dark;--bg:#101114;--panel:#191a1f;--line:#303139;--text:#f4f3f0;--muted:#a0a0a8;--accent:#b9f36a;--ink:#17200f}*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif}.bar{height:68px;border-bottom:1px solid var(--line);display:flex;align-items:center;justify-content:space-between;padding:0 max(22px,calc((100vw - 1180px)/2))}.brand{font-size:19px;font-weight:800;letter-spacing:-.6px}.bar-right{display:flex;align-items:center;gap:16px;color:var(--muted)}a{color:var(--accent);text-decoration:none}.bar a{font-size:13px}.wrap{max-width:1180px;margin:0 auto;padding:34px 22px 65px}.eyebrow{font-size:11px;color:var(--accent);font-weight:800;letter-spacing:1.4px;text-transform:uppercase}.heading{display:flex;align-items:end;justify-content:space-between;margin:8px 0 25px;gap:15px}.heading h1{font-size:31px;letter-spacing:-1px;margin:0}.heading p{color:var(--muted);margin:5px 0 0}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px}.stat,.panel{background:var(--panel);border:1px solid var(--line);border-radius:15px}.stat{padding:16px 18px}.stat strong{font-size:22px;display:block}.stat span{color:var(--muted);font-size:12px}.grid{display:grid;grid-template-columns:1fr 1fr;gap:16px}.panel{padding:20px;min-width:0}.panel.wide{grid-column:1/-1}.panel h2{font-size:16px;margin:0 0 4px}.panel>p{color:var(--muted);font-size:12px;margin:0 0 17px}.field{display:block;margin:0 0 13px}.field span{display:block;font-size:12px;font-weight:650;margin:0 0 6px}.field input,.field textarea,.field select{width:100%;border:1px solid #383941;border-radius:9px;background:#111216;color:var(--text);padding:10px 11px;outline:none}.field input,.field select{height:41px}.field textarea{min-height:80px;resize:vertical}.check{display:flex;align-items:center;gap:9px;color:#d0d0d5;font-size:13px;margin:5px 0 15px}.button{border:0;border-radius:9px;background:var(--accent);color:var(--ink);font-weight:750;padding:10px 14px;cursor:pointer}.button.danger{background:#402322;color:#ffc1ba}.button.subtle{background:#292a31;color:#eee}.message{padding:11px 13px;border-radius:10px;margin-bottom:17px;background:#20291a;color:#c9f39d}.error{background:#301c1d;color:#ffc1ba}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left}th,td{padding:11px 9px;border-bottom:1px solid #2c2d34;vertical-align:middle}th{font-size:11px;color:#9b9ca4;text-transform:uppercase;letter-spacing:.7px}td{font-size:12px}.muted{color:var(--muted)}.row-actions{display:flex;align-items:center;gap:7px}.row-actions select,.video-category-form select{background:#111216;color:#eee;border:1px solid #383941;border-radius:8px;padding:7px}.video-category-form{display:flex;align-items:center;gap:6px;min-width:190px}.video-category-form select{min-width:0;max-width:180px}.video-category-form .button{padding:8px 10px}.inline{display:inline}.video-name{min-width:180px}.status{border:1px solid #34353d;padding:3px 8px;border-radius:20px;font-size:11px}.status.live{color:#c9f39d}.status.hidden{color:#ffc1ba}.category-add{display:flex;align-items:end;gap:10px;max-width:560px}.category-add .field{flex:1;margin:0}.category-row{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid #2c2d34}.category-row form:first-child{display:flex;align-items:center;gap:8px;flex:1}.category-row .category-name{min-width:0;max-width:360px}.category-row .button{white-space:nowrap}.category-label{font-weight:650}.category-note{font-size:11px;color:var(--muted)}.admin-nav{position:sticky;top:0;z-index:4;display:flex;gap:7px;overflow-x:auto;margin:0 0 20px;padding:10px 0;background:rgba(16,17,20,.96);border-bottom:1px solid var(--line)}.admin-nav a{flex:0 0 auto;padding:8px 12px;border:1px solid var(--line);border-radius:9px;background:var(--panel);color:#d8d8dd;font-size:12px;font-weight:650}.admin-nav a:hover,.admin-nav a:focus-visible,.admin-nav a[aria-current="page"]{border-color:var(--accent);color:var(--accent);outline:none}.admin-section{scroll-margin-top:76px}.admin-section h2{margin-bottom:5px}.admin-section>p{max-width:760px}.accounts-table{margin-top:20px}@media(max-width:720px){.grid{grid-template-columns:1fr}.panel.wide{grid-column:auto}.stats{gap:8px}.stat{padding:12px}.heading{align-items:flex-start;flex-direction:column}.heading h1{font-size:26px}.bar{padding:0 16px}.wrap{padding:27px 14px 50px}.bar-right{gap:10px}.bar-right span{display:none}}
</style></head><body>
<header class="bar"><a class="brand" href="./"><?= escapeHtml($siteName) ?> / admin</a><div class="bar-right"><span><?= escapeHtml($currentUser['name']) ?></span><a href="index.php?page=profile">Profile</a><a href="./">View site</a><form class="inline" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="logout"><button class="button subtle" type="submit">Sign out</button></form></div></header>
<main class="wrap"><div class="eyebrow">Control room</div><div class="heading"><div><h1>Site administration</h1><p>Manage your community, videos, and site settings.</p></div></div>
<?php if ($actionError !== ''): ?><div class="message error" role="alert"><?= escapeHtml($actionError) ?></div><?php elseif ($notice !== ''): ?><div class="message" role="status"><?= escapeHtml($notice) ?></div><?php endif; ?>
<div class="stats"><div class="stat"><strong><?= count($users) ?></strong><span>Registered accounts</span></div><div class="stat"><strong><?= count($videos) ?></strong><span>Visible videos</span></div><div class="stat"><strong><?= empty($settings['uploads_enabled']) ? 'Paused' : 'Open' ?></strong><span>Community uploads</span></div></div>
<nav class="admin-nav" aria-label="Admin sections"><a href="index.php?page=admin&amp;tab=settings"<?= $adminTab === 'settings' ? ' aria-current="page"' : '' ?>>Site settings</a><a href="index.php?page=admin&amp;tab=categories"<?= $adminTab === 'categories' ? ' aria-current="page"' : '' ?>>Categories</a><a href="index.php?page=admin&amp;tab=accounts"<?= $adminTab === 'accounts' ? ' aria-current="page"' : '' ?>>Accounts</a><a href="index.php?page=admin&amp;tab=videos"<?= $adminTab === 'videos' ? ' aria-current="page"' : '' ?>>Video moderation</a></nav>
<div class="grid">
<?php if ($adminTab === 'settings'): ?><section class="panel admin-section" id="site-settings"><h2>Site settings</h2><p>Set the public site name, announcement, and upload availability.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="save_settings"><label class="field"><span>Site name</span><input name="site_name" maxlength="40" required value="<?= escapeHtml($settings['site_name']) ?>"></label><label class="field"><span>Announcement</span><textarea name="announcement" maxlength="240" placeholder="Optional message shown to everyone"><?= escapeHtml($settings['announcement']) ?></textarea></label><label class="check"><input type="checkbox" name="uploads_enabled" value="1"<?= empty($settings['uploads_enabled']) ? '' : ' checked' ?>> Allow signed-in members to upload videos</label><button class="button" type="submit">Save settings</button></form></section><?php elseif ($adminTab === 'categories'): ?>
<section class="panel wide admin-section" id="categories"><h2>Video categories</h2><p>Create categories for uploads, rename existing categories, or delete them. Deleting a category moves its videos to Community. The built-in Community category cannot be changed.</p><form class="category-add" method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_category"><input type="hidden" name="category_mode" value="add"><label class="field"><span>New category</span><input name="name" maxlength="30" required></label><button class="button" type="submit">Add category</button></form><div class="category-list">
<?php foreach ($categoryOptions as $categoryOption): ?><div class="category-row"><?php if (strcasecmp($categoryOption, 'Community') === 0): ?><span class="category-label">Community</span><span class="category-note">Built-in fallback</span><?php else: ?><form method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_category"><input type="hidden" name="category_mode" value="rename"><input type="hidden" name="category" value="<?= escapeHtml($categoryOption) ?>"><input class="category-name" name="name" maxlength="30" required value="<?= escapeHtml($categoryOption) ?>"><button class="button subtle" type="submit">Rename</button></form><form method="post" onsubmit="return confirm('Delete this category? Its videos will be moved to Community.')"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_category"><input type="hidden" name="category_mode" value="delete"><input type="hidden" name="category" value="<?= escapeHtml($categoryOption) ?>"><button class="button danger" type="submit">Delete</button></form><?php endif; ?></div><?php endforeach; ?>
</div></section><?php elseif ($adminTab === 'accounts'): ?>
<section class="panel admin-section" id="accounts"><h2>Account management</h2><p>Create accounts, promote trusted members, or remove unwanted accounts. At least one administrator must remain.</p><form method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_create_user"><label class="field"><span>Name</span><input name="name" minlength="2" maxlength="40" autocomplete="name" required></label><label class="field"><span>Email</span><input type="email" name="email" maxlength="254" autocomplete="email" required></label><label class="field"><span>Temporary password (12-72 bytes)</span><input type="password" name="password" minlength="12" maxlength="72" autocomplete="new-password" required></label><label class="field"><span>Role</span><select name="role"><option value="member">Member</option><option value="admin">Admin</option></select></label><button class="button" type="submit">Create account</button></form><div class="table-wrap accounts-table"><table><thead><tr><th>Account</th><th>Role</th><th>Actions</th></tr></thead><tbody>
<?php foreach ($users as $user): ?><tr><td><?= escapeHtml($user['name']) ?><br><span class="muted"><?= escapeHtml($user['email']) ?></span></td><td><?= escapeHtml(ucfirst((string) $user['role'])) ?></td><td><div class="row-actions"><form class="inline" method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_role"><input type="hidden" name="user_id" value="<?= escapeHtml($user['id']) ?>"><select name="role" aria-label="Role for <?= escapeHtml($user['name']) ?>"><option value="member"<?= $user['role'] === 'member' ? ' selected' : '' ?>>Member</option><option value="admin"<?= $user['role'] === 'admin' ? ' selected' : '' ?>>Admin</option></select><button class="button subtle" type="submit">Save</button></form><?php if ($user['id'] !== $currentUser['id']): ?><form class="inline" method="post" onsubmit="return confirm('Delete this account?')"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_delete_user"><input type="hidden" name="user_id" value="<?= escapeHtml($user['id']) ?>"><button class="button danger" type="submit">Delete</button></form><?php endif; ?></div></td></tr><?php endforeach; ?>
</tbody></table></div></section><?php elseif ($adminTab === 'videos'): ?>
<section class="panel wide admin-section" id="video-moderation"><h2>Video moderation</h2><p>Hide a video from the public site or restore a hidden video. Hidden files remain stored. Select sample or uploaded videos below to remove them from the catalog; uploaded media files are deleted too.</p><form id="bulk-delete-videos" method="post" onsubmit="return confirm('Permanently remove the selected videos and delete any uploaded media files?')"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_delete_videos"><button class="button danger" type="submit">Delete selected videos</button></form><div class="table-wrap"><table><thead><tr><th><input id="select-all-videos" type="checkbox" aria-label="Select all videos"></th><th>Video</th><th>Creator</th><th>Category</th><th>Visibility</th><th>Action</th></tr></thead><tbody>
<?php foreach ($allVideos as $video): $isHidden = in_array($video['id'], $hiddenVideoIds, true); ?><tr><td><input class="video-select" form="bulk-delete-videos" type="checkbox" name="video_ids[]" value="<?= escapeHtml($video['id']) ?>" aria-label="Select <?= escapeHtml($video['title']) ?>"></td><td class="video-name"><a href="index.php?v=<?= rawurlencode((string) $video['id']) ?>&amp;preview=1"><?= escapeHtml($video['title']) ?></a></td><td><?= escapeHtml($video['creator']) ?></td><td><form class="inline video-category-form" method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_video_category"><input type="hidden" name="video_id" value="<?= escapeHtml($video['id']) ?>"><select name="category" aria-label="Category for <?= escapeHtml($video['title']) ?>"><?php foreach ($categoryOptions as $categoryOption): ?><option value="<?= escapeHtml($categoryOption) ?>"<?= resolveCategoryAlias((string) $video['category'], $settings['category_aliases']) === $categoryOption ? ' selected' : '' ?>><?= escapeHtml($categoryOption) ?></option><?php endforeach; ?></select><button class="button subtle" type="submit">Save</button></form></td><td><span class="status<?= $isHidden ? ' hidden' : ' live' ?>"><?= $isHidden ? 'Hidden' : 'Public' ?></span></td><td><form class="inline" method="post"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="admin_video"><input type="hidden" name="video_id" value="<?= escapeHtml($video['id']) ?>"><input type="hidden" name="visibility" value="<?= $isHidden ? 'show' : 'hide' ?>"><button class="button <?= $isHidden ? 'subtle' : 'danger' ?>" type="submit"><?= $isHidden ? 'Restore' : 'Hide' ?></button></form></td></tr><?php endforeach; ?>
</tbody></table></div></section><?php endif; ?>
</div></main><script>document.getElementById('select-all-videos')?.addEventListener('change', function () { document.querySelectorAll('.video-select').forEach((checkbox) => { checkbox.checked = this.checked; }); });</script></body></html>
<?php exit; endif; ?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#101114">
	<meta name="description" content="A home for the videos worth sharing. Watch, discover, and share on Frame.">
	<title><?= $page === 'browse' ? 'Browse videos' : ($page === 'profile' ? ($profileUser !== null ? escapeHtml($profileUser['name']) . ' — Profile' : 'Profile not found') : ($isHomeGallery ? 'Latest videos' : escapeHtml($selectedVideo['title']))) ?> — <?= escapeHtml($siteName) ?></title>
	<style>
		:root{color-scheme:dark;--bg:#101114;--panel:#17181d;--panel-2:#1d1e24;--line:#2a2b32;--text:#f4f3f0;--muted:#a0a0a8;--accent:#b9f36a;--accent-ink:#17200f;--radius:18px}
		*{box-sizing:border-box}
		body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;min-height:100vh}
		button,input,select{font:inherit}
		button,a{-webkit-tap-highlight-color:transparent}
		a{color:inherit;text-decoration:none}
		button{color:inherit}
		.topbar{height:76px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:30px;padding:0 4.2vw;position:sticky;top:0;background:rgba(16,17,20,.92);backdrop-filter:blur(16px);z-index:5}
		.brand{display:flex;align-items:center;gap:10px;font-size:21px;font-weight:780;letter-spacing:-1.1px;flex-shrink:0}
		.brand-mark{height:31px;width:31px;border-radius:10px;background:var(--accent);display:grid;place-items:center;color:var(--accent-ink)}
		.brand-mark svg{width:17px;height:17px}
		.search{position:relative;width:min(410px,40vw);margin-left:auto}
		.search svg{position:absolute;width:18px;height:18px;left:15px;top:12px;color:#8f9098}
		.search input{width:100%;height:43px;border:1px solid var(--line);border-radius:12px;background:#1a1b20;color:var(--text);padding:0 16px 0 43px;outline:none;transition:border-color .2s}
		.search input:focus{border-color:#66696f}
		.search input::placeholder{color:#8e8f98}
		.top-actions{display:flex;align-items:center;gap:12px}
		.upload-button,.primary-button{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;border-radius:11px;background:var(--accent);color:var(--accent-ink);font-weight:750;padding:11px 16px;cursor:pointer;transition:transform .18s,filter .18s}
		.upload-button:hover,.primary-button:hover{filter:brightness(1.05);transform:translateY(-1px)}
		.upload-button svg{width:17px;height:17px}
		.avatar{width:36px;height:36px;border-radius:50%;background:#34353b;color:#d9dbd2;display:grid;place-items:center;font-size:12px;font-weight:700}
		.account-link{font-size:12px;color:#d1d2d7;white-space:nowrap}
		.account-link:hover{color:var(--accent)}
		.signout-form{display:inline-flex}
		.layout{width:min(1440px,100%);margin:0 auto;padding:38px 4.2vw 70px;display:grid;grid-template-columns:minmax(0,1fr) 342px;gap:36px}
		.eyebrow{display:flex;align-items:center;gap:9px;color:var(--accent);font-size:11px;font-weight:800;letter-spacing:1.55px;text-transform:uppercase;margin-bottom:12px}
		.eyebrow-mark{width:7px;height:7px;border-radius:50%;background:var(--accent);box-shadow:0 0 12px rgba(185,243,106,.55)}
		h1{font-size:clamp(26px,3vw,37px);letter-spacing:-1.3px;line-height:1.15;margin:0 0 23px;font-weight:740}
		.player-shell{position:relative;overflow:hidden;border-radius:var(--radius);background:#08090b;aspect-ratio:16/9;box-shadow:0 22px 65px rgba(0,0,0,.26)}
		.player-shell video{display:block;width:100%;height:100%;object-fit:contain;background:#08090b}
		.player-empty{position:absolute;inset:0;display:grid;place-items:center;background:radial-gradient(ellipse at 68% 28%,#36344a 0,#23242d 35%,#111217 76%)}
		.player-empty::before,.player-empty::after{content:"";position:absolute;border-radius:50%;filter:blur(1px)}
		.player-empty::before{width:38%;aspect-ratio:1;background:linear-gradient(145deg,#d9ebaa,#829f70);right:13%;top:10%;opacity:.8}
		.player-empty::after{height:42%;width:115%;bottom:-27%;left:-7%;border-radius:50% 50% 0 0;background:#171b20}
		.player-empty-label{position:relative;z-index:1;font-size:12px;color:#e0e4d7;letter-spacing:2px;text-transform:uppercase}
		.video-meta{display:flex;align-items:flex-start;justify-content:space-between;gap:20px;padding:22px 1px 18px;border-bottom:1px solid var(--line)}
		.video-meta h2{font-size:21px;letter-spacing:-.55px;line-height:1.3;margin:0 0 8px}
		.video-stats{display:flex;align-items:center;gap:9px;flex-wrap:wrap;color:var(--muted);font-size:13px}
		.dot{width:3px;height:3px;border-radius:50%;background:#777982}
		.tag{display:inline-flex;align-items:center;border:1px solid #35363c;color:#c5c6cb;border-radius:7px;padding:3px 8px;font-size:11px;margin-left:4px}
		.meta-actions{display:flex;gap:9px;flex-shrink:0}
		.icon-button{width:42px;height:42px;border-radius:11px;border:1px solid var(--line);background:var(--panel);display:grid;place-items:center;cursor:pointer;transition:background .16s,border-color .16s}
		.icon-button:hover{background:#25262c;border-color:#46474f}
		.icon-button svg{width:18px;height:18px;color:#d8d9de}
		.description{display:flex;gap:13px;padding:20px 0;align-items:flex-start}
		.creator-avatar{width:40px;height:40px;border-radius:50%;display:grid;place-items:center;flex-shrink:0;background:linear-gradient(135deg,#d7e8ba,#738f65);color:#182115;font-weight:800;font-size:13px}
		.creator-name{font-weight:700;margin:0 0 4px}
		.description p{margin:0;color:#a8a9b0;font-size:13px}
		.section-heading{display:flex;align-items:baseline;justify-content:space-between;margin:9px 0 14px}
		.section-heading h3{margin:0;font-size:14px;letter-spacing:.1px}
		.section-heading span{color:#85868e;font-size:12px}
		.chips{display:flex;gap:8px;overflow-x:auto;padding-bottom:8px;scrollbar-width:none}
		.chips::-webkit-scrollbar{display:none}
		.chip{flex-shrink:0;border:1px solid var(--line);border-radius:100px;padding:7px 12px;background:transparent;color:#aeb0b6;font-size:12px;cursor:pointer;transition:.18s}
		.chip:hover,.chip.active{background:var(--accent);border-color:var(--accent);color:var(--accent-ink);font-weight:700}
		.sidebar-heading{display:flex;align-items:center;justify-content:space-between;margin:1px 0 17px}
		.sidebar-heading h2{font-size:16px;letter-spacing:-.3px;margin:0}
		.sidebar-heading span{font-size:12px;color:#8f9098}
		.video-list{display:flex;flex-direction:column;gap:13px}
		.video-card{display:grid;grid-template-columns:148px minmax(0,1fr);gap:13px;padding:8px;border-radius:13px;transition:background .17s;cursor:pointer}
		.video-card[hidden]{display:none!important}
		.video-card:hover{background:var(--panel)}
		.video-card.current{background:#1d1f24;outline:1px solid #2d3034}
		.thumb{aspect-ratio:16/9;position:relative;overflow:hidden;border-radius:9px;background:linear-gradient(135deg,#3b4741,#172421)}
		.thumb img{width:100%;height:100%;object-fit:cover;display:block;transition:transform .3s}
		.video-card:hover .thumb img{transform:scale(1.04)}
		.thumb-fallback{height:100%;width:100%;display:grid;place-items:center;color:#e7ebd8;font-size:10px;letter-spacing:1.2px;text-transform:uppercase;background:radial-gradient(circle at 75% 22%,#70846d,#283331 54%,#12191a)}
		.duration{position:absolute;right:6px;bottom:6px;background:rgba(8,9,10,.82);border-radius:4px;padding:2px 5px;font-size:10px;font-weight:650}
		.card-copy{min-width:0;padding:2px 1px}
		.card-copy h3{font-size:12px;line-height:1.45;margin:0 0 7px;font-weight:680;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
		.card-copy p{font-size:11px;line-height:1.45;color:#92939b;margin:0 0 4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
		.card-copy .category-label{color:var(--accent);font-size:10px}
		.empty-results{display:none;color:#9798a1;font-size:13px;text-align:center;padding:35px 12px;border:1px dashed #35363d;border-radius:14px}
		.empty-gallery{margin-top:24px;padding:48px 24px;text-align:center;border:1px dashed #3a3b43;border-radius:16px;background:linear-gradient(145deg,#191a1f,#141519)}
		.empty-gallery h2{margin:0 0 8px;font-size:20px;letter-spacing:-.4px}
		.empty-gallery p{max-width:440px;margin:0 auto 18px;color:var(--muted);font-size:13px}
		.empty-gallery-actions{display:flex;justify-content:center;align-items:center;gap:12px;flex-wrap:wrap}
		.empty-gallery-actions .account-link{padding:10px 14px;border:1px solid var(--line);border-radius:10px}
		.browse-layout{display:block;max-width:1440px}
		.browse-page{min-width:0}
		.profile-heading{display:flex;align-items:center;gap:18px;margin:18px 0 25px;padding:24px;border:1px solid var(--line);border-radius:16px;background:linear-gradient(145deg,#1d1e24,#15161a)}
		.profile-avatar{width:64px;height:64px;flex-shrink:0;border-radius:50%;display:grid;place-items:center;background:linear-gradient(135deg,#d7e8ba,#738f65);color:#182115;font-size:24px;font-weight:800}
		.profile-heading h1{margin:0 0 6px}
		.profile-heading p{margin:0;color:var(--muted);white-space:pre-wrap;overflow-wrap:anywhere}
		.profile-edit{max-width:620px;margin:0 0 30px;padding:20px;border:1px solid var(--line);border-radius:14px;background:var(--panel)}
		.profile-edit h2{margin:0 0 14px;font-size:16px}
		.profile-edit textarea{width:100%;min-height:100px;resize:vertical;border:1px solid #33343b;border-radius:9px;background:#111216;color:#eee;padding:10px 12px;outline:none;font:inherit}
		.profile-edit textarea:focus{border-color:#82877b}
		.video-grid{display:grid;grid-template-columns:repeat(4,minmax(0,1fr));gap:14px}
		.video-grid .video-card{display:block;padding:9px}
		.video-grid .thumb{margin-bottom:10px}
		.footer-note{margin-top:24px;padding-top:18px;border-top:1px solid var(--line);font-size:11px;color:#74757d;line-height:1.7}
		.toast{position:fixed;left:50%;bottom:26px;transform:translate(-50%,20px);background:#f3f4ed;color:#20221c;font-size:13px;font-weight:650;border-radius:10px;padding:11px 16px;box-shadow:0 10px 40px #0008;z-index:20;opacity:0;pointer-events:none;transition:.22s}
		.toast.visible{opacity:1;transform:translate(-50%,0)}
		.modal-backdrop{position:fixed;inset:0;display:none;align-items:center;justify-content:center;padding:20px;background:rgba(0,0,0,.68);backdrop-filter:blur(7px);z-index:10}
		.modal-backdrop.open{display:flex}
		.modal{width:min(470px,100%);max-height:90vh;overflow-y:auto;border:1px solid #37383f;border-radius:20px;background:#191a1f;padding:27px;box-shadow:0 28px 80px #0008}
		.modal-head{display:flex;align-items:flex-start;justify-content:space-between;gap:15px;margin-bottom:20px}
		.modal-head h2{margin:0 0 4px;font-size:21px;letter-spacing:-.5px}
		.modal-head p{margin:0;color:#a0a1a8;font-size:13px}
		.modal-close{width:34px;height:34px;display:grid;place-items:center;border:1px solid var(--line);border-radius:9px;background:transparent;cursor:pointer;color:#bbb}
		.field{display:block;margin:0 0 14px}
		.field span{display:block;color:#c9cacf;font-size:12px;font-weight:650;margin-bottom:7px}
		.field input,.field select{width:100%;height:43px;background:#111216;color:#eee;border:1px solid #33343b;border-radius:9px;padding:0 12px;outline:none}
		.field input:focus,.field select:focus{border-color:#82877b}
		.file-picker{border:1px dashed #46474f;border-radius:11px;padding:14px;background:#141519}
		.file-picker input{height:auto;padding:0;border:0;background:transparent}
		.file-help{font-size:11px;color:#898a93;margin:7px 0 0}
		.upload-progress{margin:15px 0 0;padding:13px;border:1px solid #37383f;border-radius:11px;background:#141519}
		.upload-progress[hidden]{display:none}
		.upload-progress-head{display:flex;justify-content:space-between;gap:12px;font-size:12px;font-weight:650}
		.upload-progress-metrics{display:flex;gap:9px;white-space:nowrap}
		#uploadPercent{color:var(--accent)}
		#uploadSpeed{color:#a0a1a8}
		.upload-progress-track{height:8px;margin-top:10px;overflow:hidden;border-radius:99px;background:#303139}
		.upload-progress-bar{height:100%;width:0;border-radius:inherit;background:var(--accent);transition:width .15s ease}
		.upload-progress-bar.indeterminate{width:35%;animation:upload-progress 1.15s ease-in-out infinite alternate}
		@keyframes upload-progress{from{transform:translateX(-20%)}to{transform:translateX(205%)}}
		.upload-progress-details{margin:8px 0 0;color:#a0a1a8;font-size:11px}
		.modal-submit{width:100%;margin-top:4px}
		.modal-submit:disabled,.modal-close:disabled{opacity:.55;cursor:wait}
		.form-error{border:1px solid #683d3c;background:#301c1d;color:#ffc1ba;padding:11px 13px;border-radius:10px;font-size:13px;margin:0 0 16px}
		.upload-success{margin:0 0 16px;padding:11px 13px;border-radius:10px;background:#20291a;color:#c9f39d;font-size:13px}
		.mobile-brand{display:none}
		@media(max-width:1050px){.layout{grid-template-columns:minmax(0,1fr) 300px;gap:22px}.video-card{grid-template-columns:122px minmax(0,1fr);gap:10px}.video-grid{grid-template-columns:repeat(3,minmax(0,1fr))}.topbar{padding:0 3vw}.layout{padding-left:3vw;padding-right:3vw}}
		@media(max-width:780px){.topbar{height:65px;padding:0 18px;gap:12px}.brand{font-size:19px}.search{width:auto;flex:1;margin-left:8px}.search input{height:40px}.top-actions{gap:7px}.upload-button{padding:10px 11px;font-size:12px}.upload-button .button-label{display:none}.avatar{display:none}.account-link{font-size:11px}.layout{display:block;padding:28px 18px 50px}.sidebar{margin-top:22px}.sidebar-heading{margin-bottom:10px}.video-list{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.video-grid{grid-template-columns:repeat(2,minmax(0,1fr));gap:8px}.video-card{display:block;padding:8px}.video-card .thumb{margin-bottom:8px}.card-copy{padding:0 1px 3px}.card-copy h3{font-size:12px}.video-meta h2{font-size:18px}.eyebrow{margin-bottom:9px}}
		@media(max-width:480px){.topbar{padding:0 13px}.brand{gap:7px}.brand-mark{width:29px;height:29px}.search input{padding-left:36px;font-size:12px}.search svg{left:12px}.upload-button{width:39px;height:39px;padding:0}.layout{padding:23px 13px 40px}h1{font-size:27px;margin-bottom:16px}.video-meta{padding-top:17px}.meta-actions{gap:6px}.icon-button{width:37px;height:37px}.video-meta h2{font-size:17px}.video-stats{font-size:11px}.tag{padding:2px 6px}.description{padding:16px 0}.video-list{grid-template-columns:repeat(2,minmax(0,1fr));gap:5px}.video-card{padding:6px}.card-copy h3{font-size:11px}.card-copy p{font-size:10px}.modal{padding:22px}.player-shell{border-radius:13px}}
		</style>
</head>
<body>
<header class="topbar">
	<a class="brand" href="./" aria-label="<?= escapeHtml($siteName) ?> home">
		<span class="brand-mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.7c0-.8.9-1.3 1.6-.9l9.1 5.3c.7.4.7 1.4 0 1.8l-9.1 5.3c-.7.4-1.6-.1-1.6-.9V5.7Z"/></svg></span>
		<?= escapeHtml($siteName) ?>
	</a>
	<a class="account-link" href="?page=browse"<?= $page === 'browse' ? ' aria-current="page"' : '' ?>>Browse</a>
	<label class="search" aria-label="Search videos">
		<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8"><circle cx="10.8" cy="10.8" r="6.8"/><path d="m16 16 4.3 4.3" stroke-linecap="round"/></svg>
		<input id="searchInput" type="search" placeholder="Search videos, creators..." autocomplete="off">
	</label>
	<div class="top-actions">
		<?php if ($currentUser !== null && !empty($settings['uploads_enabled'])): ?>
			<button class="upload-button" type="button" data-open-upload>
				<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4m0 0L7.5 8.5M12 4l4.5 4.5M5 14.5v3A2.5 2.5 0 0 0 7.5 20h9a2.5 2.5 0 0 0 2.5-2.5v-3"/></svg>
				<span class="button-label">Upload</span>
			</button>
		<?php elseif ($currentUser === null): ?>
			<a class="account-link" href="login.php">Sign in</a>
		<?php endif; ?>
		<?php if ($currentUser !== null): ?>
			<?php if (($currentUser['role'] ?? '') === 'admin'): ?><a class="account-link" href="admin.php">Admin</a><?php endif; ?>
			<a class="account-link" href="?page=profile"<?= $page === 'profile' ? ' aria-current="page"' : '' ?>>Profile</a>
			<span class="avatar" aria-label="Signed in as <?= escapeHtml($currentUser['name']) ?>"><?= escapeHtml(strtoupper(substr($currentUser['name'], 0, 1))) ?></span>
			<form class="signout-form" method="post" action="logout.php"><input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>"><input type="hidden" name="action" value="logout"><button class="account-link" style="background:none;border:0;cursor:pointer;padding:0" type="submit">Sign out</button></form>
		<?php else: ?>
			<a class="account-link" href="register.php">Join</a>
		<?php endif; ?>
	</div>
</header>

<main class="layout<?= $page === 'browse' || $page === 'profile' || $isHomeGallery ? ' browse-layout' : '' ?>">
<?php if ($page === 'browse' || $isHomeGallery): ?>
	<section class="browse-page">
		<div class="eyebrow"><span class="eyebrow-mark"></span> <?= $isHomeGallery ? 'Fresh from the community' : 'Find something worth watching' ?></div>
		<h1><?= $isHomeGallery ? 'Latest videos' : 'Browse videos' ?></h1>
		<?php if ($uploadError !== ''): ?><div class="form-error" role="alert"><?= escapeHtml($uploadError) ?></div><?php elseif ($notice !== ''): ?><div class="upload-success" role="status"><?= escapeHtml($notice) ?></div><?php endif; ?>
		<div class="section-heading"><h3><?= $isHomeGallery ? 'Most recent' : 'All videos' ?></h3><span><?= count($videos) ?> videos</span></div>
		<?php if ($videos === []): ?>
			<div class="empty-gallery"><h2>No videos yet</h2><p><?= !empty($settings['uploads_enabled']) ? 'Be the first to share a video with the community.' : 'There are no videos to show right now.' ?></p><div class="empty-gallery-actions"><?php if ($currentUser !== null && !empty($settings['uploads_enabled'])): ?><button class="upload-button" type="button" data-open-upload>Upload a video</button><?php elseif ($currentUser === null): ?><a class="account-link" href="login.php">Sign in to upload</a><a class="account-link" href="register.php">Create an account</a><?php endif; ?></div></div>
		<?php else: ?>
			<div class="chips" id="categoryChips">
				<button type="button" class="chip active" data-category="all">Everything</button>
				<?php foreach ($categories as $category): ?><button type="button" class="chip" data-category="<?= escapeHtml(strtolower($category)) ?>"><?= escapeHtml($category) ?></button><?php endforeach; ?>
			</div>
			<div class="video-grid" id="videoList">
				<?php foreach ($videos as $video) { renderVideoCard($video, $selectedVideo); } ?>
			</div>
			<div class="empty-results" id="emptyResults">No videos found. Try another search.</div>
		<?php endif; ?>
	</section>
<?php elseif ($page === 'profile'): ?>
	<section class="browse-page profile-page">
		<div class="eyebrow"><span class="eyebrow-mark"></span> Community profile</div>
		<?php if ($uploadError !== ''): ?><div class="form-error" role="alert"><?= escapeHtml($uploadError) ?></div><?php elseif ($notice !== ''): ?><div class="upload-success" role="status"><?= escapeHtml($notice) ?></div><?php endif; ?>
		<?php if ($profileUser === null): ?>
			<h1>Profile not found</h1>
			<p class="profile-bio">This profile may no longer be available.</p>
		<?php else: ?>
			<div class="profile-heading">
				<div class="profile-avatar" aria-hidden="true"><?= escapeHtml(strtoupper(substr((string) $profileUser['name'], 0, 1))) ?></div>
				<div><h1><?= escapeHtml($profileUser['name']) ?></h1><p><?= escapeHtml((string) ($profileUser['bio'] ?? '')) ?></p></div>
			</div>
			<?php if ($currentUser !== null && ($currentUser['id'] ?? '') === ($profileUser['id'] ?? '')): ?>
				<form class="profile-edit" method="post">
					<input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
					<input type="hidden" name="action" value="save_profile">
					<h2>Edit your profile</h2>
					<label class="field"><span>Display name</span><input type="text" name="name" minlength="2" maxlength="40" value="<?= escapeHtml($profileUser['name']) ?>" autocomplete="name" required></label>
					<label class="field"><span>Bio</span><textarea name="bio" maxlength="280" aria-describedby="profileBioHelp"><?= escapeHtml((string) ($profileUser['bio'] ?? '')) ?></textarea><small class="file-help" id="profileBioHelp">Up to 280 characters. This bio is visible to everyone.</small></label>
					<button class="primary-button" type="submit">Save profile</button>
				</form>
			<?php endif; ?>
			<div class="section-heading"><h3>Uploaded videos</h3><span><?= count($profileVideos) ?> videos</span></div>
			<?php if ($profileVideos === []): ?>
				<div class="empty-gallery"><h2>No public videos yet</h2><p><?= $currentUser !== null && ($currentUser['id'] ?? '') === ($profileUser['id'] ?? '') ? 'Videos you upload will appear here.' : 'This member has not shared any public videos yet.' ?></p></div>
			<?php else: ?>
				<div class="video-grid" id="videoList"><?php foreach ($profileVideos as $video) { renderVideoCard($video, $selectedVideo); } ?></div>
			<?php endif; ?>
		<?php endif; ?>
	</section>
<?php else: ?>
	<section class="main-column">
		<div class="eyebrow"><span class="eyebrow-mark"></span> The good stuff, in motion</div>
		<h1>Find your next favorite.</h1>

		<?php if ($isAdminPreview && in_array($selectedVideo['id'], $hiddenVideoIds, true)): ?><div class="upload-success" role="status">Admin preview: this video is hidden from the public site.</div><?php endif; ?>
		<?php if ($uploadError !== ''): ?>
			<div class="form-error" role="alert"><?= escapeHtml($uploadError) ?></div>
		<?php elseif ($notice !== ''): ?>
			<div class="upload-success" role="status"><?= escapeHtml($notice) ?></div>
		<?php elseif (isset($_GET['uploaded'])): ?>
			<div class="upload-success" role="status">Your video is live. Share it with someone who'll love it.</div>
		<?php endif; ?>
		<?php if (trim((string) $settings['announcement']) !== ''): ?><div class="upload-success" role="status"><?= escapeHtml($settings['announcement']) ?></div><?php endif; ?>

		<div class="player-shell">
			<?php if (videoThumbnailUrl($selectedVideo, $isAdminPreview) !== ''): ?>
					<video id="mainPlayer" controls playsinline preload="metadata" poster="<?= escapeHtml(videoThumbnailUrl($selectedVideo, $isAdminPreview)) ?>">
					<source src="<?= escapeHtml($selectedVideoUrl) ?>">
					Your browser does not support HTML video.
				</video>
			<?php else: ?>
				<video id="mainPlayer" controls playsinline preload="metadata">
					<source src="<?= escapeHtml($selectedVideoUrl) ?>">
					Your browser does not support HTML video.
				</video>
			<?php endif; ?>
		</div>

		<div class="video-meta">
			<div>
				<h2><?= escapeHtml($selectedVideo['title']) ?></h2>
				<div class="video-stats">
					<span><?= escapeHtml($selectedVideo['views']) ?> views</span><span class="dot"></span>
					<span><?= !empty($selectedVideo['uploaded_at']) && $selectedVideo['uploaded_at'] > 1000000000 ? 'Just now' : 'A little while ago' ?></span>
					<span class="tag"><?= escapeHtml($selectedVideo['category']) ?></span>
				</div>
			</div>
			<div class="meta-actions">
				<button class="icon-button" type="button" id="shareButton" aria-label="Copy video link" title="Copy video link">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><circle cx="18" cy="5" r="3"/><circle cx="6" cy="12" r="3"/><circle cx="18" cy="19" r="3"/><path d="m8.6 10.6 6.8-4.1M8.6 13.4l6.8 4.1"/></svg>
				</button>
				<button class="icon-button" type="button" id="likeButton" aria-label="Like video" title="Like video">
					<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round"><path d="M20.8 8.8c0 5.1-8.8 10-8.8 10s-8.8-4.9-8.8-10A4.7 4.7 0 0 1 12 6.3a4.7 4.7 0 0 1 8.8 2.5Z"/></svg>
				</button>
			</div>
		</div>

		<div class="description">
			<div class="creator-avatar"><?= escapeHtml(strtoupper(substr((string) $selectedVideo['creator'], 0, 1))) ?></div>
			<div>
				<?php if ($selectedVideoOwner !== null): ?><a class="creator-name" href="?page=profile&amp;id=<?= rawurlencode((string) $selectedVideoOwner['id']) ?>"><?= escapeHtml($selectedVideo['creator']) ?></a><?php else: ?><div class="creator-name"><?= escapeHtml($selectedVideo['creator']) ?></div><?php endif; ?>
				<p><?= escapeHtml($selectedVideo['description']) ?></p>
			</div>
		</div>

		<div class="section-heading"><h3>Browse by mood</h3><span><?= count($videos) ?> videos</span></div>
		<div class="chips" id="categoryChips">
			<button type="button" class="chip active" data-category="all">Everything</button>
			<?php foreach ($categories as $category): ?>
				<button type="button" class="chip" data-category="<?= escapeHtml(strtolower($category)) ?>"><?= escapeHtml($category) ?></button>
			<?php endforeach; ?>
		</div>
	</section>

	<aside class="sidebar">
		<div class="sidebar-heading"><h2>More to explore</h2><a class="account-link" href="?page=browse">Browse all</a></div>
		<div class="video-list" id="videoList">
			<?php foreach ($videos as $video) { renderVideoCard($video, $selectedVideo); } ?>
		</div>
		<div class="empty-results" id="emptyResults">No videos found. Try another search.</div>
		<div class="footer-note">A calmer corner of the internet for videos worth sharing.<br>Made for the moments that move you.</div>
	</aside>
<?php endif; ?>
</main>

<div class="modal-backdrop" id="uploadModal" role="dialog" aria-modal="true" aria-labelledby="uploadTitle">
	<form class="modal" id="uploadForm" method="post" action="<?= escapeHtml((string) ($_SERVER['SCRIPT_NAME'] ?? 'index.php')) ?>" enctype="multipart/form-data">
		<input type="hidden" name="action" value="upload">
		<input type="hidden" name="csrf_token" value="<?= escapeHtml($_SESSION['csrf_token']) ?>">
		<div class="modal-head">
			<div><h2 id="uploadTitle">Share videos</h2><p>Files upload in small parts; URL imports download directly on the server.</p></div>
			<button class="modal-close" type="button" data-close-upload aria-label="Close upload dialog">✕</button>
		</div>
		<label class="field"><span>Upload source</span><select id="uploadSource"><option value="device">From your device</option><option value="url">From a video URL</option></select></label>
		<div id="deviceUploadFields"><label class="field"><span>Videos or ZIP archive</span><div class="file-picker"><input type="file" name="video" accept="video/mp4,video/webm,video/ogg,application/zip,.mp4,.webm,.ogv,.ogg,.zip" multiple required><p class="file-help">Choose MP4, WebM, or Ogg videos, or a ZIP archive containing up to 50 videos. <?= ($currentUser['role'] ?? '') === 'admin' ? 'No application file-size limit; ZIP contents are limited to 10 GB total.' : 'Up to 1 GB per video and 1 GB per ZIP; ZIP contents are limited to 10 GB total.' ?>. Videos upload one at a time in small, automatically retried parts; the server extracts ZIP contents and generates a thumbnail for each video.</p></div></label></div>
		<div id="urlUploadFields" hidden><label class="field"><span>Video page or direct video URL</span><input id="videoUrlInput" name="video_url" type="url" inputmode="url" placeholder="https://example.com/watch/video" required disabled><small class="file-help">Use a public page with a downloadable embedded MP4, WebM, or Ogg video, or a direct video link (up to 1 GB). Protected/streaming-only embeds and private/local network links are not supported.</small></label></div>
		<label class="field"><span>Video title</span><input type="text" name="title" maxlength="100" placeholder="Optional; blank uses each file's name"><small class="file-help">A custom title is applied to every selected video.</small></label>
		<label class="field"><span>Your name</span><input type="text" name="creator" maxlength="50" placeholder="How should we credit you?"></label>
		<label class="field"><span>Category</span><select name="category"><?php foreach ($categoryOptions as $categoryOption): ?><option value="<?= escapeHtml($categoryOption) ?>"><?= escapeHtml($categoryOption) ?></option><?php endforeach; ?></select></label>
		<button class="primary-button modal-submit" type="submit">Upload video</button>
		<div class="upload-progress" id="uploadProgress" hidden>
			<div class="upload-progress-head"><span id="uploadStatus" role="status" aria-live="polite">Uploading video…</span><span class="upload-progress-metrics"><span id="uploadPercent">0%</span><span id="uploadSpeed" aria-label="Upload speed">— MB/s</span></span></div>
			<div class="upload-progress-track"><div class="upload-progress-bar" id="uploadProgressBar" role="progressbar" aria-label="Video upload progress" aria-valuemin="0" aria-valuemax="100" aria-valuenow="0"></div></div>
			<p class="upload-progress-details" id="uploadDetails">Keep this page open while your videos upload. They are sent one at a time.</p>
		</div>
	</form>
</div>
<div class="toast" id="toast" role="status" aria-live="polite"></div>

<script>
	const modal = document.getElementById('uploadModal');
	const toast = document.getElementById('toast');
	const searchInput = document.getElementById('searchInput');
	const uploadForm = document.getElementById('uploadForm');
	const uploadSource = document.getElementById('uploadSource');
	const deviceUploadFields = document.getElementById('deviceUploadFields');
	const urlUploadFields = document.getElementById('urlUploadFields');
	const videoUrlInput = document.getElementById('videoUrlInput');
	const uploadProgress = document.getElementById('uploadProgress');
	const uploadProgressBar = document.getElementById('uploadProgressBar');
	const uploadStatus = document.getElementById('uploadStatus');
	const uploadPercent = document.getElementById('uploadPercent');
	const uploadSpeed = document.getElementById('uploadSpeed');
	const uploadDetails = document.getElementById('uploadDetails');
	const cards = [...document.querySelectorAll('.video-card')];
	const chips = [...document.querySelectorAll('.chip')];
	let activeCategory = 'all';
	let toastTimer;
	let uploadInProgress = false;
	let uploadProgressPercent = 0;
	const uploadChunkSize = 1024 * 1024;

	function showToast(message) {
		toast.textContent = message;
		toast.classList.add('visible');
		window.clearTimeout(toastTimer);
		toastTimer = window.setTimeout(() => toast.classList.remove('visible'), 2300);
	}

	function setUploadProgress(percent) {
		uploadProgressPercent = Math.max(uploadProgressPercent, Math.max(0, Math.min(100, percent)));
		uploadProgressBar.style.width = `${uploadProgressPercent}%`;
		uploadProgressBar.setAttribute('aria-valuenow', String(uploadProgressPercent));
		uploadPercent.textContent = `${uploadProgressPercent}%`;
	}

	function formatUploadSpeed(bytesPerSecond) {
		if (bytesPerSecond >= 1024 * 1024) return `${(bytesPerSecond / (1024 * 1024)).toFixed(1)} MB/s`;
		if (bytesPerSecond >= 1024) return `${(bytesPerSecond / 1024).toFixed(0)} KB/s`;
		return `${Math.max(0, Math.round(bytesPerSecond))} B/s`;
	}

	function uploadVideoFromUrl(url) {
		return new Promise((resolve, reject) => {
			const request = new XMLHttpRequest();
			const formData = new FormData(uploadForm);
			formData.set('action', 'upload_url');
			formData.set('video_url', url);
			uploadStatus.textContent = 'Downloading video from URL…';
			uploadPercent.textContent = 'URL';
			uploadSpeed.textContent = '— MB/s';
			uploadProgressBar.classList.add('indeterminate');
			uploadDetails.textContent = 'The server is downloading the video. Transfer progress and network speed are unavailable for URL imports.';
			request.addEventListener('load', () => {
				let result = null;
				try {
					result = JSON.parse(request.responseText);
				} catch (error) {
					const responseDocument = new DOMParser().parseFromString(request.responseText, 'text/html');
					const serverMessage = responseDocument.querySelector('.form-error');
					reject(new Error(serverMessage?.textContent.trim() || `The server returned HTTP ${request.status || 'an unknown error'} while importing the URL.`));
					return;
				}
				if (request.status >= 200 && request.status < 400 && typeof result?.redirect === 'string') {
					const destination = new URL(result.redirect, window.location.href);
					if (destination.origin === window.location.origin) {
						resolve(destination.href);
						return;
					}
				}
				reject(new Error(typeof result?.error === 'string' ? result.error : `The server returned HTTP ${request.status || 'an unknown error'} while importing the URL.`));
			});
			request.addEventListener('error', () => reject(new Error('The connection to the site was interrupted while importing the URL.')));
			request.addEventListener('abort', () => reject(new Error('The URL import was interrupted.')));
			request.open('POST', uploadForm.getAttribute('action') || window.location.href);
			request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
			request.send(formData);
		});
	}

	function uploadOneVideo(file, totalFiles, completedBytes, totalBytes) {
		return new Promise((resolve, reject) => {
			const uploadId = [...window.crypto.getRandomValues(new Uint8Array(16))]
				.map((byte) => byte.toString(16).padStart(2, '0')).join('');
			const chunkCount = Math.ceil(file.size / uploadChunkSize);
			let chunkIndex = 0;
			let retryCount = 0;
			let lastProgressLoaded = 0;
			let lastProgressTime = performance.now();
			let smoothedSpeed = 0;

			function sendChunk() {
				const start = chunkIndex * uploadChunkSize;
				const chunk = file.slice(start, Math.min(start + uploadChunkSize, file.size));
				const isFinalChunk = chunkIndex === chunkCount - 1;
				lastProgressLoaded = 0;
				lastProgressTime = performance.now();
				const request = new XMLHttpRequest();
				const formData = new FormData(uploadForm);
				formData.delete('video');
				formData.set('action', 'upload_chunk');
				formData.append('chunk', chunk, file.name);
				formData.set('upload_id', uploadId);
				formData.set('chunk_index', String(chunkIndex));
				formData.set('chunk_count', String(chunkCount));
				formData.set('file_size', String(file.size));
				formData.set('file_name', file.name);
				if (totalFiles > 1 && !file.name.toLowerCase().endsWith('.zip') && !String(formData.get('title') || '').trim()) {
					formData.set('title', file.name.replace(/\.[^.]+$/, ''));
				}

				function retryOrReject(message, retryable) {
					if (retryable && retryCount < 3) {
						retryCount += 1;
						uploadStatus.textContent = 'Retrying upload…';
						uploadSpeed.textContent = '— MB/s';
						uploadDetails.textContent = `${file.name} · ${message} Retrying (${retryCount} of 3).`;
						window.setTimeout(sendChunk, 500 * (2 ** (retryCount - 1)));
						return;
					}
					reject(new Error(message));
				}

				request.upload.addEventListener('progress', (progressEvent) => {
					uploadStatus.textContent = 'Uploading videos…';
					const now = performance.now();
					const elapsedMilliseconds = now - lastProgressTime;
					if (elapsedMilliseconds > 0 && progressEvent.loaded >= lastProgressLoaded) {
						const currentSpeed = (progressEvent.loaded - lastProgressLoaded) * 1000 / elapsedMilliseconds;
						smoothedSpeed = smoothedSpeed === 0 ? currentSpeed : smoothedSpeed * 0.7 + currentSpeed * 0.3;
						uploadSpeed.textContent = formatUploadSpeed(smoothedSpeed);
					}
					lastProgressLoaded = progressEvent.loaded;
					lastProgressTime = now;
					if (!progressEvent.lengthComputable) return;
					const sentChunkBytes = Math.min(chunk.size, chunk.size * progressEvent.loaded / progressEvent.total);
					const sentFileBytes = start + sentChunkBytes;
					const batchProgress = ((completedBytes + sentFileBytes) / totalBytes) * 100;
					setUploadProgress(Math.floor(batchProgress));
					uploadDetails.textContent = `${file.name} · ${((start + sentChunkBytes) / 1024 / 1024).toFixed(1)} MB of ${(file.size / 1024 / 1024).toFixed(1)} MB sent.`;
				});
				request.upload.addEventListener('load', () => {
					setUploadProgress(Math.floor((completedBytes + start + chunk.size) / totalBytes * 100));
					if (isFinalChunk) {
						const isArchive = file.name.toLowerCase().endsWith('.zip');
						uploadStatus.textContent = isArchive ? 'Unpacking and saving videos…' : 'Saving video…';
						uploadPercent.textContent = 'Processing';
						uploadSpeed.textContent = '— MB/s';
						uploadProgressBar.classList.add('indeterminate');
						uploadDetails.textContent = isArchive
							? `${file.name} has reached the server. Its videos are being extracted, validated, and saved.`
							: `${file.name} has reached the server. It may still be saving the video and generating its thumbnail.`;
					} else {
						uploadStatus.textContent = 'Uploading videos…';
					}
				});
				request.addEventListener('load', () => {
					let result = null;
					try {
						result = JSON.parse(request.responseText);
					} catch (error) {
						const responseDocument = new DOMParser().parseFromString(request.responseText, 'text/html');
						const serverMessage = responseDocument.querySelector('.form-error');
						if (serverMessage) {
							retryOrReject(serverMessage.textContent.trim(), request.status >= 500);
							return;
						}
					}
					if (request.status >= 200 && request.status < 400) {
						if (typeof result?.redirect === 'string' && isFinalChunk) {
							const destination = new URL(result.redirect, window.location.href);
							if (destination.origin === window.location.origin) {
								resolve(destination.href);
								return;
							}
						}
						if (result?.chunk_received === true && !isFinalChunk) {
							chunkIndex += 1;
							retryCount = 0;
							sendChunk();
							return;
						}
						retryOrReject('The server responded, but did not confirm this upload.', request.status >= 500);
						return;
					}
					const message = typeof result?.error === 'string' ? result.error : request.status === 413
						? 'The web server rejected a 1 MB upload part. Check PHP post_max_size and Apache LimitRequestBody.'
						: request.status === 404
							? `The upload endpoint returned HTTP 404: ${request.responseURL || uploadForm.getAttribute('action') || window.location.href}`
							: `The server returned HTTP ${request.status || 'an unknown error'} for this video part.`;
					retryOrReject(message, request.status === 408 || request.status >= 500);
				});
				request.addEventListener('error', () => retryOrReject('The connection was interrupted.', true));
				request.addEventListener('timeout', () => retryOrReject('The video part timed out.', true));
				request.addEventListener('abort', () => reject(new Error('The upload was interrupted.')));
				request.open('POST', uploadForm.getAttribute('action') || window.location.href);
				request.timeout = isFinalChunk ? 0 : 300000;
				request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
				request.send(formData);
			}

			sendChunk();
		});
	}

	uploadSource.addEventListener('change', () => {
		const useUrl = uploadSource.value === 'url';
		deviceUploadFields.hidden = useUrl;
		urlUploadFields.hidden = !useUrl;
		uploadForm.querySelector('input[type="file"]').disabled = useUrl;
		videoUrlInput.disabled = !useUrl;
	});

	uploadForm.addEventListener('submit', async (event) => {
		if (uploadInProgress) {
			event.preventDefault();
			return;
		}
		const useUrl = uploadSource.value === 'url';
		const fileInput = uploadForm.querySelector('input[type="file"]');
		const files = useUrl ? [] : [...fileInput.files];
		if (!useUrl && files.length === 0) return;
		event.preventDefault();
		uploadInProgress = true;
		const submitButton = uploadForm.querySelector('.modal-submit');
		const closeButtons = modal.querySelectorAll('[data-close-upload]');
		uploadProgress.hidden = false;
		uploadDetails.style.color = '';
		uploadProgressBar.classList.remove('indeterminate');
		uploadProgressPercent = 0;
		setUploadProgress(0);
		uploadSpeed.textContent = '— MB/s';
		submitButton.disabled = true;
		closeButtons.forEach((button) => { button.disabled = true; });

		let completed = 0;
		let completedBytes = 0;
		const totalBytes = Math.max(files.reduce((total, file) => total + file.size, 0), 1);
		let lastRedirect = '';
		if (useUrl) {
			try {
				lastRedirect = await uploadVideoFromUrl(videoUrlInput.value.trim());
				completed = 1;
			} catch (error) {
				uploadInProgress = false;
				uploadStatus.textContent = 'URL import failed';
				uploadPercent.textContent = 'Failed';
				uploadSpeed.textContent = '— MB/s';
				uploadDetails.textContent = error.message;
				uploadDetails.style.color = '#ffc1ba';
				uploadProgressBar.classList.remove('indeterminate');
				submitButton.disabled = false;
				closeButtons.forEach((button) => { button.disabled = false; });
				return;
			}
		}
		for (let index = 0; index < files.length; index += 1) {
			uploadProgressBar.classList.remove('indeterminate');
			uploadSpeed.textContent = '— MB/s';
			uploadStatus.textContent = 'Uploading videos…';
			try {
				lastRedirect = await uploadOneVideo(files[index], files.length, completedBytes, totalBytes);
				completed += 1;
				completedBytes += files[index].size;
			} catch (error) {
				uploadInProgress = false;
				uploadStatus.textContent = `Stopped after ${completed} of ${files.length} selected files`;
				uploadPercent.textContent = 'Failed';
				uploadDetails.textContent = `${files[index].name}: ${error.message}${completed > 0 ? ` ${completed} selected file(s) completed successfully.` : ''}`;
				uploadDetails.style.color = '#ffc1ba';
				uploadProgressBar.classList.remove('indeterminate');
				submitButton.disabled = false;
				closeButtons.forEach((button) => { button.disabled = false; });
				return;
			}
		}

		uploadStatus.textContent = 'All selected uploads processed successfully';
		uploadPercent.textContent = 'Done';
		uploadSpeed.textContent = '— MB/s';
		uploadProgressBar.classList.remove('indeterminate');
		setUploadProgress(100);
		uploadDetails.textContent = 'Opening the last uploaded video…';
		window.location.assign(lastRedirect);
	});

	function filterVideos() {
		const query = searchInput.value.trim().toLowerCase();
		let visibleCount = 0;
		cards.forEach((card) => {
			const matchesQuery = card.dataset.title.includes(query);
			const matchesCategory = activeCategory === 'all' || card.dataset.category === activeCategory;
			card.hidden = !(matchesQuery && matchesCategory);
			if (!card.hidden) visibleCount += 1;
		});
		const emptyResults = document.getElementById('emptyResults');
		if (emptyResults) emptyResults.style.display = visibleCount ? 'none' : 'block';
	}

	document.querySelectorAll('[data-open-upload]').forEach((button) => button.addEventListener('click', () => {
		modal.classList.add('open');
		modal.querySelector('input[type="file"]').focus();
	}));
	document.querySelectorAll('[data-close-upload]').forEach((button) => button.addEventListener('click', () => {
		if (!uploadInProgress) modal.classList.remove('open');
	}));
	modal.addEventListener('click', (event) => {
		if (event.target === modal && !uploadInProgress) modal.classList.remove('open');
	});
	document.addEventListener('keydown', (event) => {
		if (event.key === 'Escape' && !uploadInProgress) modal.classList.remove('open');
	});
	searchInput.addEventListener('input', filterVideos);
	chips.forEach((chip) => chip.addEventListener('click', () => {
		activeCategory = chip.dataset.category;
		chips.forEach((item) => item.classList.toggle('active', item === chip));
		filterVideos();
	}));
	const shareButton = document.getElementById('shareButton');
	if (shareButton) shareButton.addEventListener('click', async () => {
		try {
			await navigator.clipboard.writeText(new URL(<?= json_encode($shareUrl, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>, window.location.href).href);
			showToast('Link copied — go make someone’s day.');
		} catch (error) {
			showToast('Copy this link from your browser’s address bar.');
		}
	});
	const likeButton = document.getElementById('likeButton');
	if (likeButton) likeButton.addEventListener('click', (event) => {
		const button = event.currentTarget;
		const liked = button.dataset.liked === 'true';
		button.dataset.liked = String(!liked);
		button.style.background = liked ? '' : '#2b3024';
		button.style.borderColor = liked ? '' : '#6e884d';
		button.querySelector('svg').style.color = liked ? '' : '#c9f39d';
		showToast(liked ? 'Like removed' : 'Added to your likes');
	});
</script>
</body>
</html>
