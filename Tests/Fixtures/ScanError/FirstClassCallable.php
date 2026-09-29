<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Tests\Fixtures\ScanError;

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * A static call in first-class callable syntax.
 *
 * In TYPO3 v14.3 and on main, AbstractCoreMatcher::isArgumentUnpackingUsed()
 * reads `$arg->unpack` on every argument of a static call. For `(...)` the only
 * argument is a PhpParser\Node\VariadicPlaceholder, which has no such property.
 * Once the Core fixes that, this file scans clean.
 */
final class FirstClassCallable
{
    /**
     * @return list<string>
     */
    public function run(): array
    {
        return array_map(GeneralUtility::underscoredToUpperCamelCase(...), ['first_class']);
    }
}
