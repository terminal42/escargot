<?php

$date = date('Y');

$header = <<<EOF
Escargot

@copyright  Copyright (c) 2019 - $date, terminal42 gmbh
@author     terminal42 gmbh <info@terminal42.ch>
@license    MIT
EOF;

$finder = PhpCsFixer\Finder::create()
    ->in([__DIR__.'/src', __DIR__.'/tests'])
;

$config = new PhpCsFixer\Config();
$config
    ->setRiskyAllowed(true)
    ->setRules([
        '@Symfony' => true,
        '@Symfony:risky' => true,
        'array_syntax' => ['syntax' => 'short'],
        'combine_consecutive_unsets' => true,
        'declare_strict_types' => true,
        'general_phpdoc_annotation_remove' => true,
        'header_comment' => ['header' => $header],
        'heredoc_to_nowdoc' => true,
        'no_extra_blank_lines' => true,
        'no_unreachable_default_argument_value' => true,
        'no_useless_else' => true,
        'no_useless_return' => true,
        'no_superfluous_phpdoc_tags' => true,
        'ordered_class_elements' => true,
        'ordered_imports' => true,
        'php_unit_strict' => true,
        'phpdoc_add_missing_param_annotation' => true,
        'phpdoc_order' => true,
        'psr_autoloading' => true,
        'strict_comparison' => true,
        'strict_param' => true,
        'native_function_invocation' => ['include' => ['@compiler_optimized']],
        'void_return' => true,
    ])
    ->setFinder($finder)
;

return $config;
