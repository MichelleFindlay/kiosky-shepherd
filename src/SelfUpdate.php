<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Auth\Principal;
use Shepherd\Http\HttpError;

/**
 * Updates Kiosky Shepherd itself from its GitHub repository, from the console (or by
 * cron when 'self_update' => ['auto' => true]).
 *
 * Channels follow branches: stable = the newest commit on main, dev = the newest
 * commit on dev (configurable). The exact commit installed is recorded in storage,
 * so dev builds that keep the same version number are still noticed.
 *
 * Install: download that commit's archive from GitHub over HTTPS, check every path in
 * it and that it is the version it claimed, zip the current files into
 * storage/backups, then write the new files one by one (each to a temporary file,
 * then renamed into place). config/config.php, storage/ and .user.ini are never
 * touched. If writing fails part-way, the backup is restored. Database updates in
 * the new version apply on the next request (Migrator::autoRun).
 */
final class SelfUpdate
{
    public const CHANNELS = ['stable', 'dev'];

    private const CACHE = 'cache/self-update.json';
    private const LAST = 'self-update-last.json';
    private const INSTALLED = 'self-update-installed.json';
    private const CHECK_EVERY = 6 * 3600;
    private const MAX_ARCHIVE = 40 * 1024 * 1024;
    private const KEEP_BACKUPS = 5;
    private const HOSTS = ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com'];

    /** Files an update never overwrites (relative to the project root). */
    private const KEEP = ['config/config.php', '.user.ini'];
    /** Paths an update never writes into. */
    private const SKIP_PREFIXES = ['storage/', '.git/', '.github/'];
    private const SKIP_FILES = ['.gitignore'];
    /** Present in every real build; checked before anything is written. */
    private const REQUIRED = ['index.php', 'src/bootstrap.php', 'src/Migrator.php', 'admin/app.js', 'admin/index.html'];

    // ---- Settings ---------------------------------------------------------------

    public static function repo(): string
    {
        $repo = (string) Config::get('self_update.repo', 'MichelleFindlay/kiosky-shepherd');
        return preg_match('#^[A-Za-z0-9_.\-]+/[A-Za-z0-9_.\-]+$#', $repo) ? $repo : 'MichelleFindlay/kiosky-shepherd';
    }

    public static function enabled(): bool
    {
        return (bool) Config::get('self_update.enabled', true);
    }

    public static function auto(): bool
    {
        return (bool) Config::get('self_update.auto', false);
    }

    public static function channel(): string
    {
        return Config::get('self_update.channel', 'stable') === 'dev' ? 'dev' : 'stable';
    }

    /** The branch a channel follows: main and dev unless config.php says otherwise. */
    public static function branch(?string $channel = null): string
    {
        $channel ??= self::channel();
        $b = (string) Config::get("self_update.branches.$channel", $channel === 'dev' ? 'dev' : 'main');
        return preg_match('#^[A-Za-z0-9._/\-]{1,100}$#', $b) && !str_contains($b, '..') ? $b : ($channel === 'dev' ? 'dev' : 'main');
    }

    /** @return array<string, string> */
    private static function githubHeaders(): array
    {
        $h = ['Accept' => 'application/vnd.github+json', 'X-GitHub-Api-Version' => '2022-11-28'];
        $token = (string) Config::get('self_update.github_token', '');
        if ($token !== '') {
            $h['Authorization'] = 'Bearer ' . $token;
        }
        return $h;
    }

