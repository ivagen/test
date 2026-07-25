<?php

declare(strict_types=1);

/**
 * Parses configuration files and reports the result honestly.
 *
 * Usage: php validate-config.php <file> [<file> ...]
 * Exit:  0 = every file parsed, 1 = at least one failed, 2 = the validator itself is unusable
 *
 * WHY THIS REPLACED THE PREVIOUS CHECK
 *
 * The old validator asked python3 to import PyYAML and, when that import failed, printed
 * "skip (no YAML parser available)" for every YAML file -- and then still reported
 * "All configuration files parse" and exited 0. Nothing was checked, and the result looked
 * identical to success.
 *
 * Two rules follow from that, and they are the reason this file exists:
 *
 *   1. There is NO skip path. A file that cannot be read or parsed is a failure.
 *   2. A missing parser is a hard error (exit 2), never a silent pass.
 *
 * symfony/yaml is a locked development dependency of the application, so running this
 * through the `app` container makes the check deterministic on every machine and in CI.
 */

const EXIT_OK = 0;
const EXIT_INVALID = 1;
const EXIT_UNUSABLE = 2;

foreach (['/var/www/vendor/autoload.php', __DIR__ . '/../www/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;

        break;
    }
}

if (!class_exists(Symfony\Component\Yaml\Yaml::class)) {
    fwrite(STDERR, "validate-config: symfony/yaml is not available, so YAML cannot be validated.\n");
    fwrite(STDERR, "                 Run this through the app container: docker compose run --rm app ...\n");

    exit(EXIT_UNUSABLE);
}

$files = \array_slice($argv, 1);

if ($files === []) {
    fwrite(STDERR, "usage: php validate-config.php <file> [<file> ...]\n");

    exit(EXIT_UNUSABLE);
}

$failures = 0;
$checked = 0;

foreach ($files as $file) {
    $label = $file;

    if (!is_file($file)) {
        printf("  INVALID %s: the file does not exist\n", $label);
        $failures++;

        continue;
    }

    if (!is_readable($file)) {
        printf("  INVALID %s: the file is not readable\n", $label);
        $failures++;

        continue;
    }

    $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));

    try {
        match ($extension) {
            'json' => validateJson($file),
            'yaml', 'yml' => validateYaml($file),
            default => throw new RuntimeException('unsupported file type ".' . $extension . '"'),
        };

        printf("  ok      %s\n", $label);
        $checked++;
    } catch (Throwable $error) {
        // Only the parser's own message is printed: it names the line and column, which is
        // what someone fixing the file needs, and nothing else.
        printf("  INVALID %s: %s\n", $label, trim($error->getMessage()));
        $failures++;
    }
}

echo "\n";

if ($failures === 0) {
    printf("All %d configuration file(s) parsed.\n", $checked);

    exit(EXIT_OK);
}

printf("%d of %d configuration file(s) failed to parse.\n", $failures, $checked + $failures);

exit(EXIT_INVALID);

function validateJson(string $file): void
{
    $contents = file_get_contents($file);

    if ($contents === false) {
        throw new RuntimeException('the file could not be read');
    }

    json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
}

function validateYaml(string $file): void
{
    // PARSE_EXCEPTION_ON_INVALID_TYPE turns a silently-ignored bad value into an error,
    // which is the behaviour a validator is supposed to have.
    Symfony\Component\Yaml\Yaml::parseFile($file, Symfony\Component\Yaml\Yaml::PARSE_EXCEPTION_ON_INVALID_TYPE);
}
