<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Scanner\Result;

final class FileScanResult
{
    /**
     * @param Hit[] $hits
     */
    public function __construct(
        public readonly string $absoluteFilePath,
        public readonly string $relativeFilePath,
        public readonly array $hits,
        public readonly bool $isFileIgnored,
        public readonly int $effectiveCodeLines,
        public readonly int $ignoredLines,
        public readonly ?string $parseError = null,
    ) {}

    public function isClean(): bool
    {
        return $this->hits === [] && $this->parseError === null;
    }
}
