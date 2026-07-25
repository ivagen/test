<?php

declare(strict_types=1);

/**
 * Evidence-based scan for the components the constitution forbids.
 *
 * Usage: php scan-forbidden.php <root>
 * Exit:  0 = clean, 1 = findings, 2 = usage error
 *
 * WHY THIS IS NOT A grep
 *
 * The previous scanner matched regular expressions against whole files, so the sentence
 * "replaces PHPDaemon" in a comment counted as PHPDaemon being installed. That is not a
 * cosmetic problem: a scanner that cries wolf gets ignored, and one that is then narrowed
 * by excluding the files it keeps tripping over stops checking the very places a real
 * regression would appear.
 *
 * So this reads each file as what it actually is, and looks only at the parts that can
 * cause a component to exist or execute:
 *
 *   composer.json / composer.lock   installed package names, stability, PHP constraint
 *   package.json / package-lock     declared dependency names
 *   Dockerfile                      FROM images and RUN/CMD/ENTRYPOINT payloads, comments stripped
 *   compose.yaml                    parsed structure: image, command, entrypoint, links, ...
 *   *.sh / Makefile                 executable command lines, comments stripped
 *   *.php                           tokenised, comments discarded, then identifiers and strings
 *   *.ts / *.js                     import and require specifiers only
 *   generated bundles               the shipped bytes
 *   *.conf                          directives, comments stripped
 *
 * A prose comment is therefore invisible to it, while a real dependency, base image,
 * command, import or class reference is not.
 */

const EXIT_CLEAN = 0;
const EXIT_FINDINGS = 1;
const EXIT_USAGE = 2;

// symfony/yaml is needed to read Compose files structurally. It is a locked development
// dependency of the application, so it is present wherever this script is meant to run.
foreach (['/var/www/vendor/autoload.php', __DIR__ . '/../www/vendor/autoload.php'] as $autoload) {
    if (is_file($autoload)) {
        require_once $autoload;

        break;
    }
}

/** Directories that never contain first-party evidence. */
const SKIP_DIRECTORIES = [
    '.git', 'vendor', 'node_modules', 'coverage', 'test-results',
    'playwright-report', 'blob-report', 'runtime', '.phpunit.cache', '.vite',
];

$root = $argv[1] ?? null;

if ($root === null || !is_dir($root)) {
    fwrite(STDERR, "usage: php scan-forbidden.php <root>\n");
    exit(EXIT_USAGE);
}

$root = realpath($root);

if ($root === false) {
    fwrite(STDERR, "the root directory could not be resolved\n");
    exit(EXIT_USAGE);
}

$scanner = new ForbiddenComponentScanner($root);
exit($scanner->run());

final class ForbiddenComponentScanner
{
    /** @var list<string> */
    private array $findings = [];

    public function __construct(private readonly string $root)
    {
    }

    public function run(): int
    {
        $this->checkComposer();
        $this->checkNpm();
        $this->checkDockerfiles();
        $this->checkCompose();
        $this->checkShellAndMake();
        $this->checkPhpSources();
        $this->checkJavaScriptSources();
        $this->checkWebServerConfig();
        $this->checkGeneratedBundle();

        echo "\n";

        if ($this->findings === []) {
            echo "No forbidden component found in the repository.\n";

            return EXIT_CLEAN;
        }

        printf("%d repository finding(s) must be removed.\n", \count($this->findings));

        return EXIT_FINDINGS;
    }

    // =====================================================================================
    // Reporting
    // =====================================================================================

    private function ok(string $message): void
    {
        printf("  ok     %s\n", $message);
    }

    private function found(string $message): void
    {
        printf("  FOUND  %s\n", $message);
        $this->findings[] = $message;
    }

    /**
     * @param list<string> $hits
     */
    private function verdict(string $subject, array $hits): void
    {
        $hits === [] ? $this->ok($subject) : $this->found($subject . ': ' . implode(', ', array_unique($hits)));
    }

    // =====================================================================================
    // File discovery
    // =====================================================================================

