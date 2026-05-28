<?php

declare(strict_types=1);

/*
 * Functional test bootstrap.
 *
 * Delegates to TYPO3 testing-framework so a full TYPO3 bootstrap with PackageManager etc.
 * is available — the Core Extension Scanner internally resolves `EXT:install/Configuration/...`
 * paths via GeneralUtility::getFileAbsFileName(), which requires an initialized package
 * registry.
 */

call_user_func(static function (): void {
    $testbase = __DIR__ . '/../../.Build/vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php';
    if (!is_file($testbase)) {
        $testbase = __DIR__ . '/../../vendor/typo3/testing-framework/Resources/Core/Build/FunctionalTestsBootstrap.php';
    }
    if (!is_file($testbase)) {
        fwrite(STDERR, "Cannot find typo3/testing-framework FunctionalTestsBootstrap.php.\n"
            . "Run 'composer install' inside the extension first.\n");
        exit(1);
    }
    require $testbase;
});
