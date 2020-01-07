<?php

$date = date('Y');

$header = <<<EOF
Escargot

@copyright  Copyright (c) 2019 - $date, terminal42 gmbh
@author     terminal42 gmbh <info@terminal42.ch>
@license    MIT
EOF;

return PhpCsFixer\Config::create()
    ->setRiskyAllowed(true)
    ->setRules([
            '@Symfony' => true,
            '@Symfony:risky' => true,
            'array_syntax' => array('syntax' => 'short'),
            'combine_consecutive_unsets' => true,
            'declare_strict_types' => true,
            // one should use PHPUnit methods to set up expected exception instead of annotations
            'general_phpdoc_annotation_remove' => array('expectedException', 'expectedExceptionMessage', 'expectedExceptionMessageRegExp'),
            'header_comment' => array('header' => $header),
            'heredoc_to_nowdoc' => true,
            'no_extra_consecutive_blank_lines' => array('break', 'continue', 'extra', 'return', 'throw', 'use', 'parenthesis_brace_block', 'square_brace_block', 'curly_brace_block'),
            'no_unreachable_default_argument_value' => true,
            'no_useless_else' => true,
            'no_useless_return' => true,
            'no_superfluous_phpdoc_tags' => true,
            'ordered_class_elements' => true,
            'ordered_imports' => true,
            'php_unit_strict' => true,
            'phpdoc_add_missing_param_annotation' => true,
            'phpdoc_order' => true,
            'psr4' => true,
            'strict_comparison' => true,
            'strict_param' => true,
            'native_function_invocation' => ['include' => ['@compiler_optimized']],
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()->in([__DIR__.'/src', __DIR__.'/tests'])
    )
;
