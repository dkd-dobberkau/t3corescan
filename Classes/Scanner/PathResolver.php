<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Scanner;

use TYPO3\CMS\Core\Core\Environment;

/**
 * Locate the project root and the conventional locations for custom extensions.
 *
 * Auto-detection covers both layouts:
 *   - Composer-based: <project-root>/packages/
 *   - Legacy:         <project-root>/typo3conf/ext/  (and public/typo3conf/ext/ on Composer setups)
 *
 * Anything outside the project root is silently skipped because the relative-path
 * normalization downstream depends on a stable root.
 */
final class PathResolver
{
    public function projectRoot(): string
    {
        if (class_exists(Environment::class) && Environment::getProjectPath() !== '') {
            return rtrim(Environment::getProjectPath(), DIRECTORY_SEPARATOR);
        }
        return rtrim((string)getcwd(), DIRECTORY_SEPARATOR);
    }

    /**
     * @return list<string> Absolute paths of detected extension roots.
     */
    public function defaultScanPaths(): array
    {
        $root = $this->projectRoot();
        $candidates = [
            $root . '/packages',
            $root . '/typo3conf/ext',
            $root . '/public/typo3conf/ext',
        ];

        $detected = [];
        foreach ($candidates as $candidate) {
            if (is_dir($candidate)) {
                $detected[] = $candidate;
            }
        }
        return $detected;
    }
}
