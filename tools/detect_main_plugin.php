<?php
// Usage: php tools/detect_main_plugin.php /path/to/plugin
$dir = $argv[1] ?? '.';
$dir = rtrim($dir, "/\\");
$rii = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
$candidates = [];
foreach ($rii as $file) {
  if ($file->isDir()) continue;
  if (substr($file->getFilename(), -4) !== '.php') continue;
  $path = $file->getPathname();
  $contents = @file_get_contents($path);
  if (!$contents) continue;
  if (preg_match('/^\s*Plugin Name:\s*(.+)\s*$/mi', $contents)) {
    $candidates[] = $path;
  }
}
echo $candidates[0] ?? '';
