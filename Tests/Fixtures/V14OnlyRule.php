<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Tests\Fixtures;

use TYPO3\CMS\Core\Service\FlexFormService;

/**
 * The counterpart to DeprecatedClassUsage: this class is only in the v14 ruleset.
 *
 * TYPO3\CMS\Core\Service\FlexFormService was merged into FlexFormTools in v14
 * (Breaking-107945), so a v13 ruleset has no rule for it while a v14 one does.
 * That makes the scan result differ between the two Core lines, which is what
 * proves the ruleset really comes from the installed Core rather than from
 * anything this extension ships.
 */
final class V14OnlyRule
{
    public function run(): string
    {
        return FlexFormService::class;
    }
}