    private static function api(string $path): array
    {
        $r = HttpClient::request('GET', 'https://api.github.com/repos/' . self::repo() . $path, self::githubHeaders());
        if ($r['status'] === 403 || $r['status'] === 429) {
            throw new \RuntimeException('GitHub rate-limited this server. Try again later, or set self_update.github_token.');
        }
        $data = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($data)) {
            throw new \RuntimeException("GitHub answered HTTP {$r['status']} for $path.", $r['status']);
        }
        return $data;
    }

    // ---- What's installed --------------------------------------------------------

    /** @return array{sha: ?string, branch: ?string, version: string} the build on this server */
    public static function installed(): array
    {
        $data = json_decode((string) @file_get_contents(Config::storage(self::INSTALLED)), true);
        // Only trust the recorded commit if the files still say the version recorded with it
        if (is_array($data) && ($data['version'] ?? null) === SHEPHERD_VERSION && is_string($data['sha'] ?? null)) {
            return ['sha' => $data['sha'], 'branch' => is_string($data['branch'] ?? null) ? $data['branch'] : null, 'version' => SHEPHERD_VERSION];
        }
        return ['sha' => null, 'branch' => null, 'version' => SHEPHERD_VERSION];
    }

    private static function recordInstalled(?string $sha, ?string $branch, string $version): void
    {
        @file_put_contents(Config::storage(self::INSTALLED), json_encode([
            'sha' => $sha, 'branch' => $branch, 'version' => $version, 'installed_at' => gmdate('c'),
        ], JSON_UNESCAPED_SLASHES), LOCK_EX);
    }

    // ---- Checking ---------------------------------------------------------------

    /** The last check's result, without contacting GitHub (for the session and banner). */
    public static function cached(): ?array
    {
        $data = json_decode((string) @file_get_contents(Config::storage(self::CACHE)), true);
        if (!is_array($data) || !is_string($data['sha'] ?? null)) {
            return is_array($data) ? $data + ['available' => false, 'current' => SHEPHERD_VERSION] : null;
        }
        $installed = self::installed();
        $latest = (string) ($data['latest'] ?? '');
        $data['current'] = SHEPHERD_VERSION;
        $data['installed_sha'] = $installed['sha'];
        $data['downgrade'] = $latest !== '' && version_compare($latest, SHEPHERD_VERSION, '<');
        if ($installed['sha'] !== null) {
            $data['available'] = !str_starts_with($data['sha'], $installed['sha']) && !str_starts_with($installed['sha'], $data['sha']);
            $data['unknown_build'] = false;
        } else {
            // A copy uploaded by hand: we don't know its commit. On stable, offer only a
            // newer version; on dev, offer to sync with the branch.
            $data['unknown_build'] = true;
            $data['available'] = ($data['channel'] ?? 'stable') === 'dev'
                ? !$data['downgrade']
                : $latest !== '' && version_compare($latest, SHEPHERD_VERSION, '>');
        }
        // A check made for another channel (the setting changed since) doesn't count
        if (($data['channel'] ?? null) !== self::channel()) {
            $data['available'] = false;
            $data['stale_channel'] = true;
        }
        return $data;
    }

    /**
     * Asks GitHub for the newest commit on the channel's branch, its version and what
     * changed since the installed build. Cached for 6 hours unless $force.
     */
    public static function check(bool $force = false): array
    {
        $cached = self::cached();
        if (!$force && $cached !== null && empty($cached['stale_channel'])
            && strtotime((string) ($cached['checked_at'] ?? '')) > time() - self::CHECK_EVERY) {
            return $cached;
        }
        $channel = self::channel();
        $branch = self::branch($channel);
        $result = ['checked_at' => gmdate('c'), 'repo' => self::repo(), 'channel' => $channel, 'branch' => $branch, 'error' => null];
        try {
            $result += self::branchHead($branch);
        } catch (\Throwable $e) {
            // Keep what we knew for this channel, so a GitHub hiccup doesn't hide an update
            $result['error'] = $e->getMessage();
            if (($cached['channel'] ?? null) === $channel) {
                foreach (['sha', 'latest', 'message', 'committed_at', 'url', 'zip', 'commits', 'notes', 'release_url'] as $k) {
                    if (isset($cached[$k])) {
                        $result[$k] = $cached[$k];
                    }
                }
            }
        }
        $dir = dirname(Config::storage(self::CACHE));
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        @file_put_contents(Config::storage(self::CACHE), json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX);
        return self::cached() ?? $result;
    }

    private static function branchHead(string $branch): array
    {
        try {
            $b = self::api('/branches/' . rawurlencode($branch));
        } catch (\RuntimeException $e) {
            if ($e->getCode() === 404) {
                throw new \RuntimeException('GitHub has no branch ' . $branch . ' in ' . self::repo() . ' (or the repository is private).');
            }
            throw $e;
        }
        $sha = (string) ($b['commit']['sha'] ?? '');
        if (!preg_match('/^[0-9a-f]{40}$/', $sha)) {
            throw new \RuntimeException("GitHub returned no commit for $branch.");
        }
        // The version that commit declares
        $file = self::api('/contents/src/bootstrap.php?ref=' . $sha);
        $boot = base64_decode((string) ($file['content'] ?? ''), false) ?: '';
        if (!preg_match("/define\\('SHEPHERD_VERSION', '([^']+)'\\)/", $boot, $m)) {
            throw new \RuntimeException("The $branch branch doesn't look like Kiosky Shepherd (no version in src/bootstrap.php).");
        }
        $out = [
            'sha' => $sha,
            'latest' => $m[1],
            'message' => mb_substr((string) ($b['commit']['commit']['message'] ?? ''), 0, 2000),
            'committed_at' => $b['commit']['commit']['committer']['date'] ?? null,
            'url' => 'https://github.com/' . self::repo() . '/commit/' . $sha,
            'zip' => 'https://api.github.com/repos/' . self::repo() . '/zipball/' . $sha,
            'commits' => [],
            'notes' => '',
            'release_url' => null,
        ];
        // What changed since the installed build, when we know which commit that is
        $installed = self::installed()['sha'];
        if ($installed !== null && $installed !== $sha) {
            try {
                $cmp = self::api('/compare/' . $installed . '...' . $sha);
                foreach (array_slice(array_reverse((array) ($cmp['commits'] ?? [])), 0, 30) as $c) {
                    $out['commits'][] = [
                        'sha' => substr((string) ($c['sha'] ?? ''), 0, 7),
                        'message' => mb_substr(strtok((string) ($c['commit']['message'] ?? ''), "\n") ?: '', 0, 200),
                        'date' => $c['commit']['committer']['date'] ?? null,
                    ];
                }
                $out['behind'] = (int) ($cmp['ahead_by'] ?? count($out['commits']));
            } catch (\Throwable) {
                // e.g. a branch that was force-pushed: the list is only a nicety
            }
        }
        // Release notes when that version has a GitHub release
        try {
            $rel = self::api('/releases/tags/' . rawurlencode('v' . $m[1]));
            $out['notes'] = mb_substr((string) ($rel['body'] ?? ''), 0, 20000);
            $out['release_url'] = $rel['html_url'] ?? null;
        } catch (\Throwable) {
            // no release for this version (normal on dev)
        }
        return $out;
    }

    // ---- Preflight --------------------------------------------------------------

    /**
     * Reasons an install can't run here, in plain words. Empty when it can.
     *
     * @return list<string>
     */
    public static function problems(): array
    {
        $out = [];
        if (!self::enabled()) {
            $out[] = 'Installing from the console is turned off (self_update.enabled in config/config.php).';
        }
        if (!class_exists(\ZipArchive::class)) {
            $out[] = 'PHP\'s zip extension is missing.';
        }
        if (!function_exists('curl_init')) {
            $out[] = 'PHP\'s curl extension is missing.';
        }
        if (is_dir(SHEPHERD_ROOT . '/.git')) {
            $out[] = 'This copy is a git checkout; update it with git pull instead.';
        }
        $storage = Config::storage();
        if (!is_dir($storage) || !is_writable($storage)) {
            $out[] = "PHP can't write to $storage.";
        }
        $unwritable = self::unwritable();
        if ($unwritable !== []) {
            $out[] = 'PHP can\'t write to ' . implode(', ', array_slice($unwritable, 0, 5))
                . (count($unwritable) > 5 ? ' and ' . (count($unwritable) - 5) . ' more' : '')
                . '. The web server needs write access to the project files (common on cPanel), or update by uploading files.';
        }
        return $out;
    }

    /** @return list<string> project files and folders PHP can't write (relative paths) */
    private static function unwritable(): array
    {
        $out = [];
        foreach (self::currentFiles() as $rel) {
            $path = SHEPHERD_ROOT . '/' . $rel;
            if (!is_writable($path) || !is_writable(dirname($path))) {
                $out[] = $rel;
            }
        }
        if (!is_writable(SHEPHERD_ROOT)) {
            array_unshift($out, './');
        }
        return array_values(array_unique($out));
    }

    /** @return list<string> every project file an update may replace (relative paths) */
    private static function currentFiles(): array
    {
        $files = [];
        $it = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(SHEPHERD_ROOT, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );
        $storage = realpath(Config::storage()) ?: Config::storage();
        foreach ($it as $f) {
            /** @var \SplFileInfo $f */
            $path = $f->getPathname();
            if (str_starts_with($path, $storage) || str_starts_with($path, Config::storage())) {
                continue;
            }
            $rel = substr($path, strlen(SHEPHERD_ROOT) + 1);
            if ($f->isFile() && self::managed($rel)) {
                $files[] = $rel;
            }
        }
        sort($files);
        return $files;
    }

    /** True for a path an update may write. */
    private static function managed(string $rel): bool
    {
        if (in_array($rel, self::KEEP, true) || in_array(basename($rel), self::SKIP_FILES, true)) {
            return false;
        }
        foreach (self::SKIP_PREFIXES as $p) {
            if (str_starts_with($rel, $p)) {
                return false;
            }
        }
        return true;
    }

    // ---- Status for the console --------------------------------------------------

    public static function status(): array
    {
        $last = json_decode((string) @file_get_contents(Config::storage(self::LAST)), true);
        return [
            'current' => SHEPHERD_VERSION,
            'installed' => self::installed(),
            'repo' => self::repo(),
            'channel' => self::channel(),
            'branches' => ['stable' => self::branch('stable'), 'dev' => self::branch('dev')],
            'check' => self::cached(),
            'problems' => self::problems(),
            'auto' => self::auto(),
            'last' => is_array($last) ? $last : null,
            'backups' => self::backups(),
        ];
    }

    /** @return list<array{name: string, version: string, sha: ?string, size: int, created_at: string}> newest first */
    public static function backups(): array
    {
        $out = [];
        foreach (glob(Config::storage('backups') . '/shepherd-*.zip') ?: [] as $f) {
            if (preg_match('/^shepherd-(.+)-(\d{8}-\d{6})\.zip$/', basename($f), $m)) {
                $out[] = ['name' => basename($f), 'version' => $m[1], 'sha' => self::backupMeta($f)['sha'] ?? null,
                    'size' => (int) filesize($f), 'created_at' => gmdate('c', (int) filemtime($f))];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $out;
    }

    /** The installed-build record stored in a backup's zip comment. */
    private static function backupMeta(string $path): array
    {
        $zip = new \ZipArchive();
        if (!class_exists(\ZipArchive::class) || $zip->open($path) !== true) {
            return [];
        }
        $meta = json_decode((string) $zip->getArchiveComment(), true);
        $zip->close();
        return is_array($meta) ? $meta : [];
    }

    // ---- Install and roll back ---------------------------------------------------

    /**
     * Installs the newest commit on the channel's branch. $sha must match it (as found
     * by a fresh check), so a click on a stale page can't install something unexpected.
     */
    public static function install(string $sha, ?Principal $by): array
    {
        $problems = self::problems();
        if ($problems !== []) {
            throw HttpError::conflict(implode(' ', $problems));
        }
        $info = self::check(true);
        if (!empty($info['error'])) {
            throw HttpError::conflict('Couldn\'t check GitHub: ' . $info['error']);
        }
        if (!is_string($info['sha'] ?? null) || $info['sha'] !== $sha) {
            throw HttpError::conflict('The ' . ($info['branch'] ?? 'branch') . ' branch has moved on since this page loaded. Reload it.');
        }
        $version = (string) $info['latest'];
        $zipUrl = (string) $info['zip'];
        if (!str_starts_with($zipUrl, 'https://api.github.com/repos/' . self::repo() . '/zipball/')) {
            throw HttpError::conflict('Unexpected download address.');
        }

        @set_time_limit(600);
        ignore_user_abort(true);
        $lock = self::lock();
        $work = Config::storage('updates/' . substr($sha, 0, 12) . '-' . bin2hex(random_bytes(4)));
        $from = SHEPHERD_VERSION;
        $fromSha = self::installed()['sha'];
        $backup = '';
        try {
            if (!mkdir($work, 0750, true) && !is_dir($work)) {
                throw new \RuntimeException("Cannot create $work");
            }
            $archive = "$work/release.zip";
            HttpClient::download($zipUrl, $archive, self::MAX_ARCHIVE, self::HOSTS, self::githubHeaders());
            [$zip, $files] = self::openRelease($archive, $version);
            $backup = self::backup();
            try {
                self::writeFiles($zip, $files);
            } catch (\Throwable $e) {
                self::restore($backup);
                throw new \RuntimeException('Installing failed, so the previous files were put back: ' . $e->getMessage(), 0, $e);
            } finally {
                $zip->close();
            }
        } finally {
            self::removeTree($work);
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::recordInstalled($sha, (string) $info['branch'], $version);
        self::resetOpcache();
        self::pruneBackups();
        $result = ['action' => 'installed', 'from' => $from, 'from_sha' => $fromSha, 'to' => $version, 'sha' => $sha,
            'channel' => $info['channel'], 'branch' => $info['branch'], 'backup' => basename($backup),
            'at' => gmdate('c'), 'by' => $by?->name ?? 'cron'];
        @file_put_contents(Config::storage(self::LAST), json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX);
        Audit::log($by, 'server.updated', null, null, $result);
        return $result;
    }

    /** Puts back the files from a backup made before an earlier update. */
    public static function rollback(string $name, ?Principal $by): array
    {
        if (!preg_match('/^shepherd-[0-9A-Za-z.+\-]+-\d{8}-\d{6}\.zip$/', $name) || !is_file(Config::storage("backups/$name"))) {
            throw HttpError::notFound('Backup');
        }
        $problems = array_values(array_filter(self::problems(), static fn ($p) => !str_contains($p, 'self_update.enabled')));
        if ($problems !== []) {
            throw HttpError::conflict(implode(' ', $problems));
        }
        @set_time_limit(300);
        ignore_user_abort(true);
        $lock = self::lock();
        $meta = self::backupMeta(Config::storage("backups/$name"));
        try {
            $safety = self::backup(); // so the rollback can itself be undone
            self::restore(Config::storage("backups/$name"));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        preg_match('/^shepherd-(.+)-\d{8}-\d{6}\.zip$/', $name, $m);
        $to = $m[1] ?? '?';
        self::recordInstalled(is_string($meta['sha'] ?? null) ? $meta['sha'] : null,
            is_string($meta['branch'] ?? null) ? $meta['branch'] : null, $to);
        self::resetOpcache();
        $result = ['action' => 'rolled_back', 'from' => SHEPHERD_VERSION, 'to' => $to, 'backup' => basename($safety),
            'at' => gmdate('c'), 'by' => $by?->name ?? 'system'];
        @file_put_contents(Config::storage(self::LAST), json_encode($result, JSON_UNESCAPED_SLASHES), LOCK_EX);
        Audit::log($by, 'server.rolled_back', null, null, $result + ['restored' => $name]);
        return $result;
    }

    /** For bin/cron.php: refresh the check, and install by itself when 'auto' is on. */
    public static function cron(): string
    {
        $info = self::check();
        if (!empty($info['error'])) {
            return 'update check failed: ' . $info['error'];
        }
        if (empty($info['available'])) {
            return 'up to date with ' . ($info['branch'] ?? self::branch());
        }
        $what = ($info['latest'] ?? '?') . ' (' . substr((string) ($info['sha'] ?? ''), 0, 7) . ' on ' . ($info['branch'] ?? '?') . ')';
        if (!self::auto()) {
            return "update $what available";
        }
        if (!empty($info['downgrade'])) {
            return "$what is older than the installed " . SHEPHERD_VERSION . '; not installed automatically';
        }
        if (self::problems() !== []) {
            return "update $what available, can't auto-install: " . implode(' ', self::problems());
        }
        $r = self::install((string) $info['sha'], null);
        return "installed {$r['to']} (" . substr($r['sha'], 0, 7) . ')';
    }

    /** @return resource */
    private static function lock()
    {
        $fh = fopen(Config::storage('self-update.lock'), 'c');
        if ($fh === false || !flock($fh, LOCK_EX | LOCK_NB)) {
            throw HttpError::conflict('An update is already running.');
        }
        return $fh;
    }

    /**
     * Opens the downloaded archive and lists what it would write.
     *
     * @return array{0: \ZipArchive, 1: array<string, int>} the archive and relative path => entry index
     */
    private static function openRelease(string $archive, string $version): array
    {
        $zip = new \ZipArchive();
        if ($zip->open($archive) !== true) {
            throw new \RuntimeException('The download is not a valid zip archive.');
        }
        // GitHub archives put everything in one top folder, e.g. owner-repo-abc1234/
        $first = (string) $zip->getNameIndex(0);
        $prefix = str_contains($first, '/') ? substr($first, 0, strpos($first, '/') + 1) : '';
        $files = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = (string) $zip->getNameIndex($i);
            if (str_ends_with($name, '/')) {
                continue;
            }
            if ($prefix === '' || !str_starts_with($name, $prefix)) {
                throw new \RuntimeException("Unexpected file in the archive: $name");
            }
            $rel = substr($name, strlen($prefix));
            // No absolute paths, parent references, backslashes or control characters
            if ($rel === '' || $rel[0] === '/' || str_contains($rel, '\\') || preg_match('#(^|/)\.\.(/|$)|[\x00-\x1F]#', $rel)) {
                throw new \RuntimeException("Unsafe path in the archive: $name");
            }
            if (self::managed($rel)) {
                $files[$rel] = $i;
            }
        }
        foreach (self::REQUIRED as $req) {
            if (!isset($files[$req])) {
                throw new \RuntimeException("The archive has no $req, so it isn't Kiosky Shepherd.");
            }
        }
        $boot = (string) $zip->getFromIndex($files['src/bootstrap.php']);
        if (!preg_match("/define\('SHEPHERD_VERSION', '([^']+)'\)/", $boot, $m) || $m[1] !== $version) {
            throw new \RuntimeException('The archive says it is version ' . ($m[1] ?? 'unknown') . ", not $version.");
        }
        return [$zip, $files];
    }

    /** @param array<string, int> $files */
    private static function writeFiles(\ZipArchive $zip, array $files): void
    {
        // The entry points last, so requests during the copy keep running the old version
        $last = ['src/bootstrap.php', 'index.php'];
        uksort($files, static fn ($a, $b) => (int) in_array($a, $last, true) <=> (int) in_array($b, $last, true));
        foreach ($files as $rel => $i) {
            $data = $zip->getFromIndex($i);
            if ($data === false) {
                throw new \RuntimeException("Cannot read $rel from the archive.");
            }
            self::writeFile($rel, $data);
        }
    }

    private static function writeFile(string $rel, string $data): void
    {
        $target = SHEPHERD_ROOT . '/' . $rel;
        $dir = dirname($target);
        if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $tmp = $target . '.update-' . bin2hex(random_bytes(3));
        if (file_put_contents($tmp, $data) !== strlen($data) || !rename($tmp, $target)) {
            @unlink($tmp);
            throw new \RuntimeException("Cannot write $rel");
        }
    }

    /** Zips every project file an update may replace. Returns the backup's path. */
    private static function backup(): string
    {
        $dir = Config::storage('backups');
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new \RuntimeException("Cannot create $dir");
        }
        $path = sprintf('%s/shepherd-%s-%s.zip', $dir, preg_replace('/[^0-9A-Za-z.+\-]/', '', SHEPHERD_VERSION), gmdate('Ymd-His'));
        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException("Cannot create $path");
        }
        foreach (self::currentFiles() as $rel) {
            $zip->addFile(SHEPHERD_ROOT . '/' . $rel, $rel);
        }
        // Remember which build this was, so a rollback knows the commit again
        $zip->setArchiveComment((string) json_encode(self::installed(), JSON_UNESCAPED_SLASHES));
        if (!$zip->close()) {
            throw new \RuntimeException('Writing the backup failed (is the disk full?).');
        }
        return $path;
    }

    private static function restore(string $backup): void
    {
        $zip = new \ZipArchive();
        if ($zip->open($backup) !== true) {
            throw new \RuntimeException('Cannot open the backup ' . basename($backup));
        }
        try {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $rel = (string) $zip->getNameIndex($i);
                if ($rel === '' || str_ends_with($rel, '/') || !self::managed($rel) || preg_match('#(^|/)\.\.(/|$)#', $rel) || $rel[0] === '/') {
                    continue;
                }
                self::writeFile($rel, (string) $zip->getFromIndex($i));
            }
        } finally {
            $zip->close();
        }
    }

    private static function pruneBackups(): void
    {
        foreach (array_slice(self::backups(), self::KEEP_BACKUPS) as $b) {
            @unlink(Config::storage('backups/' . $b['name']));
        }
    }

    private static function resetOpcache(): void
    {
        if (function_exists('opcache_reset')) {
            @opcache_reset();
        }
        clearstatcache(true);
    }

    private static function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $it = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($it as $f) {
            $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
        }
        @rmdir($dir);
    }
}
