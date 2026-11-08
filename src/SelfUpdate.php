<?php

declare(strict_types=1);

namespace Shepherd;

use Shepherd\Auth\Principal;
use Shepherd\Http\HttpError;

/**
 * Updates Kiosky Shepherd itself from its GitHub repository, from the console (or by
 * cron when 'self_update' => ['auto' => true]).
 *
 * Install: download the release archive from GitHub over HTTPS, check every path in
 * it and that it really is the version it claims, zip the current files into
 * storage/backups, then write the new files one by one (each to a temporary file,
 * then renamed into place). config/config.php, storage/ and .user.ini are never
 * touched. If writing fails part-way, the backup is restored. Database updates in
 * the new version apply on the next request (Migrator::autoRun).
 */
final class SelfUpdate
{
    private const CACHE = 'cache/self-update.json';
    private const LAST = 'self-update-last.json';
    private const CHECK_EVERY = 6 * 3600;
    private const MAX_ARCHIVE = 40 * 1024 * 1024;
    private const KEEP_BACKUPS = 5;
    private const HOSTS = ['api.github.com', 'github.com', 'codeload.github.com', 'objects.githubusercontent.com'];

    /** Files an update never overwrites (relative to the project root). */
    private const KEEP = ['config/config.php', '.user.ini'];
    /** Paths an update never writes into. */
    private const SKIP_PREFIXES = ['storage/', '.git/', '.github/'];
    private const SKIP_FILES = ['.gitignore'];
    /** Present in every real release; checked before anything is written. */
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

