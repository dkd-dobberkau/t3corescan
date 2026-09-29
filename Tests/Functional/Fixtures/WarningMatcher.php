<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Tests\Functional\Fixtures;

use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use TYPO3\CMS\Install\ExtensionScanner\CodeScannerInterface;

/**
 * A matcher that fails the way the Core matchers can fail: it reads a property
 * the node does not have, which raises an "Undefined property" warning.
 *
 * It lets the tests prove that a failing matcher costs one file and not the run,
 * independent of whether the installed Core still has such a bug.
 */
final class WarningMatcher extends NodeVisitorAbstract implements CodeScannerInterface
{
    /**
     * @param array<mixed> $matcherDefinitions
     */
    public function __construct(array $matcherDefinitions = []) {}

    public function enterNode(Node $node): null
    {
        $node->propertyNoNodeHas;
        return null;
    }

    public function getMatches(): array
    {
        return [];
    }
}
