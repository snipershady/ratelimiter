<?php

declare(strict_types=1);

use Rector\CodingStyle\Rector\Stmt\NewlineAfterStatementRector;
use Rector\Config\RectorConfig;
use Rector\Naming\Rector\Class_\RenamePropertyToMatchTypeRector;
use Rector\Php84\Rector\MethodCall\NewMethodCallWithoutParenthesesRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
                ->withPaths([
                    __DIR__ . '/src',                    
                    __DIR__ . '/tests',                    
                ])
                ->withSkip([
                    //__DIR__ . '/tests',
                    NewlineAfterStatementRector::class,
                    NewMethodCallWithoutParenthesesRector::class,
                    RenamePropertyToMatchTypeRector::class,
                    Rector\Naming\Rector\Assign\RenameVariableToMatchMethodCallReturnTypeRector::class
                ])
                ->withPreparedSets(
                        //deadCode: true,
                        //codeQuality: true,
                        codingStyle: true,
                        naming: true,
                        privatization: true,
                        //typeDeclarations: true,
                        //rectorPreset: true
                )
                ->withPhpSets(php85: true)
                ->withPhpVersion(PhpVersion::PHP_85)
                ->withAttributesSets(symfony: true, doctrine: true)
                ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
                ->withSets(
                        [
                            LevelSetList::UP_TO_PHP_85
                        ]
                )
                ->withRules(
                        [
                        ]
                )
                ->withTypeCoverageLevel(50)
                ->withDeadCodeLevel(50)
                ->withCodeQualityLevel(50)
;