    private static function prereleases(): bool
    {
        return (bool) Config::get('self_update.prereleases', false);
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

    public static function normalise(string $tag): string
    {
        return ltrim(trim($tag), 'vV');
    }

    // ---- Checking ---------------------------------------------------------------

    /** The last check's result, without contacting GitHub (for the session and banner). */
    public static function cached(): ?array
    {
        $data = json_decode((string) @file_get_contents(Config::storage(self::CACHE)), true);
        if (!is_array($data)) {
            return null;
        }
        $data['current'] = SHEPHERD_VERSION;
        $data['available'] = is_string($data['latest'] ?? null) && version_compare($data['latest'], SHEPHERD_VERSION, '>');
        return $data;
    }

    /**
     * Asks GitHub for the newest release (or, when the repository has no releases, the
     * newest version tag). Cached for 6 hours unless $force.
     */
    public static function check(bool $force = false): array
    {
        $cached = self::cached();
        if (!$force && $cached !== null && strtotime((string) ($cached['checked_at'] ?? '')) > time() - self::CHECK_EVERY) {
            return $cached;
        }
        $result = ['checked_at' => gmdate('c'), 'repo' => self::repo(), 'error' => null];
        try {
            $result += self::newestRelease() ?? self::newestTag()
                ?? throw new \RuntimeException('The repository has no releases or version tags yet.');
        } catch (\Throwable $e) {
            // Keep what we knew, so a GitHub hiccup doesn't hide an available update
            $result['error'] = $e->getMessage();
            foreach (['latest', 'tag', 'name', 'notes', 'url', 'published_at', 'zip', 'prerelease'] as $k) {
                if (isset($cached[$k])) {
                    $result[$k] = $cached[$k];
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

    private static function newestRelease(): ?array
    {
        $r = HttpClient::request('GET', 'https://api.github.com/repos/' . self::repo() . '/releases?per_page=15', self::githubHeaders());
        if ($r['status'] === 404) {
            throw new \RuntimeException('GitHub doesn\'t know the repository ' . self::repo() . ' (or it is private).');
        }
        if ($r['status'] === 403 || $r['status'] === 429) {
            throw new \RuntimeException('GitHub rate-limited this server. Try again later, or set self_update.github_token.');
        }
        $list = json_decode($r['body'], true);
        if ($r['status'] !== 200 || !is_array($list)) {
            throw new \RuntimeException("GitHub answered HTTP {$r['status']}.");
        }
        $best = null;
        foreach ($list as $rel) {
            if (!is_array($rel) || !empty($rel['draft']) || !is_string($rel['tag_name'] ?? null)) {
                continue;
            }
            $v = self::normalise($rel['tag_name']);
            if (!self::isVersion($v) || (!empty($rel['prerelease']) && !self::prereleases())) {
                continue;
            }
            if ($best === null || version_compare($v, $best['latest'], '>')) {
                $best = [
                    'latest' => $v,
                    'tag' => $rel['tag_name'],
                    'name' => is_string($rel['name'] ?? null) && $rel['name'] !== '' ? $rel['name'] : $rel['tag_name'],
                    'notes' => mb_substr((string) ($rel['body'] ?? ''), 0, 20000),
                    'url' => (string) ($rel['html_url'] ?? ''),
                    'published_at' => $rel['published_at'] ?? null,
                    'zip' => (string) ($rel['zipball_url'] ?? ''),
                    'prerelease' => !empty($rel['prerelease']),
                ];
            }
        }
        return $best;
    }

    private static function newestTag(): ?array
    {
        $tags = HttpClient::json('GET', 'https://api.github.com/repos/' . self::repo() . '/tags?per_page=50', self::githubHeaders());
        $best = null;
        foreach ($tags as $t) {
            $v = is_array($t) && is_string($t['name'] ?? null) ? self::normalise($t['name']) : '';
            if (!self::isVersion($v) || (str_contains($v, '-') && !self::prereleases())) {
                continue;
            }
            if ($best === null || version_compare($v, $best['latest'], '>')) {
                $best = [
                    'latest' => $v, 'tag' => $t['name'], 'name' => $t['name'], 'notes' => '',
                    'url' => 'https://github.com/' . self::repo() . '/releases/tag/' . rawurlencode($t['name']),
                    'published_at' => null, 'zip' => (string) ($t['zipball_url'] ?? ''), 'prerelease' => str_contains($v, '-'),
                ];
            }
        }
        return $best;
    }

    private static function isVersion(string $v): bool
    {
        return preg_match('/^\d+\.\d+\.\d+([\-+][0-9A-Za-z.\-]+)?$/', $v) === 1;
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
            if (str_starts_with($path, $storage)) {
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
            'repo' => self::repo(),
            'check' => self::cached(),
            'problems' => self::problems(),
            'auto' => self::auto(),
            'prereleases' => self::prereleases(),
            'last' => is_array($last) ? $last : null,
            'backups' => self::backups(),
        ];
    }

    /** @return list<array{name: string, version: string, size: int, created_at: string}> newest first */
    public static function backups(): array
    {
        $out = [];
        foreach (glob(Config::storage('backups') . '/shepherd-*.zip') ?: [] as $f) {
            if (preg_match('/^shepherd-(.+)-(\d{8}-\d{6})\.zip$/', basename($f), $m)) {
                $out[] = ['name' => basename($f), 'version' => $m[1], 'size' => (int) filesize($f),
                    'created_at' => gmdate('c', (int) filemtime($f))];
            }
        }
        usort($out, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));
        return $out;
    }

    // ---- Install and roll back ---------------------------------------------------

    /**
     * Installs the newest release found by the last check. $version must match it,
     * so a click on a stale page can't install something unexpected.
     */
    public static function install(string $version, ?Principal $by): array
    {
        $problems = self::problems();
        if ($problems !== []) {
            throw HttpError::conflict(implode(' ', $problems));
        }
        $info = self::check(true);
        if (!is_string($info['latest'] ?? null) || $info['latest'] !== $version) {
            throw HttpError::conflict('The newest release is ' . ($info['latest'] ?? 'unknown') . ", not $version. Reload the page.");
        }
        if (!version_compare($version, SHEPHERD_VERSION, '>')) {
            throw HttpError::conflict("Kiosky Shepherd $version is not newer than the installed " . SHEPHERD_VERSION . '.');
        }
        $zipUrl = (string) ($info['zip'] ?? '');
        if (!str_starts_with($zipUrl, 'https://api.github.com/repos/' . self::repo() . '/')
            && !str_starts_with($zipUrl, 'https://github.com/' . self::repo() . '/')) {
            throw HttpError::conflict('The release has no download from ' . self::repo() . '.');
        }

        @set_time_limit(600);
        ignore_user_abort(true);
        $lock = self::lock();
        $work = Config::storage('updates/' . preg_replace('/[^0-9A-Za-z.\-]/', '', $version) . '-' . bin2hex(random_bytes(4)));
        $from = SHEPHERD_VERSION;
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
        self::resetOpcache();
        self::pruneBackups();
        $result = ['action' => 'installed', 'from' => $from, 'to' => $version, 'backup' => basename($backup),
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
        try {
            $safety = self::backup(); // so the rollback can itself be undone
            self::restore(Config::storage("backups/$name"));
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
        self::resetOpcache();
        preg_match('/^shepherd-(.+)-\d{8}-\d{6}\.zip$/', $name, $m);
        $result = ['action' => 'rolled_back', 'from' => SHEPHERD_VERSION, 'to' => $m[1] ?? '?', 'backup' => basename($safety),
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
            return 'up to date';
        }
        if (!self::auto() || !empty($info['prerelease'])) {
            return "update {$info['latest']} available";
        }
        if (self::problems() !== []) {
            return "update {$info['latest']} available, can't auto-install: " . implode(' ', self::problems());
        }
        $r = self::install((string) $info['latest'], null);
        return "installed {$r['to']}";
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
                throw new \RuntimeException("The archive has no $req, so it isn't a Kiosky Shepherd release.");
            }
        }
        $boot = (string) $zip->getFromIndex($files['src/bootstrap.php']);
        if (!preg_match("/define\('SHEPHERD_VERSION', '([^']+)'\)/", $boot, $m) || $m[1] !== $version) {
            throw new \RuntimeException("The archive says it is version " . ($m[1] ?? 'unknown') . ", not $version.");
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
                if (str_ends_with($rel, '/') || !self::managed($rel) || preg_match('#(^|/)\.\.(/|$)#', $rel) || $rel[0] === '/') {
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
