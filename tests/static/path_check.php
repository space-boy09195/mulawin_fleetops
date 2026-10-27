<?php
// ============================================================
// tests/static/path_check.php
// STATIC path check: no database, no HTTP, writes nothing. Guards the
// restructuring of the repository by checking that every path the code
// relies on still exists:
//   1. require/include of a literal __DIR__ . '...' path resolves to a file
//      (relative to the including file's folder).
//   2. every literal application path '/pages|ajax|auth|assets/<name>.<ext>'
//      written in PHP or JavaScript (URLs built with APP_BASE, BASE,
//      window.APP_BASE, nav entries, redirects, test fixtures) is a file
//      under the repository root. A query string is ignored.
//   3. login.php's relative links (href/src="assets/...") resolve from
//      login.php's folder.
//   4. the root .htaccess deny rule covers every internal folder that
//      exists (code, config, database files, scripts, tests, docs).
//
// Not verified: paths built from variables (e.g. '/pages/' . $page), URLs
// typed by users, and whether the web server honours .htaccess.
//
// Run from the repository root:  php tests/static/path_check.php
// Exit code 0 = no broken path found.
// ============================================================

$root = dirname(__DIR__, 2);
// Paths that may be absent by design. assets/images/logo.png is the
// customer's own logo: assets/images/README.md says to drop it in, and the
// pages fall back to a truck icon until it exists.
const PATH_CHECK_OPTIONAL = ['assets/images/logo.png'];
$scanDirs = ['pages', 'ajax', 'auth', 'includes', 'config', 'scripts', 'analytics', 'app', 'resources', 'assets/js', 'tests'];
$scanFiles = ['login.php'];
$failures = [];
$counts = ['includes' => 0, 'app paths' => 0, 'relative links' => 0, 'skipped dynamic includes' => 0];

$files = [];
foreach ($scanDirs as $dir) {
    if (!is_dir($root . '/' . $dir)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $dir, FilesystemIterator::SKIP_DOTS));
    foreach ($iterator as $file) {
        if (preg_match('/\.(php|js)$/', $file->getFilename()) && !str_contains($file->getPathname(), DIRECTORY_SEPARATOR . '.captured' . DIRECTORY_SEPARATOR)) {
            $files[] = $file->getPathname();
        }
    }
}
foreach ($scanFiles as $file) {
    $files[] = $root . '/' . $file;
}

$relative = static fn(string $path): string => ltrim(str_replace([$root, '\\'], ['', '/'], $path), '/');

foreach ($files as $file) {
    $source = (string)file_get_contents($file);
    $lines = preg_split('/\R/', $source);
    $isPhp = str_ends_with($file, '.php');
    // Test files quote include lines inside assertions; only application
    // code is checked for includes (tests are still checked for paths).
    $isTest = str_starts_with($relative($file), 'tests/');
    foreach ($lines as $index => $line) {
        $where = $relative($file) . ':' . ($index + 1);

        // 1. Literal __DIR__ includes.
        if ($isPhp && !$isTest && preg_match_all('/\b(?:require|include)(?:_once)?\s*\(?\s*__DIR__\s*\.\s*([\'"])([^\'"]+)\1\s*(\)?\s*;|\.)?/', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                if (($match[3] ?? '') === '.') {
                    $counts['skipped dynamic includes']++;
                    continue;
                }
                $counts['includes']++;
                $target = dirname($file) . $match[2];
                if (!is_file($target)) {
                    $failures[] = "$where  include target missing: __DIR__ . '{$match[2]}'";
                }
            }
        }

        // 2. Literal application paths.
        if (preg_match_all('#/(pages|ajax|auth|assets)/([A-Za-z0-9_./-]+\.(?:php|js|css|png|jpg|jpeg|svg|ico|webp|woff2?))#', $line, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $match) {
                $counts['app paths']++;
                if (!is_file($root . '/' . $match[1] . '/' . $match[2])
                    && !in_array($match[1] . '/' . $match[2], PATH_CHECK_OPTIONAL, true)) {
                    $failures[] = "$where  application path missing: /{$match[1]}/{$match[2]}";
                }
            }
        }

        // 3. Relative links from login.php (served from the repository root).
        if ($file === $root . '/login.php' && preg_match_all('/(?:href|src)="((?:assets|pages|ajax|auth)\/[^"?#]+)"/', $line, $matches)) {
            foreach ($matches[1] as $link) {
                $counts['relative links']++;
                if (!is_file($root . '/' . $link) && !in_array($link, PATH_CHECK_OPTIONAL, true)) {
                    $failures[] = "$where  relative link missing: $link";
                }
            }
        }
    }
}

// 4. .htaccess deny rule covers the internal folders that exist.
$htaccess = (string)file_get_contents($root . '/.htaccess');
$denied = [];
if (preg_match('/RewriteRule \^\(\?:([^)]+)\)/', $htaccess, $match)) {
    $denied = array_map(static fn(string $name): string => str_replace('\\', '', $name), explode('|', $match[1]));
}
$internal = ['includes', 'config', 'db', 'scripts', 'tests', 'docs', 'analytics', 'instructions', 'app', 'resources'];
$protected = 0;
foreach ($internal as $folder) {
    if (!is_dir($root . '/' . $folder)) {
        continue;
    }
    $protected++;
    if (!in_array($folder, $denied, true)) {
        $failures[] = ".htaccess  internal folder not denied: $folder/";
    }
}
if (!str_contains($htaccess, '<FilesMatch "^\.env')) {
    $failures[] = '.htaccess  .env deny rule missing';
}

printf(
    "PATH CHECK (STATIC, no database): %d files scanned; %d includes, %d application paths, %d relative links checked; %d dynamic includes skipped; %d internal folders checked against .htaccess\n",
    count($files), $counts['includes'], $counts['app paths'], $counts['relative links'], $counts['skipped dynamic includes'], $protected
);
foreach ($failures as $failure) {
    echo "FAIL $failure\n";
}
echo $failures ? 'PATH CHECK: FAIL (' . count($failures) . ")\n" : "PATH CHECK: PASS\n";
exit($failures ? 1 : 0);
