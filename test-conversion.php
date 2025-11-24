<?php
// Standalone test script to debug ImageMagick CLI conversion
// Run this from the command line: php test-conversion.php

echo "--- Starting Standalone Conversion Test ---\n";

// Configuration
$binary = '/usr/local/bin/magick';
$source = '/home/daviddegner.com/public_html/wp-content/uploads/2025/11/IMG_4203.jpeg';
$target = '/home/daviddegner.com/public_html/wp-content/uploads/2025/11/IMG_4203-test-standalone.avif';

// 1. Check Binary
echo "1. Checking Binary: $binary\n";
if (file_exists($binary) && is_executable($binary)) {
    echo "   [OK] Binary exists and is executable.\n";
    $verCmd = escapeshellarg($binary) . ' -version';
    $verOut = shell_exec($verCmd);
    echo "   Version: " . explode("\n", trim($verOut))[0] . "\n";
} else {
    echo "   [FAIL] Binary not found or not executable.\n";
    exit(1);
}

// 2. Check Source
echo "\n2. Checking Source File: $source\n";
if (file_exists($source)) {
    $size = filesize($source);
    echo "   [OK] Source exists. Size: " . number_format($size) . " bytes.\n";
} else {
    echo "   [FAIL] Source file not found.\n";
    exit(1);
}

// 3. Check Target Directory
$dir = dirname($target);
echo "\n3. Checking Target Directory: $dir\n";
if (is_writable($dir)) {
    $free = disk_free_space($dir);
    echo "   [OK] Directory is writable. Free space: " . number_format($free / 1024 / 1024, 2) . " MB.\n";
} else {
    echo "   [FAIL] Directory is not writable.\n";
    exit(1);
}

// 4. Construct Command (Mirroring plugin logic)
// cmd: '/usr/local/bin/magick' 'jpeg:...' '-auto-orient' '-strip' '-quality' '80' '-define' 'avif:speed=1' ...
$cmdParts = [
    escapeshellarg($binary),
    escapeshellarg('jpeg:' . $source),
    '-auto-orient',
    '-strip',
    '-quality', '80',
    '-define', 'avif:speed=1',
    '-define', 'avif:chroma-subsample=4:2:0',
    '-depth', '8',
    '-define', 'avif:bit-depth=8',
    '-colorspace', 'sRGB',
    escapeshellarg('avif:' . $target)
];

$command = implode(' ', $cmdParts) . ' 2>&1';

echo "\n4. Executing Command:\n";
echo "   $command\n";

$output = [];
$code = 0;
$start = microtime(true);
exec($command, $output, $code);
$end = microtime(true);
$duration = round(($end - $start) * 1000, 2);

echo "\n5. Execution Results:\n";
echo "   Exit Code: $code\n";
echo "   Duration: {$duration} ms\n";
echo "   Output:\n";
if (empty($output)) {
    echo "   (No output)\n";
} else {
    foreach ($output as $line) {
        echo "   > $line\n";
    }
}

// 6. Validate Result
echo "\n6. Validating Target File: $target\n";
if (file_exists($target)) {
    $size = filesize($target);
    echo "   File Size: $size bytes\n";
    if ($size > 512) {
        echo "   [SUCCESS] File created and looks valid.\n";
    } else {
        echo "   [FAIL] File created but too small (<= 512 bytes).\n";
    }
} else {
    echo "   [FAIL] Target file was NOT created.\n";
}

echo "\n--- End Test ---\n";





