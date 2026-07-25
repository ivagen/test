<?php

declare(strict_types=1);

/**
 * Coding style (tasks.md T022; PHP-CS-Fixer selected in research.md).
 *
 * PSR-12 plus a small set of rules that keep diffs readable and imports tidy. Nothing here
 * rewrites logic -- style must never be able to change behaviour.
 */

/*
 * `migrations/` is deliberately absent.
 *
 * m160815_184648_create_items_table.php has been applied to every existing database since
 * 2016. It is a historical record of what was executed, not live code, and rewriting it --
 * even to add `declare(strict_types=1)` -- would make the file disagree with what actually
 * ran. It is still covered by PHPStan and by the migration preservation test.
 */
$finder = PhpCsFixer\Finder::create()
    ->in([
        __DIR__ . '/commands',
        __DIR__ . '/components',
        __DIR__ . '/controllers',
        __DIR__ . '/models',
        __DIR__ . '/services',
        __DIR__ . '/tests',
    ])
    ->append([
        __DIR__ . '/config/common.php',
        __DIR__ . '/config/console.php',
        __DIR__ . '/config/params.php',
        __DIR__ . '/config/web.php',
    ]);

return (new PhpCsFixer\Config())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'binary_operator_spaces' => true,
        'blank_line_after_opening_tag' => true,
        'blank_line_before_statement' => [
            'statements' => ['return', 'throw', 'try', 'if', 'foreach', 'while'],
        ],
        'concat_space' => ['spacing' => 'one'],
        'declare_strict_types' => true,
        'no_unused_imports' => true,
        'ordered_imports' => ['sort_algorithm' => 'alpha'],
        'single_quote' => true,
        'trailing_comma_in_multiline' => ['elements' => ['arrays', 'arguments', 'parameters']],
        'phpdoc_align' => false,
        'phpdoc_separation' => false,
    ])
    ->setFinder($finder)
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
