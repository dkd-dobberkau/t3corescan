<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Tests\Functional\Scanner;

use T3x\T3Corescan\Scanner\ScannerAdapter;
use T3x\T3Corescan\Tests\Functional\Fixtures\WarningMatcher;
use TYPO3\CMS\Core\Information\Typo3Version;
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

    /**
     * The other tests would pass on any Core line, because the CacheFactory rule
     * exists in every ruleset since v9 — so they prove "the scanner still runs",
     * not "the scanner uses the installed Core's rules". This one asserts a
     * difference: TYPO3\CMS\Core\Service\FlexFormService is a rule in the v14
     * ruleset and in no earlier one. A run that silently fell back to a shipped
     * or stale ruleset fails here.
     */
    public function testRulesetComesFromTheInstalledCoreVersion(): void
    {
        $adapter = new ScannerAdapter();
        $fixture = dirname(__DIR__, 2) . '/Fixtures/V14OnlyRule.php';

        $result = $adapter->scanFile($fixture);
        self::assertNull($result->parseError, 'fixture should parse cleanly');

        $hits = array_values(array_filter(
            $result->hits,
            static fn ($hit) => $hit->matcher === 'ClassNameMatcher'
                && str_contains($hit->message, 'TYPO3\\CMS\\Core\\Service\\FlexFormService'),
        ));

        if ((new Typo3Version())->getMajorVersion() >= 14) {
            self::assertCount(1, $hits, 'v14 ships a rule for this class — expected exactly one hit');
            self::assertSame('strong', $hits[0]->indicator);
            self::assertContains(
                'Breaking-107945-ClassFlexFormServiceMergedIntoFlexFormTools.rst',
                $hits[0]->restFiles,
            );
        } else {
            self::assertSame([], $hits, 'before v14 there is no rule for this class — a hit would mean a foreign ruleset');
        }
    }

    public function testReportsNoHitsForCleanFile(): void
    {
        $adapter = new ScannerAdapter();
        $fixture = dirname(__DIR__, 2) . '/Fixtures/CleanFile.php';

        $result = $adapter->scanFile($fixture);

        self::assertTrue($result->isClean(), 'clean fixture must produce zero hits');
    }

    public function testWarningInAMatcherBecomesTheScanErrorOfThatFile(): void
    {
        $adapter = new ScannerAdapter([['class' => WarningMatcher::class, 'configurationArray' => []]]);
        $fixture = dirname(__DIR__, 2) . '/Fixtures/CleanFile.php';

        $result = $adapter->scanFile($fixture);

        self::assertNull($result->parseError);
        self::assertNotNull($result->scanError, 'the matcher warning must be reported, not swallowed');
        self::assertStringContainsString('propertyNoNodeHas', $result->scanError);
        self::assertSame([], $result->hits, 'hits of an aborted file are incomplete and must not be reported');
        self::assertFalse($result->isClean());
    }

    /**
     * Regression test against the Core itself. On a Core that still has the
     * VariadicPlaceholder bug the file carries a scan error; on a fixed Core it
     * scans clean. What must never happen is the exception escaping scanFile().
     */
    public function testStaticFirstClassCallableDoesNotEscapeAsException(): void
    {
        $adapter = new ScannerAdapter();
        $fixture = dirname(__DIR__, 2) . '/Fixtures/ScanError/FirstClassCallable.php';

        $result = $adapter->scanFile($fixture);

        self::assertNull($result->parseError);
        if ($result->scanError !== null) {
            self::assertStringContainsString('VariadicPlaceholder', $result->scanError);
        } else {
            self::assertTrue($result->isClean());
        }
    }
}
