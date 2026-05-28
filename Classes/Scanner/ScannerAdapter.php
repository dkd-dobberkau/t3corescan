<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Scanner;

use T3x\T3Corescan\Scanner\Result\FileScanResult;
use T3x\T3Corescan\Scanner\Result\Hit;
use PhpParser\Error as PhpParserError;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\NameResolver;
use PhpParser\ParserFactory;
use PhpParser\PhpVersion;
use TYPO3\CMS\Install\ExtensionScanner\Php\CodeStatistics;
use TYPO3\CMS\Install\ExtensionScanner\Php\GeneratorClassesResolver;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\AbstractMethodImplementationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayDimensionMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ArrayGlobalMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ClassConstantMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ClassNameMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ConstantMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ConstructorArgumentMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\FunctionCallMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\InterfaceMethodChangedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodAnnotationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentDroppedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentDroppedStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentRequiredMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentRequiredStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodArgumentUnusedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallArgumentValueMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\MethodCallStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyAnnotationMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyExistsStaticMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyProtectedMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\PropertyPublicMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\Matcher\ScalarStringMatcher;
use TYPO3\CMS\Install\ExtensionScanner\Php\MatcherFactory;

/**
 * Sole bridge to the TYPO3 Core Extension Scanner.
 *
 * ALL references to internal core classes from TYPO3\CMS\Install\ExtensionScanner\* are
 * deliberately concentrated here. The Core marks those classes as @internal — the matcher
 * list, their signatures and the configuration-file layout are bound to the installed Core
 * version and may change between major releases without a deprecation path.
 *
 * This list mirrors UpgradeController::$matchers in TYPO3 v13.4 (verified 2026-05-27).
 * If a Core update changes that registry, edit it here in one place and update the
 * version constraints in composer.json / ext_emconf.php accordingly.
 */
final class ScannerAdapter
{
    /**
     * Matcher registry mirrored from
     * vendor/typo3/cms-install/Classes/Controller/UpgradeController.php (v13.4).
     *
     * @var list<array{class: class-string, configurationFile: string}>
     */
    private const MATCHERS = [
        ['class' => ArrayDimensionMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ArrayDimensionMatcher.php'],
        ['class' => ArrayGlobalMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ArrayGlobalMatcher.php'],
        ['class' => ClassConstantMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ClassConstantMatcher.php'],
        ['class' => ClassNameMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ClassNameMatcher.php'],
        ['class' => ConstantMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ConstantMatcher.php'],
        ['class' => ConstructorArgumentMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ConstructorArgumentMatcher.php'],
        ['class' => PropertyAnnotationMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/PropertyAnnotationMatcher.php'],
        ['class' => MethodAnnotationMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodAnnotationMatcher.php'],
        ['class' => FunctionCallMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/FunctionCallMatcher.php'],
        ['class' => AbstractMethodImplementationMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/AbstractMethodImplementationMatcher.php'],
        ['class' => InterfaceMethodChangedMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/InterfaceMethodChangedMatcher.php'],
        ['class' => MethodArgumentDroppedMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentDroppedMatcher.php'],
        ['class' => MethodArgumentDroppedStaticMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentDroppedStaticMatcher.php'],
        ['class' => MethodArgumentRequiredMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentRequiredMatcher.php'],
        ['class' => MethodArgumentRequiredStaticMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentRequiredStaticMatcher.php'],
        ['class' => MethodArgumentUnusedMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodArgumentUnusedMatcher.php'],
        ['class' => MethodCallMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodCallMatcher.php'],
        ['class' => MethodCallArgumentValueMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodCallArgumentValueMatcher.php'],
        ['class' => MethodCallStaticMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/MethodCallStaticMatcher.php'],
        ['class' => PropertyExistsStaticMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/PropertyExistsStaticMatcher.php'],
        ['class' => PropertyProtectedMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/PropertyProtectedMatcher.php'],
        ['class' => PropertyPublicMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/PropertyPublicMatcher.php'],
        ['class' => ScalarStringMatcher::class, 'configurationFile' => 'EXT:install/Configuration/ExtensionScanner/Php/ScalarStringMatcher.php'],
    ];

    public function scanFile(string $absoluteFilePath, ?string $projectRoot = null): FileScanResult
    {
        $relativeFilePath = $this->resolveRelative($absoluteFilePath, $projectRoot);
        $contents = @file_get_contents($absoluteFilePath);
        if ($contents === false) {
            return new FileScanResult($absoluteFilePath, $relativeFilePath, [], false, 0, 0, 'unable to read file');
        }

        $parser = (new ParserFactory())->createForVersion(PhpVersion::fromComponents(8, 2));

        try {
            $statements = $parser->parse($contents);
        } catch (PhpParserError $e) {
            return new FileScanResult($absoluteFilePath, $relativeFilePath, [], false, 0, 0, $e->getMessage());
        }
        if ($statements === null) {
            return new FileScanResult($absoluteFilePath, $relativeFilePath, [], false, 0, 0, 'parser returned no statements');
        }

        // Pass 1: resolve "use" statements to fully-qualified names so matchers see FQNs.
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new NameResolver());
        $statements = $traverser->traverse($statements);

        // Pass 2: resolve GeneralUtility::makeInstance('FQN') call sites, collect statistics, run matchers.
        $traverser = new NodeTraverser();
        $traverser->addVisitor(new GeneratorClassesResolver());
        $statistics = new CodeStatistics();
        $traverser->addVisitor($statistics);

        $matchers = (new MatcherFactory())->createAll(self::MATCHERS);
        foreach ($matchers as $matcher) {
            $traverser->addVisitor($matcher);
        }
        $traverser->traverse($statements);

        $hits = [];
        foreach ($matchers as $matcher) {
            $matcherShortName = (new \ReflectionClass($matcher))->getShortName();
            foreach ($matcher->getMatches() as $match) {
                $hits[] = new Hit(
                    absoluteFilePath: $absoluteFilePath,
                    relativeFilePath: $relativeFilePath,
                    line: (int)($match['line'] ?? 0),
                    indicator: (string)($match['indicator'] ?? 'unknown'),
                    matcher: $matcherShortName,
                    message: (string)($match['message'] ?? ''),
                    restFiles: array_values(array_unique($match['restFiles'] ?? [])),
                );
            }
        }

        return new FileScanResult(
            absoluteFilePath: $absoluteFilePath,
            relativeFilePath: $relativeFilePath,
            hits: $hits,
            isFileIgnored: $statistics->isFileIgnored(),
            effectiveCodeLines: $statistics->getNumberOfEffectiveCodeLines(),
            ignoredLines: $statistics->getNumberOfIgnoredLines(),
        );
    }

    private function resolveRelative(string $absoluteFilePath, ?string $projectRoot): string
    {
        if ($projectRoot === null || $projectRoot === '') {
            return $absoluteFilePath;
        }
        $root = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        if (str_starts_with($absoluteFilePath, $root)) {
            return substr($absoluteFilePath, strlen($root));
        }
        return $absoluteFilePath;
    }
}
