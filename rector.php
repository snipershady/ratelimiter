<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Php84\Rector\Param\ExplicitNullableParamTypeRector;
use Rector\Set\ValueObject\LevelSetList;
use Rector\TypeDeclaration\Rector\ClassMethod\ReturnTypeFromStrictNativeCallRector;
use Rector\ValueObject\PhpVersion;

return RectorConfig::configure()
                ->withPaths([
                    __DIR__ . '/src',
                ])
                ->withPreparedSets(
                //deadCode: true,
                //codeQuality: true,
                //codingStyle: true,
                //naming: true,
                //privatization: true,
                //typeDeclarations: true,
                //rectorPreset: true
                )
                ->withPhpSets(php84: true)
                ->withPhpVersion(PhpVersion::PHP_84)
                ->withAttributesSets(symfony: true, doctrine: true)
                ->withComposerBased(twig: true, doctrine: true, phpunit: true, symfony: true)
                ->withSets(
                        [
                            LevelSetList::UP_TO_PHP_84
                        ]
                )
                ->withRules(
                        [
                            ExplicitNullableParamTypeRector::class,
                            AddOverrideAttributeToOverriddenMethodsRector::class,
                            //ReturnTypeFromStrictNativeCallRector::class
                        ]
                )
->withTypeCoverageLevel(50)
->withDeadCodeLevel(15)
->withCodeQualityLevel(50)
;
