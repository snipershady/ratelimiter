<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__."/src")
    ->in(__DIR__."/tests")
    ->exclude('vendor')
;

return (new PhpCsFixer\Config())
    ->setRules([
        '@Symfony' => true,
    ])
    ->setFinder($finder)
;
