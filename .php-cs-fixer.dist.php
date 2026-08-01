<?php

/*
 * Milpa family coding standard — shared verbatim across milpa/core, milpa/http
 * and milpa/tool-runtime. PSR-12 base plus the deltas below. Change it in one
 * package only in lockstep with the others (future home: milpa/coding-standard).
 */

use PhpCsFixer\Config;
use PhpCsFixer\Finder;

$finder = Finder::create()
    ->in(array_filter(['src', 'tests', 'tools'], 'is_dir'))
    ->exclude(['vendor']);

return (new Config())
    ->setParallelConfig(PhpCsFixer\Runner\Parallel\ParallelConfigFactory::detect())
    ->setRiskyAllowed(true)
    ->setRules([
        '@PSR12' => true,
        'array_syntax' => ['syntax' => 'short'],
        'concat_space' => ['spacing' => 'one'],
        'method_argument_space' => ['on_multiline' => 'ensure_fully_multiline'],
        'no_unused_imports' => true,
        'phpdoc_align' => true,
        'phpdoc_separation' => true,
        'phpdoc_trim' => true,
        'trailing_comma_in_multiline' => true,
        'ternary_operator_spaces' => true,
        'align_multiline_comment' => true,
    ])
    ->setFinder($finder);
