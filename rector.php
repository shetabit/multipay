<?php

use Rector\Config\RectorConfig;
use Rector\Php83\Rector\ClassMethod\AddOverrideAttributeToOverriddenMethodsRector;
use Rector\Strict\Rector\Empty_\DisallowedEmptyRuleFixerRector;
use Rector\TypeDeclaration\Rector\StmtsAwareInterface\SafeDeclareStrictTypesRector;

return RectorConfig::configure()
    ->withPaths([
        __DIR__.'/config',
        __DIR__.'/src',
        __DIR__.'/tests',
    ])
    ->withSkip([
        AddOverrideAttributeToOverriddenMethodsRector::class,

        // The drivers pass the values of a gateway's callback around as they come in,
        // where a numeric string standing in for a number is normal. Turning strict
        // types on would have to happen for the whole package at once, and with a
        // careful look at every driver.
        SafeDeclareStrictTypesRector::class,

        // Turns `!empty($string)` into `$string !== '' && $string !== '0'`, which is
        // correct but harder to read than what it replaces.
        DisallowedEmptyRuleFixerRector::class,
    ])
    ->withPreparedSets(
        deadCode: true,
        codeQuality: true,
        typeDeclarations: true,
        privatization: true,
        earlyReturn: true,
    )
    ->withPhpSets();
