<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Scanner\Result;

final class Hit
{
    public function __construct(
        public readonly string $absoluteFilePath,
        public readonly string $relativeFilePath,
        public readonly int $line,
        public readonly string $indicator,
        public readonly string $matcher,
        public readonly string $message,
        /** @var string[] */
        public readonly array $restFiles,
    ) {}

    public function toArray(): array
    {
        return [
            'file' => $this->relativeFilePath,
            'absoluteFile' => $this->absoluteFilePath,
            'line' => $this->line,
            'indicator' => $this->indicator,
            'matcher' => $this->matcher,
            'message' => $this->message,
            'restFiles' => $this->restFiles,
        ];
    }
}