    /**
     * @param callable(string): bool $matches receives the path relative to the root
     *
     * @return list<string> absolute paths
     */
    private function files(callable $matches): array
    {
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
                static fn (SplFileInfo $file): bool => !$file->isDir()
                    || !\in_array($file->getFilename(), SKIP_DIRECTORIES, true),
            ),
        );

        foreach ($iterator as $file) {
            \assert($file instanceof SplFileInfo);

            if (!$file->isFile()) {
                continue;
            }

            $relative = ltrim(str_replace($this->root, '', $file->getPathname()), '/');

            if ($matches($relative)) {
                $found[] = $file->getPathname();
            }
        }

        sort($found);

        return $found;
    }

    private function relative(string $path): string
    {
        return ltrim(str_replace($this->root, '', $path), '/');
    }

    private function read(string $path): string
    {
        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function readJson(string $path): ?array
    {
        try {
            $decoded = json_decode($this->read($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return \is_array($decoded) ? $decoded : null;
    }

    // =====================================================================================
    // Comment stripping
    // =====================================================================================

    /**
     * Removes `#` comments from shell/Dockerfile/conf text, keeping the executable part of
     * each line. Quoted `#` characters are preserved, because they are data, not comments.
     */
    private function stripHashComments(string $text): string
    {
        $out = [];

        foreach (preg_split('/\r?\n/', $text) ?: [] as $line) {
            $result = '';
            $quote = null;
            $length = \strlen($line);

            for ($i = 0; $i < $length; $i++) {
                $char = $line[$i];

                if ($quote !== null) {
                    $result .= $char;

                    if ($char === $quote && ($i === 0 || $line[$i - 1] !== '\\')) {
                        $quote = null;
                    }

                    continue;
                }

                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    $result .= $char;

                    continue;
                }

                if ($char === '#') {
                    break;
                }

                $result .= $char;
            }

            $out[] = $result;
        }

        return implode("\n", $out);
    }

    /**
     * Everything in a PHP file except its comments and docblocks. Uses the real tokeniser,
     * so a `#`, `//` or `/* *​/` inside a string literal is not mistaken for a comment.
     */
    private function stripPhpComments(string $code): string
    {
        $tokens = @token_get_all($code);

        if ($tokens === []) {
            // Unparseable: fail safe by treating the whole file as evidence.
            return $code;
        }

        $out = '';

        foreach ($tokens as $token) {
            if (\is_array($token)) {
                if ($token[0] === T_COMMENT || $token[0] === T_DOC_COMMENT) {
                    continue;
                }

                $out .= $token[1];

                continue;
            }

            $out .= $token;
        }

        return $out;
    }

    // =====================================================================================
    // Checks
    // =====================================================================================

    private function checkComposer(): void
    {
        foreach ($this->files(static fn (string $p): bool => basename($p) === 'composer.json') as $path) {
            $manifest = $this->readJson($path);
            $label = $this->relative($path);

            if ($manifest === null) {
                $this->found($label . ' is not valid JSON');

                continue;
            }

            // Declared dependencies. `replace` is deliberately NOT treated as a dependency:
            // declaring `bower-asset/*` there is what PREVENTS Composer from installing
            // Bower assets, so it is the fix rather than the violation.
            $required = array_merge(
                array_keys($manifest['require'] ?? []),
                array_keys($manifest['require-dev'] ?? []),
            );

            $hits = array_values(array_filter(
                $required,
                static fn (string $name): bool => (bool) preg_match(
                    '#(^|/)(phpdaemon|bower-asset|npm-asset)|^fxp/composer-asset-plugin$#i',
                    $name,
                ),
            ));
            $this->verdict($label . ' requires no forbidden package', $hits);

            $stability = $manifest['minimum-stability'] ?? 'stable';
            $this->verdict(
                $label . ' requires stable packages',
                $stability === 'stable' ? [] : ['minimum-stability: ' . (string) $stability],
            );

            $devConstraints = [];

            foreach (array_merge($manifest['require'] ?? [], $manifest['require-dev'] ?? []) as $name => $constraint) {
                if (\is_string($constraint) && preg_match('/(^|\|)\s*dev-|@dev/', $constraint) === 1) {
                    $devConstraints[] = $name . ': ' . $constraint;
                }
            }

            $this->verdict($label . ' has no dev constraint', $devConstraints);

            $php = $manifest['require']['php'] ?? null;

            if (\is_string($php)) {
                $this->verdict(
                    $label . ' targets a supported PHP version',
                    preg_match('/[~^>=]*\s*[57]\./', $php) === 1 ? ['php: ' . $php] : [],
                );
            }
        }

        foreach ($this->files(static fn (string $p): bool => basename($p) === 'composer.lock') as $path) {
            $lock = $this->readJson($path);
            $label = $this->relative($path);

            if ($lock === null) {
                $this->found($label . ' is not valid JSON');

                continue;
            }

            $packages = array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []);

            $forbidden = [];
            $unstable = [];

            foreach ($packages as $package) {
                $name = (string) ($package['name'] ?? '');
                $version = (string) ($package['version'] ?? '');

                if (preg_match('#(^|/)(phpdaemon|bower-asset|npm-asset)|^fxp/composer-asset-plugin$#i', $name) === 1) {
                    $forbidden[] = $name;
                }

                if (str_starts_with($version, 'dev-') || str_contains($version, '@dev')) {
                    $unstable[] = $name . '@' . $version;
                }
            }

            $this->verdict($label . ' installs no forbidden package', $forbidden);
            $this->verdict($label . ' installs only stable releases', $unstable);
        }
    }

    private function checkNpm(): void
    {
        $forbiddenNames = '#^(angular($|-)|angularjs|bower$|gulp($|-)|grunt$|jquery$|bootstrap$)#i';

        foreach ($this->files(static fn (string $p): bool => basename($p) === 'package.json') as $path) {
            $manifest = $this->readJson($path);
            $label = $this->relative($path);

            if ($manifest === null) {
                $this->found($label . ' is not valid JSON');

                continue;
            }

            $declared = array_merge(
                array_keys($manifest['dependencies'] ?? []),
                array_keys($manifest['devDependencies'] ?? []),
                array_keys($manifest['peerDependencies'] ?? []),
            );

            $hits = array_values(array_filter(
                $declared,
                static fn (string $name): bool => (bool) preg_match($forbiddenNames, $name),
            ));

            $this->verdict($label . ' declares no forbidden dependency', $hits);
        }

        foreach ($this->files(static fn (string $p): bool => basename($p) === 'package-lock.json') as $path) {
            $lock = $this->readJson($path);
            $label = $this->relative($path);

            if ($lock === null) {
                $this->found($label . ' is not valid JSON');

                continue;
            }

            $hits = [];

            foreach (array_keys($lock['packages'] ?? []) as $key) {
                $name = preg_replace('#^.*node_modules/#', '', (string) $key);

                if ($name !== '' && preg_match($forbiddenNames, $name) === 1) {
                    $hits[] = $name;
                }
            }

            $this->verdict($label . ' installs no forbidden package', $hits);
        }
    }

    private function checkDockerfiles(): void
    {
        $dockerfiles = $this->files(static fn (string $p): bool => str_starts_with(basename($p), 'Dockerfile'));

        foreach ($dockerfiles as $path) {
            $label = $this->relative($path);
            $instructions = $this->stripHashComments($this->read($path));

            // --- base images ---------------------------------------------------------
            preg_match_all('/^\s*FROM\s+(\S+)/mi', $instructions, $matches);
            $images = $matches[1] ?? [];

            $eol = [];
            $unpinned = [];

            foreach ($images as $image) {
                // ARG placeholders are resolved from a default that is checked separately.
                if (str_starts_with($image, '$')) {
                    continue;
                }

                if (preg_match('/(ubuntu:1[0-6]\.|debian:[1-8]$|php:[57]\.|node:([0-9]|1[0-9])(\.|-|$))/i', $image) === 1) {
                    $eol[] = $image;
                }

                if (str_ends_with($image, ':latest') || !str_contains($image, ':')) {
                    $unpinned[] = $image;
                }
            }

            // Defaults of build ARGs are real image references too.
            preg_match_all('/^\s*ARG\s+\w*IMAGE\w*=(\S+)/mi', $instructions, $argMatches);

            foreach ($argMatches[1] ?? [] as $image) {
                if (preg_match('/(ubuntu:1[0-6]\.|php:[57]\.)/i', $image) === 1) {
                    $eol[] = $image;
                }

                if (str_ends_with($image, ':latest')) {
                    $unpinned[] = $image;
                }
            }

            $this->verdict($label . ' uses no end-of-life base image', $eol);
            $this->verdict($label . ' pins every base image', $unpinned);

            // --- executable instructions ---------------------------------------------
            preg_match_all('/^\s*(RUN|CMD|ENTRYPOINT)\s+(.*)$/mi', $instructions, $execMatches, PREG_SET_ORDER);
            $executable = implode("\n", array_map(static fn (array $m): string => $m[2], $execMatches));

            $this->verdict(
                $label . ' executes no forbidden program',
                $this->forbiddenPrograms($executable),
            );
        }

        if ($dockerfiles === []) {
            $this->ok('no Dockerfile present');
        }
    }

    private function checkCompose(): void
    {
        $composeFiles = $this->files(
            static fn (string $p): bool => \in_array(basename($p), ['compose.yaml', 'compose.yml', 'docker-compose.yml', 'docker-compose.yaml'], true),
        );

        foreach ($composeFiles as $path) {
            $label = $this->relative($path);
            $raw = $this->read($path);

            // The obsolete top-level `version:` key must not be present. Checked on the raw
            // text at column zero, because a YAML parser would silently accept it.
            $this->verdict(
                $label . ' has no obsolete top-level version key',
                preg_match('/^version:\s*[\'"]?\d/m', $this->stripHashComments($raw)) === 1 ? ['version:'] : [],
            );

            $document = $this->parseYaml($path);

            if ($document === null) {
                $this->found($label . ' could not be parsed as YAML');

                continue;
            }

            $services = $document['services'] ?? [];

            if (!\is_array($services)) {
                continue;
            }

            $links = [];
            $names = [];
            $unpinned = [];
            $commands = [];

            foreach ($services as $service => $definition) {
                if (!\is_array($definition)) {
                    continue;
                }

                if (!empty($definition['links'])) {
                    $links[] = (string) $service;
                }

                if (!empty($definition['container_name'])) {
                    $names[] = (string) $service;
                }

                $image = $definition['image'] ?? null;

                if (\is_string($image)) {
                    $pinned = str_contains($image, '@sha256:')
                        || (bool) preg_match('/:[0-9]+(\.[0-9]+)*(-[\w.]+)?$/', $image);

                    if (!$pinned || str_ends_with($image, ':latest')) {
                        $unpinned[] = $service . ' -> ' . $image;
                    }
                }

                foreach (['command', 'entrypoint'] as $key) {
                    $value = $definition[$key] ?? null;

                    if (\is_string($value)) {
                        $commands[] = $value;
                    } elseif (\is_array($value)) {
                        $commands[] = implode(' ', array_map(strval(...), $value));
                    }
                }
            }

            $this->verdict($label . ' uses no legacy links', $links);
            $this->verdict($label . ' fixes no global container name', $names);
            $this->verdict($label . ' pins every image', $unpinned);
            $this->verdict(
                $label . ' runs no forbidden program',
                $this->forbiddenPrograms(implode("\n", $commands)),
            );
        }

        if ($composeFiles === []) {
            $this->ok('no Compose file present');
        }
    }

    private function checkShellAndMake(): void
    {
        $scripts = $this->files(
            static fn (string $p): bool => str_ends_with($p, '.sh')
                || str_ends_with($p, '.bash')
                || basename($p) === 'Makefile',
        );

        $forbiddenPrograms = [];
        $destructive = [];

        foreach ($scripts as $path) {
            $label = $this->relative($path);

            // This scanner and its regression tests necessarily contain the patterns they
            // look for; skipping them is not an exclusion of production code.
            if (str_contains($label, 'scan-forbidden') || str_contains($label, 'tests/infrastructure/scanner_test')) {
                continue;
            }

            $commands = $this->stripHashComments($this->read($path));

            foreach ($this->forbiddenPrograms($commands) as $hit) {
                $forbiddenPrograms[] = $label . ': ' . $hit;
            }

            // Commands that act on Docker objects beyond this Compose project, or that throw
            // away work (Constitution V).
            $patterns = [
                '/\bdocker\s+(rm|rmi)\s+(-\w+\s+)*\$\(/' => 'docker rm/rmi over a command substitution',
                '/\bdocker\s+(system|volume|network|image|container)\s+prune/' => 'docker prune',
                '/\bgit\s+reset\s+--hard/' => 'git reset --hard',
            ];

            foreach ($patterns as $pattern => $description) {
                if (preg_match($pattern, $commands) === 1) {
                    $destructive[] = $label . ': ' . $description;
                }
            }
        }

        $this->verdict('no script runs a forbidden program', $forbiddenPrograms);
        $this->verdict('no script acts outside this Compose project', $destructive);
    }

    private function checkPhpSources(): void
    {
        $sources = $this->files(
            fn (string $p): bool => str_ends_with($p, '.php') && !str_contains($p, 'scan-forbidden'),
        );

        $references = [];
        $hardCodedKeys = [];

        foreach ($sources as $path) {
            $label = $this->relative($path);
            $code = $this->stripPhpComments($this->read($path));

            // Real references: namespaces, class names and class-name strings. Comments and
            // docblocks are already gone, so prose cannot reach this point.
            if (preg_match('/\bPHPDaemon\b|\bphpdaemon\b/', $code) === 1) {
                $references[] = $label . ': PHPDaemon';
            }

            if (preg_match('/\bangular\.module\s*\(/', $code) === 1) {
                $references[] = $label . ': angular.module()';
            }

            // A credential assigned to a literal in configuration.
            if (preg_match("/['\"]cookieValidationKey['\"]\s*=>\s*['\"][^'\"\\\$]{6,}['\"]/", $code) === 1) {
                $hardCodedKeys[] = $label;
            }
        }

        $this->verdict('no PHP source references a forbidden component', $references);
        $this->verdict('no PHP source hard-codes a cookie validation key', $hardCodedKeys);
    }

    private function checkJavaScriptSources(): void
    {
        $sources = $this->files(
            static fn (string $p): bool => (bool) preg_match('/\.(ts|tsx|js|mjs|cjs)$/', $p),
        );

        $imports = [];

        foreach ($sources as $path) {
            $label = $this->relative($path);
            $code = $this->read($path);

            // Only real module specifiers count. A comment mentioning AngularJS cannot
            // match, because it is not an import statement.
            preg_match_all(
                '/(?:^|\s)(?:import\s+(?:[^\'"]*?\sfrom\s+)?|export\s+[^\'"]*?\sfrom\s+|require\s*\(\s*)[\'"]([^\'"]+)[\'"]/m',
                $code,
                $matches,
            );

            foreach ($matches[1] ?? [] as $specifier) {
                if (preg_match('#^(angular($|[-/])|angularjs|bower($|/)|gulp($|[-/])|jquery$|bootstrap$)#i', $specifier) === 1) {
                    $imports[] = $label . ': ' . $specifier;
                }
            }
        }

        $this->verdict('no browser source imports a forbidden library', $imports);
    }

    private function checkWebServerConfig(): void
    {
        $configs = $this->files(static fn (string $p): bool => str_ends_with($p, '.conf'));

        $hits = [];

        foreach ($configs as $path) {
            $directives = $this->stripHashComments($this->read($path));

            if (preg_match('/php[57](\.\d)?-fpm|fastcgi_pass\s+unix:.*php[57]/i', $directives) === 1) {
                $hits[] = $this->relative($path) . ': an EOL PHP-FPM socket';
            }

            foreach ($this->forbiddenPrograms($directives) as $hit) {
                $hits[] = $this->relative($path) . ': ' . $hit;
            }
        }

        $this->verdict('no web-server configuration targets an EOL runtime', $hits);
    }

    private function checkGeneratedBundle(): void
    {
        $bundles = $this->files(
            static fn (string $p): bool => str_starts_with($p, 'www/web/assets/') && str_ends_with($p, '.js'),
        );

        if ($bundles === []) {
            $this->ok('no generated bundle present to inspect');

            return;
        }

        $hits = [];

        foreach ($bundles as $path) {
            $code = $this->read($path);

            if (preg_match('/angular\.module\s*\(|\bjQuery\b|angularjs-toaster/', $code) === 1) {
                $hits[] = $this->relative($path);
            }
        }

        $this->verdict('the generated bundle ships no forbidden library', $hits);
    }

    // =====================================================================================
    // Shared matchers
    // =====================================================================================

    /**
     * Programs whose invocation means a forbidden component is actually being run.
     *
     * Matched as commands -- at a word boundary, in text that has already had its comments
     * removed -- rather than as substrings anywhere in a file.
     *
     * @return list<string>
     */
    private function forbiddenPrograms(string $commands): array
    {
        $programs = [
            'supervisord' => '/(^|[\s"\'\/;&|])supervisord\b|\bapt-get\s+install\b[^\n]*\bsupervisor\b|\bapk\s+add\b[^\n]*\bsupervisor\b/i',
            'phpd (PHPDaemon)' => '/(^|[\s"\'\/;&|])phpd\b|\bphpdaemon\b/i',
            'bower' => '/(^|[\s"\'\/;&|])bower\s+(install|update)\b|\bnpm\s+install\b[^\n]*\bbower\b/i',
            'gulp' => '/(^|[\s"\'\/;&|])gulp\b/i',
            'EOL PHP packages' => '/\bphp[57](\.\d)?-(fpm|cli|dev)\b/i',
        ];

        $hits = [];

        foreach ($programs as $label => $pattern) {
            if (preg_match($pattern, $commands) === 1) {
                $hits[] = $label;
            }
        }

        return $hits;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function parseYaml(string $path): ?array
    {
        if (!class_exists(Symfony\Component\Yaml\Yaml::class)) {
            fwrite(STDERR, "symfony/yaml is required to parse Compose files\n");
            exit(EXIT_USAGE);
        }

        try {
            $parsed = Symfony\Component\Yaml\Yaml::parseFile($path);
        } catch (Throwable) {
            return null;
        }

        return \is_array($parsed) ? $parsed : null;
    }
}
