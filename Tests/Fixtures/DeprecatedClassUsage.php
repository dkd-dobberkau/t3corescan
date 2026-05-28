<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Tests\Fixtures;

use TYPO3\CMS\Core\Cache\CacheFactory;

final class DeprecatedClassUsage
{
    public function run(): void
    {
        $factory = new CacheFactory();
        unset($factory);
    }
}
