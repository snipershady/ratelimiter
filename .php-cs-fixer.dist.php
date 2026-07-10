<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__."/src")
    ->in(__DIR__."/tests")
    ->exclude('vendor')
;

return (new PhpCsFixer\Config())
                ->setRules([
                    '@Symfony' => true,
                    'array_syntax' => ['syntax' => 'short'],
                    'list_syntax' => ['syntax' => 'short'],
                    'modernize_types_casting' => true,
                    'is_null' => true,
                    'no_alias_functions' => true,
                    'no_unused_imports' => true,
                    'no_empty_comment' => true,
                    'no_empty_phpdoc' => true,
                    'no_useless_return' => true,
                    'no_useless_else' => true,
                    'no_empty_statement' => true,
                    'no_extra_blank_lines' => ['tokens' => ['extra', 'use', 'return']],
                    'standardize_not_equals' => true,
                    'single_quote' => true,
                    'short_scalar_cast' => true,
                    'magic_constant_casing' => true,
                    'native_function_casing' => true,
                    'concat_space' => ['spacing' => 'one'],
                    'cast_spaces' => ['space' => 'single'],
                    'object_operator_without_whitespace' => true,
                    'ternary_operator_spaces' => true,
                ])
                ->setRiskyAllowed(true)
                ->setFinder($finder)
;
