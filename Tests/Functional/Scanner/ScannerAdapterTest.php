<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Tests\Functional\Scanner;

use T3x\T3Corescan\Scanner\ScannerAdapter;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ScannerAdapterTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = [
        't3corescan' => 'typo3conf/ext/t3corescan',
    ];

    public function testDetectsRemovedClassNameAsStrongHit(): void
    {
        $adapter = new ScannerAdapter();
        $fixture = dirname(__DIR__, 2) . '/Fixtures/DeprecatedClassUsage.php';

        $result = $adapter->scanFile($fixture);

        self::assertNull($result->parseError, 'fixture should parse cleanly');
        self::assertNotEmpty($result->hits, 'expected at least one hit on the removed CacheFactory class');

        $cacheFactoryHits = array_values(array_filter(
            $result->hits,
            static fn ($hit) => $hit->matcher === 'ClassNameMatcher'
                && str_contains($hit->message, 'TYPO3\\CMS\\Core\\Cache\\CacheFactory'),
        ));

        self::assertCount(1, $cacheFactoryHits, 'expected exactly one ClassNameMatcher hit for CacheFactory');
        $hit = $cacheFactoryHits[0];

        self::assertSame('strong', $hit->indicator);
        self::assertContains(
            'Breaking-80700-DeprecatedFunctionalityRemoved.rst',
            $hit->restFiles,
            'expected the breaking-change rst reference to be present',
        );
        self::assertGreaterThan(0, $hit->line);
    }

    public function testReportsNoHitsForCleanFile(): void
    {
        $adapter = new ScannerAdapter();
        $fixture = dirname(__DIR__, 2) . '/Fixtures/CleanFile.php';

        $result = $adapter->scanFile($fixture);

        self::assertTrue($result->isClean(), 'clean fixture must produce zero hits');
    }
}
