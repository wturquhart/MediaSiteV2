<?php
ini_set('session.use_strict_mode', '1');
ini_set('session.use_only_cookies', '1');
session_set_cookie_params([
	'httponly' => true,
	'samesite' => 'Strict',
	'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
]);
session_start();

header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
header('Cache-Control: no-store');

function escapeInstallHtml(string $value): string
{
	return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function installDataDirectory(): string
{
	$configured = getenv('FRAME_DATA_DIR');
	return $configured !== false && $configured !== ''
		? rtrim($configured, DIRECTORY_SEPARATOR)
		: dirname(__DIR__) . DIRECTORY_SEPARATOR . 'mediasite-data';
}

function installDatabaseConfigurationPath(): string
{
	return installDataDirectory() . DIRECTORY_SEPARATOR . 'database-config.json';
}

function installerIsDisabled(): bool
{
	if (is_file(installDatabaseConfigurationPath())) {
		return true;
	}
	foreach (['FRAME_DB_NAME', 'FRAME_DB_USER', 'FRAME_DB_PASSWORD'] as $name) {
		$value = getenv($name);
		if ($value !== false && $value !== '') {
			return true;
		}
	}
	return false;
}

function saveDatabaseConfiguration(string $path, array $configuration): void
{
	$json = json_encode($configuration, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
	$temporaryPath = tempnam(dirname($path), '.database-config-');
	if ($temporaryPath === false) {
		throw new RuntimeException('Could not create a temporary database configuration file.');
	}
	try {
		if (file_put_contents($temporaryPath, $json, LOCK_EX) === false || !chmod($temporaryPath, 0600)) {
			throw new RuntimeException('Could not securely write the database configuration file.');
		}
		if (!rename($temporaryPath, $path)) {
			throw new RuntimeException('Could not place the database configuration file in private storage.');
		}
	} finally {
		if (is_file($temporaryPath)) {
			unlink($temporaryPath);
		}
	}
}

if (empty($_SESSION['install_csrf'])) {
	$_SESSION['install_csrf'] = bin2hex(random_bytes(32));
}

$remoteAddress = (string) ($_SERVER['REMOTE_ADDR'] ?? '');
$localRequest = in_array($remoteAddress, ['127.0.0.1', '::1'], true);
$requestIsHttps = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
$setupAllowed = $localRequest || $requestIsHttps;
$error = '';
$complete = installerIsDisabled();
$form = [
	'db_host' => '127.0.0.1',
	'db_port' => '3306',
	'db_name' => 'frame',
	'db_user' => 'frame_app',
	'name' => '',
	'email' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$complete && $setupAllowed) {
	if (!hash_equals((string) $_SESSION['install_csrf'], (string) ($_POST['csrf_token'] ?? ''))) {
		$error = 'Your setup session expired. Reload this page and try again.';
	} else {
		foreach (array_keys($form) as $field) {
			$form[$field] = trim((string) ($_POST[$field] ?? ''));
		}
		$password = (string) ($_POST['db_password'] ?? '');
		$adminPassword = (string) ($_POST['admin_password'] ?? '');
		$adminPasswordConfirm = (string) ($_POST['admin_password_confirm'] ?? '');

		if (!preg_match('/^[A-Za-z0-9.-]+$/', $form['db_host'])) {
			$error = 'Enter a valid MySQL host name or IP address.';
		} elseif (!ctype_digit($form['db_port']) || (int) $form['db_port'] < 1 || (int) $form['db_port'] > 65535) {
			$error = 'Enter a valid MySQL port number.';
		} elseif (!preg_match('/^[A-Za-z0-9_]+$/', $form['db_name']) || strlen($form['db_name']) > 64) {
			$error = 'The database name may contain only letters, numbers, and underscores (up to 64 characters).';
		} elseif (!preg_match('/^[A-Za-z0-9_]+$/', $form['db_user']) || strlen($form['db_user']) > 32) {
			$error = 'The database username may contain only letters, numbers, and underscores (up to 32 characters).';
		} elseif ($password === '' || strlen($password) > 256) {
			$error = 'Enter the database user password (up to 256 bytes).';
		} elseif (strlen($form['name']) < 2 || strlen($form['name']) > 40 || !preg_match('/^[\p{L}\p{N} _.-]+$/u', $form['name'])) {
			$error = 'Enter an administrator name between 2 and 40 letters, numbers, spaces, dots, underscores, or hyphens.';
		} elseif (!filter_var($form['email'], FILTER_VALIDATE_EMAIL) || strlen($form['email']) > 254) {
			$error = 'Enter a valid administrator email address.';
		} elseif (strlen($adminPassword) < 12 || strlen($adminPassword) > 72) {
			$error = 'Choose an administrator password between 12 and 72 bytes.';
		} elseif (!hash_equals($adminPassword, $adminPasswordConfirm)) {
			$error = 'The administrator passwords do not match.';
		} else {
			$directory = installDataDirectory();
			if (!is_dir($directory) && !mkdir($directory, 0750, true) && !is_dir($directory)) {
				$error = 'The private data directory could not be created. Check its parent directory permissions.';
			} else {
				$publicDirectory = realpath(__DIR__);
				$privateDirectory = realpath($directory);
				$publicPrefix = $publicDirectory === false ? '' : rtrim($publicDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
				if ($publicDirectory === false || $privateDirectory === false || str_starts_with(strtolower($privateDirectory . DIRECTORY_SEPARATOR), strtolower($publicPrefix))) {
					$error = 'The private data directory must be outside the public web directory.';
				} else {
					$lockPath = $directory . DIRECTORY_SEPARATOR . 'installer.lock';
					$lock = @fopen($lockPath, 'c');
					if ($lock === false || !flock($lock, LOCK_EX | LOCK_NB)) {
						if (is_resource($lock)) {
							fclose($lock);
						}
						$complete = installerIsDisabled();
						$error = $complete ? '' : 'Another setup request is in progress, or the installer lock could not be created. Check the private data directory permissions.';
					} else {
						$configPath = installDatabaseConfigurationPath();
						$configurationSaved = false;
						$installationCommitted = false;
						$connection = null;
					try {
						if (installerIsDisabled()) {
							$complete = true;
							throw new RuntimeException('Setup has already been completed or database settings are already configured.');
						}
						$legacyPaths = [
							$directory . DIRECTORY_SEPARATOR . 'frame.sqlite',
							$directory . DIRECTORY_SEPARATOR . 'users.json',
							$directory . DIRECTORY_SEPARATOR . 'settings.json',
							$directory . DIRECTORY_SEPARATOR . 'videos.json',
							__DIR__ . DIRECTORY_SEPARATOR . 'data' . DIRECTORY_SEPARATOR . 'videos.json',
						];
						foreach ($legacyPaths as $legacyPath) {
							if (is_file($legacyPath)) {
								throw new RuntimeException('Legacy application data was found. This web installer is only for fresh installs; back up and migrate existing data before switching.');
							}
						}
						$dsn = 'mysql:host=' . $form['db_host'] . ';port=' . $form['db_port'] . ';dbname=' . $form['db_name'] . ';charset=utf8mb4';
						$connection = new PDO($dsn, $form['db_user'], $password, [
							PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
							PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
							PDO::ATTR_EMULATE_PREPARES => false,
						]);
						$connection->exec('CREATE TABLE IF NOT EXISTS app_data (data_key VARCHAR(191) NOT NULL PRIMARY KEY, data_value LONGTEXT NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
						$connection->exec('CREATE TABLE IF NOT EXISTS rate_limits (rate_key CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL PRIMARY KEY, attempts INT NOT NULL, window_started BIGINT NOT NULL) ENGINE=InnoDB');
						$lockName = 'frame_web_installer_' . substr(hash('sha256', $form['db_name']), 0, 40);
						$lockQuery = $connection->prepare('SELECT GET_LOCK(:lock_name, 10)');
						$lockQuery->execute(['lock_name' => $lockName]);
						if ((int) $lockQuery->fetchColumn() !== 1) {
							throw new RuntimeException('Could not acquire the database setup lock. Please retry.');
						}
						try {
							if ((int) $connection->query('SELECT COUNT(*) FROM app_data')->fetchColumn() > 0) {
								throw new RuntimeException('This database already contains application data. Choose a new empty database; setup will not overwrite existing data.');
							}
							$connection->beginTransaction();
						try {
							$admin = [[
								'id' => bin2hex(random_bytes(12)),
								'name' => $form['name'],
								'email' => strtolower($form['email']),
								'password_hash' => password_hash($adminPassword, PASSWORD_DEFAULT),
								'role' => 'admin',
								'created_at' => time(),
							]];
							$insert = $connection->prepare('INSERT INTO app_data (data_key, data_value) VALUES (:key, :value)');
							$insert->execute(['key' => 'users.json', 'value' => json_encode($admin, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)]);
							$insert->execute(['key' => '__legacy_json_migrated__', 'value' => '[]']);
							$insert->execute(['key' => '__sqlite_imported__', 'value' => 'fresh-install']);
							$config = [
								'host' => $form['db_host'],
								'port' => (int) $form['db_port'],
								'name' => $form['db_name'],
								'user' => $form['db_user'],
								'password' => $password,
							];
							saveDatabaseConfiguration($configPath, $config);
							$configurationSaved = true;
							$connection->commit();
							$installationCommitted = true;
						} catch (Throwable $exception) {
							if ($connection->inTransaction()) {
								$connection->rollBack();
							}
							throw $exception;
						}
					} finally {
						$release = $connection->prepare('SELECT RELEASE_LOCK(:lock_name)');
						$release->execute(['lock_name' => $lockName]);
					}
					session_regenerate_id(true);
					$_SESSION['user_id'] = $admin[0]['id'];
					header('Location: admin.php', true, 303);
					exit;
				} catch (Throwable $exception) {
					if ($configurationSaved && !$installationCommitted && is_file($configPath) && !unlink($configPath)) {
						error_log('Frame installer could not remove an incomplete database configuration.');
					}
					$error = 'Setup failed: ' . $exception->getMessage();
				} finally {
					if ($connection instanceof PDO) {
						$connection = null;
					}
					flock($lock, LOCK_UN);
					fclose($lock);
				}
			}
		}
	}
}
}
}

$complete = $complete || installerIsDisabled();
?>
<!doctype html>
<html lang="en">
<head>
	<meta charset="utf-8">
	<meta name="viewport" content="width=device-width, initial-scale=1">
	<meta name="theme-color" content="#101114">
	<title>Initial setup — frame</title>
	<style>
		:root{color-scheme:dark;--bg:#101114;--panel:#191a1f;--line:#303139;--text:#f4f3f0;--muted:#a0a0a8;--accent:#b9f36a;--ink:#17200f}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:radial-gradient(ellipse at 70% 8%,#24271f,transparent 42%),var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",sans-serif;display:grid;place-items:center;padding:24px}.card{width:min(560px,100%);background:rgba(25,26,31,.96);border:1px solid var(--line);border-radius:20px;padding:32px;box-shadow:0 28px 80px #0006}.brand{display:inline-flex;align-items:center;gap:10px;font-weight:800;font-size:21px;letter-spacing:-.8px;margin-bottom:24px;color:var(--text);text-decoration:none}.mark{width:31px;height:31px;border-radius:10px;background:var(--accent);color:var(--ink);display:grid;place-items:center}.mark svg{width:18px}h1{font-size:26px;letter-spacing:-.8px;margin:0 0 6px}.sub{color:var(--muted);font-size:13px;margin:0 0 22px}.section-title{font-size:13px;color:var(--accent);font-weight:750;margin:22px 0 12px}.fields{display:grid;grid-template-columns:1fr 1fr;gap:0 12px}.field{display:block;margin:0 0 13px}.field.full{grid-column:1/-1}.field span{display:block;font-size:12px;font-weight:650;margin-bottom:6px}.field input{width:100%;height:43px;background:#111216;color:#fff;border:1px solid #393a42;border-radius:9px;padding:0 11px;outline:none}.field input:focus{border-color:#9bb97c}.primary{width:100%;height:46px;border:0;border-radius:10px;background:var(--accent);color:var(--ink);font-weight:750;cursor:pointer;margin-top:8px}.message{border-radius:10px;padding:11px 13px;margin:0 0 16px;font-size:13px;background:#20291a;color:#c9f39d}.error{background:#301c1d;color:#ffc1ba}.hint{font-size:12px;color:#92939b;margin:14px 0 0}.notice{border:1px solid var(--line);border-radius:11px;padding:15px;color:var(--muted);font-size:13px}.notice a{color:var(--accent)}@media(max-width:520px){body{padding:14px}.card{padding:24px 19px}.fields{display:block}}
	</style>
</head>
<body>
<main class="card">
	<a class="brand" href="./"><span class="mark" aria-hidden="true"><svg viewBox="0 0 24 24" fill="currentColor"><path d="M8 5.7c0-.8.9-1.3 1.6-.9l9.1 5.3c.7.4.7 1.4 0 1.8l-9.1 5.3c-.7.4-1.6-.1-1.6-.9V5.7Z"/></svg></span>frame / setup</a>
	<?php if ($complete): ?>
		<h1>Setup is already complete</h1>
		<p class="sub">The installer is locked because this site already has database configuration.</p>
		<div class="notice">For security, this page cannot be used to replace an installed database or administrator. <a href="login.php">Sign in</a> or return to the <a href="./">video site</a>.</div>
	<?php elseif (!$setupAllowed): ?>
		<h1>Secure connection required</h1>
		<p class="sub">Remote setup sends database credentials and is available only over HTTPS.</p>
		<div class="notice">Open this page using your site's HTTPS address, then continue setup.</div>
	<?php else: ?>
		<h1>Set up your site</h1>
		<p class="sub">Connect an empty MySQL database and create your first administrator. Your database password is saved outside the public web directory.</p>
		<?php if ($error !== ''): ?><div class="message error" role="alert"><?= escapeInstallHtml($error) ?></div><?php endif; ?>
		<form method="post" autocomplete="on">
			<input type="hidden" name="csrf_token" value="<?= escapeInstallHtml((string) $_SESSION['install_csrf']) ?>">
			<h2 class="section-title">MySQL connection</h2>
			<div class="fields">
				<label class="field"><span>Database host</span><input name="db_host" value="<?= escapeInstallHtml($form['db_host']) ?>" required autocomplete="off"></label>
				<label class="field"><span>Port</span><input name="db_port" type="number" min="1" max="65535" value="<?= escapeInstallHtml($form['db_port']) ?>" required autocomplete="off"></label>
				<label class="field"><span>Database name</span><input name="db_name" value="<?= escapeInstallHtml($form['db_name']) ?>" maxlength="64" required autocomplete="off"></label>
				<label class="field"><span>Database username</span><input name="db_user" value="<?= escapeInstallHtml($form['db_user']) ?>" maxlength="32" required autocomplete="username"></label>
				<label class="field full"><span>Database password</span><input name="db_password" type="password" maxlength="256" required autocomplete="new-password"></label>
			</div>
			<h2 class="section-title">Administrator account</h2>
			<div class="fields">
				<label class="field"><span>Name</span><input name="name" value="<?= escapeInstallHtml($form['name']) ?>" minlength="2" maxlength="40" required autocomplete="name"></label>
				<label class="field"><span>Email</span><input name="email" type="email" value="<?= escapeInstallHtml($form['email']) ?>" maxlength="254" required autocomplete="email"></label>
				<label class="field"><span>Password</span><input name="admin_password" type="password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
				<label class="field"><span>Confirm password</span><input name="admin_password_confirm" type="password" minlength="12" maxlength="72" required autocomplete="new-password"></label>
			</div>
			<button class="primary" type="submit">Test database and finish setup</button>
			<p class="hint">Create the database and a dedicated MySQL user before continuing. Use HTTPS on a live server; never send database credentials over plain HTTP.</p>
		</form>
	<?php endif; ?>
</main>
</body>
</html>
