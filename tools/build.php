<?php

declare(strict_types = 1);

/**
* Builds the StonePerms plugin.
*
*     php -d phar.readonly=0 tools/build.php
*
* Produces two things in build/:
*
*   StonePerms_v<version>.phar  drop straight into a server's plugins folder
*   StonePerms_v<version>.zip   extract into plugins/ for a folder plugin,
*                               or read as the source of that release
*
* Phar writing is disabled by default in PHP, which is why the ini override is
* required.
*/

const SOURCE_DIRECTORIES = ['src', 'resources'];
const SOURCE_FILES = ['plugin.yml', 'LICENSE', 'README.md', 'CHANGELOG.md', 'CREDITS.md'];

$root = dirname(__DIR__);
$buildDirectory = $root . '/build';

if (ini_get('phar.readonly') === '1') {
  fwrite(STDERR, "Phar writing is disabled.\nRun: php -d phar.readonly=0 tools/build.php\n");
  exit(1);
}

if (!class_exists(ZipArchive::class)) {
  fwrite(STDERR, "The zip extension is required to build the zip archive.\n");
  exit(1);
}

$manifest = readManifest($root . '/plugin.yml');
foreach (['name', 'version', 'main', 'api'] as $key) {
  if (!isset($manifest[$key]) || (string) $manifest[$key] === '') {
    fwrite(STDERR, "plugin.yml is missing '$key'.\n");
    exit(1);
  }
}

// The manifest and the class constant are both quoted in release notes and in
// the dashboard, so a mismatch is a release bug, not a detail.
$declared = declaredVersion($root . '/src/stoneperms/Version.php');
if ($declared !== null && $declared !== (string) $manifest['version']) {
  fwrite(STDERR, "Version mismatch: plugin.yml says {$manifest['version']}, Version.php says $declared.\n");
  exit(1);
}

$name = (string) $manifest['name'];
$version = (string) $manifest['version'];

if (!is_dir($buildDirectory) && !mkdir($buildDirectory, 0777, true)) {
  fwrite(STDERR, "Could not create $buildDirectory.\n");
  exit(1);
}

$contents = collectFiles($root);
if ($contents === []) {
  fwrite(STDERR, "Nothing to package.\n");
  exit(1);
}

$output = "$buildDirectory/$name" . '_v' . $version . '.phar';
if (is_file($output)) {
  unlink($output);
}

$phar = new Phar($output);
$phar->setSignatureAlgorithm(Phar::SHA256);
$phar->setMetadata([
  'name' => $name,
  'version' => $version,
  'main' => (string) $manifest['main'],
  'api' => $manifest['api'],
  'creationDate' => time()
]);
$phar->setStub(
  '<?php echo "' . $name . ' ' . $version
  . ' is a PocketMine-MP plugin. Put this file in your server\'s plugins folder.\n";'
  . ' __HALT_COMPILER();'
);

$phar->startBuffering();
foreach ($contents as $relative => $absolute) {
  $phar->addFile($absolute, $relative);
}
$phar->stopBuffering();

// The zip carries the plugin inside a folder named after it, so extracting it
// into plugins/ produces a folder plugin rather than loose files.
$archive = "$buildDirectory/$name" . '_v' . $version . '.zip';
if (is_file($archive)) {
  unlink($archive);
}
$zip = new ZipArchive();
if ($zip->open($archive, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
  fwrite(STDERR, "Could not create $archive.\n");
  exit(1);
}
foreach ($contents as $relative => $absolute) {
  $zip->addFile($absolute, "$name/$relative");
}
$zip->close();

printf("Built %s\n", $output);
printf("  %d files, %s\n", count($contents), formatBytes((int) filesize($output)));
printf("Built %s\n", $archive);
printf("  %d files, %s\n", count($contents), formatBytes((int) filesize($archive)));

/**
* Everything that belongs in a build, as relative path => absolute path.
*
* Repository art and tooling are deliberately absent: a server does not need
* them and they would only make the download bigger.
*
* @return array<string, string>
*/
function collectFiles(string $root): array {
  $contents = [];

  foreach (SOURCE_FILES as $file) {
    if (is_file("$root/$file")) {
      $contents[$file] = "$root/$file";
    }
  }

  foreach (SOURCE_DIRECTORIES as $directory) {
    $base = "$root/$directory";
    if (!is_dir($base)) {
      continue;
    }
    $files = new RecursiveIteratorIterator(
      new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($files as $file) {
      if (!$file->isFile()) {
        continue;
      }
      $relative = $directory . '/' . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
      $contents[$relative] = $file->getPathname();
    }
  }

  ksort($contents);
  return $contents;
}

/**
* Reads the top-level scalars from plugin.yml.
*
* ext-yaml is used when it is present. The fallback only has to understand this
* one file, which is flat, so a line reader is enough and keeps the build
* runnable on a plain PHP install.
*
* @return array<string, mixed>
*/
function readManifest(string $path): array {
  if (!is_file($path)) {
    fwrite(STDERR, "plugin.yml not found at $path.\n");
    exit(1);
  }
  if (function_exists('yaml_parse_file')) {
    $parsed = yaml_parse_file($path);
    if (is_array($parsed)) {
      return $parsed;
    }
  }

  $manifest = [];
  foreach (file($path, FILE_IGNORE_NEW_LINES) as $line) {
    if (preg_match('/^([a-z0-9_-]+):\s*(\S.*)$/i', $line, $matches) === 1) {
      $manifest[$matches[1]] = trim($matches[2], " \"'");
    }
  }
  return $manifest;
}

function declaredVersion(string $path): ?string {
  if (!is_file($path)) {
    return null;
  }
  $source = file_get_contents($path);
  return preg_match("/const VERSION = '([^']+)'/", $source, $matches) === 1 ? $matches[1] : null;
}

function formatBytes(int $bytes): string {
  return $bytes >= 1048576
    ? sprintf('%.2f MiB', $bytes / 1048576)
    : sprintf('%.1f KiB', $bytes / 1024);
}
