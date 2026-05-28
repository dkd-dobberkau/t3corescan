<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Tests\Functional\Command;

use T3x\T3Corescan\Command\ScanCommand;
use T3x\T3Corescan\Scanner\PathResolver;
use T3x\T3Corescan\Scanner\ScannerAdapter;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

final class ScanCommandTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['install'];

    protected array $testExtensionsToLoad = [
        't3corescan' => 'typo3conf/ext/t3corescan',
    ];

    public function testJsonFormatReportsHitsWithStableSchema(): void
    {
        $command = new ScanCommand(new ScannerAdapter(), new PathResolver());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'paths' => [dirname(__DIR__, 2) . '/Fixtures'],
            '--format' => 'json',
        ]);

        self::assertSame(1, $exitCode, 'expected non-zero exit because the fixture has hits');

        $decoded = json_decode($tester->getDisplay(), true, 32, JSON_THROW_ON_ERROR);

        self::assertIsArray($decoded);
        self::assertArrayHasKey('summary', $decoded);
        self::assertArrayHasKey('results', $decoded);

        $summary = $decoded['summary'];
        self::assertGreaterThanOrEqual(2, $summary['filesScanned']);
        self::assertGreaterThanOrEqual(1, $summary['hits']['total']);
        self::assertArrayHasKey('strong', $summary['hits']['byIndicator']);

        $cacheFactoryHit = null;
        foreach ($decoded['results'] as $result) {
            foreach ($result['hits'] as $hit) {
                if ($hit['matcher'] === 'ClassNameMatcher' && str_contains((string)$hit['message'], 'CacheFactory')) {
                    $cacheFactoryHit = $hit;
                    break 2;
                }
            }
        }

        self::assertNotNull($cacheFactoryHit, 'JSON output must contain the CacheFactory ClassNameMatcher hit');
        self::assertSame('strong', $cacheFactoryHit['indicator']);
        self::assertContains('Breaking-80700-DeprecatedFunctionalityRemoved.rst', $cacheFactoryHit['restFiles']);
        self::assertGreaterThan(0, $cacheFactoryHit['line']);
        self::assertNotEmpty($cacheFactoryHit['file']);
    }

    public function testNoFailFlagForcesZeroExit(): void
    {
        $command = new ScanCommand(new ScannerAdapter(), new PathResolver());
        $tester = new CommandTester($command);

        $exitCode = $tester->execute([
            'paths' => [dirname(__DIR__, 2) . '/Fixtures'],
            '--format' => 'json',
            '--no-fail' => true,
        ]);

        self::assertSame(0, $exitCode);
    }
}
