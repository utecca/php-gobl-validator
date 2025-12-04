<?php

declare(strict_types=1);

use Ecourier\GoblValidator\GoblValidator;

require __DIR__ . '/vendor/autoload.php';

$version = GoblValidator::$GOBL_VERSION;
$repo = "invopop/gobl";
$zipUrl = "https://github.com/$repo/archive/refs/tags/v$version.zip";
$tmpZip  = __DIR__ . "/.gobl.zip";
$tmpDir  = __DIR__ . "/.gobl-sync";
$dstDir  = __DIR__ . "/schemas";

echo "Using GOBL version: $version\n";
echo "Downloading: $zipUrl ...\n";

// ------------------------------------------------------------
// 1. Download the ZIP archive
// ------------------------------------------------------------
$data = @file_get_contents($zipUrl);
if ($data === false) {
    fwrite(STDERR, "Error: Could not download $zipUrl\n");
    exit(1);
}
file_put_contents($tmpZip, $data);

// ------------------------------------------------------------
// 2. Extract ZIP
// ------------------------------------------------------------
$zip = new ZipArchive();
if ($zip->open($tmpZip) !== true) {
    fwrite(STDERR, "Cannot open downloaded ZIP file\n");
    exit(1);
}

$zip->extractTo($tmpDir);
$zip->close();

$goblRoot = glob("$tmpDir/gobl-$version")[0] ?? null;
$schemasPath = "$goblRoot/data/schemas";

if (!$goblRoot) {
    fwrite(STDERR, "Could not locate extracted folder gobl-$version\n");
    exit(1);
}

if (!is_dir($schemasPath)) {
    fwrite(STDERR, "Schema directory not found: $schemasPath\n");
    exit(1);
}

// ------------------------------------------------------------
// 3. Replace local schemas/
// ------------------------------------------------------------
if (is_dir($dstDir)) {
    echo "Clearing existing schemas directory...\n";
    deleteDirectory($dstDir);
}
mkdir($dstDir, 0777, true);

echo "Copying schemas...\n";
recurseCopy($schemasPath, $dstDir);

echo "Adding additionalProperties: false to all object definitions...\n";
addAdditionalPropertiesFalse($dstDir);

deleteDirectory($tmpDir);
unlink($tmpZip);

echo "Schemas updated successfully for GOBL version $version\n";

// ------------------------------------------------------------
// Helper functions
// ------------------------------------------------------------

function deleteDirectory(string $dir): void
{
    if (!is_dir($dir)) return;

    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;

        $path = "$dir/$item";
        if (is_dir($path)) {
            deleteDirectory($path);
        } else {
            unlink($path);
        }
    }
    rmdir($dir);
}

function recurseCopy(string $src, string $dst): void
{
    @mkdir($dst, 0777, true);

    foreach (scandir($src) as $item) {
        if ($item === '.' || $item === '..') continue;

        $srcPath = "$src/$item";
        $dstPath = "$dst/$item";

        if (is_dir($srcPath)) {
            recurseCopy($srcPath, $dstPath);
        } else {
            copy($srcPath, $dstPath);
        }
    }
}

/**
 * Recursively process all JSON schema files in a directory,
 * adding "additionalProperties": false to all object definitions
 * that have "properties" defined.
 */
function addAdditionalPropertiesFalse(string $dir): void
{
    foreach (scandir($dir) as $item) {
        if ($item === '.' || $item === '..') continue;

        $path = "$dir/$item";

        if (is_dir($path)) {
            addAdditionalPropertiesFalse($path);
        } elseif (str_ends_with($item, '.json')) {
            $content = file_get_contents($path);
            $schema = json_decode($content, true);

            if ($schema !== null) {
                $modified = processSchemaNode($schema);
                file_put_contents($path, json_encode($modified, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
            }
        }
    }
}

/**
 * Recursively process a schema node, adding additionalProperties: false
 * to any object that has "type": "object" and "properties" defined.
 */
function processSchemaNode(array $node): array
{
    // If this node is an object type with properties, add additionalProperties: false
    if (
        isset($node['type']) &&
        $node['type'] === 'object' &&
        isset($node['properties']) &&
        !isset($node['additionalProperties'])
    ) {
        // Allow $schema property (used by GOBL to identify the schema type)
        if (!isset($node['properties']['$schema'])) {
            $node['properties']['$schema'] = [
                'type' => 'string',
                'format' => 'uri',
                'title' => 'Schema',
                'description' => 'Schema identifier for the GOBL document type.',
            ];
        }

        $node['additionalProperties'] = false;
    }

    // Recursively process all nested structures
    foreach ($node as $key => $value) {
        if (is_array($value)) {
            $node[$key] = processSchemaNode($value);
        }
    }

    return $node;
}