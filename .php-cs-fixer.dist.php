<?php
// php vendor/bin/php-cs-fixer fix nome_file.php --config=.php-cs-fixer.dist.php
$finder = (new PhpCsFixer\Finder())
        ->in(__DIR__ . "/src")
        ->exclude('var')
        ->exclude('vendor')
;
return (new PhpCsFixer\Config())
                ->setRules([
                    //'@PhpCsFixer' => true,
                    '@PSR12' => true,
                    //'strict_param' => true,
                    'array_syntax' => ['syntax' => 'short'],
                ])
                ->setRiskyAllowed(false)
                ->setFinder($finder)
;
