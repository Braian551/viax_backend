<?php
/**
 * Detecta marcadores de codigo legacy/deprecado sin bloquear despliegue.
 *
 * Uso:
 *   php backend/scripts/check_legacy_code.php
 */

const LEGACY_MARKERS = [
    'TODO_REMOVE',
    'LEGACY_ENDPOINT',
    'DEPRECATED_API',
    'OLD_DISPATCH',
];

function scanLegacyMarkers(string $baseDir): array
{
    $results = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($baseDir, FilesystemIterator::SKIP_DOTS)
    );

    foreach ($iterator as $fileInfo) {
        if (!$fileInfo->isFile()) {
            continue;
        }

        $path = $fileInfo->getPathname();
        if (basename($path) === 'check_legacy_code.php') {
            continue;
        }
        if (strpos($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) !== false) {
            continue;
        }

        $relativePath = ltrim(str_replace($baseDir, '', $path), DIRECTORY_SEPARATOR);
        $contents = @file($path);
        if ($contents === false) {
            continue;
        }

        foreach ($contents as $lineNumber => $line) {
            foreach (LEGACY_MARKERS as $marker) {
                if (strpos($line, $marker) !== false) {
                    $results[] = sprintf('%s:%d %s', $relativePath, $lineNumber + 1, $marker);
                }
            }
        }
    }

    return $results;
}

function runLegacyCheck(): int
{
    $baseDir = realpath(__DIR__ . '/..');
    if ($baseDir === false) {
        echo "LEGACY SCAN ERROR: backend directory not found\n";
        return 0;
    }

    $matches = scanLegacyMarkers($baseDir);

    if (!empty($matches)) {
        echo "LEGACY CODE DETECTED\n";
        foreach ($matches as $match) {
            echo $match . "\n";
        }
        return 0;
    }

    echo "NO_LEGACY_CODE_MARKERS\n";
    return 0;
}

if (php_sapi_name() === 'cli') {
    exit(runLegacyCheck());
}
