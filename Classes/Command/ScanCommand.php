<?php

declare(strict_types=1);

namespace T3x\T3Corescan\Command;

use T3x\T3Corescan\Scanner\PathResolver;
use T3x\T3Corescan\Scanner\Result\FileScanResult;
use T3x\T3Corescan\Scanner\ScannerAdapter;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Component\Finder\Finder;

final class ScanCommand extends Command
{
    private const DEFAULT_EXCLUDES = ['vendor', 'node_modules', '.Build', 'var', '.git'];

    /**
     * Which findings make the command exit non-zero.
     *
     * `strong` is the default because weak hits are name collisions as often as
     * findings: MethodCallMatcher cannot know a receiver's type, so it flags every
     * call to a known method name. A measured scan of 358 files of real extension
     * code produced 70 strong and 151 weak hits — a gate that trips on weak hits
     * trips on every project, which says nothing.
     */
    private const FAIL_ON = ['strong', 'any', 'none'];

    private const NOTE = 'Static analysis only — dynamically composed calls or runtime class names are out of scope.';

    public function __construct(
        private readonly ScannerAdapter $scanner,
        private readonly PathResolver $pathResolver,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('t3x:extensionscanner:scan')
            ->setDescription('Run the TYPO3 Core Extension Scanner against custom extensions on the CLI (JSON-capable).')
            ->setHelp(
                'Wraps the internal Core Extension Scanner (EXT:install) and exposes it as a CLI command'
                . ' so it can be plugged into upgrade scripts. The set of checks mirrors what the Admin'
                . ' Tools backend module would show for the installed Core version.'
            )
            ->addArgument('paths', InputArgument::IS_ARRAY, 'Directories to scan. Defaults to packages/ and typo3conf/ext/ when present.')
            ->addOption('format', null, InputOption::VALUE_REQUIRED, 'Output format: table or json.', 'table')
            ->addOption('exclude', null, InputOption::VALUE_IS_ARRAY | InputOption::VALUE_REQUIRED, 'Directory names to exclude (default: ' . implode(', ', self::DEFAULT_EXCLUDES) . ').')
            ->addOption('fail-on', null, InputOption::VALUE_REQUIRED, 'Which findings make the exit code non-zero: ' . implode(', ', self::FAIL_ON) . '.', 'strong')
            ->addOption('no-fail', null, InputOption::VALUE_NONE, 'Always exit 0 even when hits are found. Same as --fail-on=none.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $paths = $input->getArgument('paths');
        if ($paths === []) {
            $paths = $this->pathResolver->defaultScanPaths();
            if ($paths === []) {
                $io->error('No scan paths given and no default location detected (looked for packages/ and typo3conf/ext/ under ' . $this->pathResolver->projectRoot() . ').');
                return Command::INVALID;
            }
        }

        $resolvedPaths = $this->resolvePaths($paths);
        if ($resolvedPaths === []) {
            $io->error('None of the supplied paths exist.');
            return Command::INVALID;
        }

        $format = strtolower((string)$input->getOption('format'));
        if (!in_array($format, ['table', 'json'], true)) {
            $io->error('Unknown format "' . $format . '". Use --format=table or --format=json.');
            return Command::INVALID;
        }

        $failOn = strtolower((string)$input->getOption('fail-on'));
        if (!in_array($failOn, self::FAIL_ON, true)) {
            $io->error('Unknown value "' . $failOn . '" for --fail-on. Use ' . implode(', ', self::FAIL_ON) . '.');
            return Command::INVALID;
        }
        if ($input->getOption('no-fail')) {
            $failOn = 'none';
        }

        $excludes = $input->getOption('exclude') ?: self::DEFAULT_EXCLUDES;
        $files = $this->collectFiles($resolvedPaths, $excludes);

        $projectRoot = $this->pathResolver->projectRoot();
        /** @var FileScanResult[] $results */
        $results = [];
        foreach ($files as $absolutePath) {
            $results[] = $this->scanner->scanFile($absolutePath, $projectRoot);
        }

        $summary = $this->buildSummary($results, $resolvedPaths, $projectRoot);

        if ($format === 'json') {
            $this->emitJson($output, $summary, $results);
        } else {
            $this->emitTable($io, $summary, $results);
        }

        $fails = match ($failOn) {
            'any' => $summary['hits']['total'] > 0,
            'strong' => ($summary['hits']['byIndicator']['strong'] ?? 0) > 0,
            'none' => false,
        };
        return $fails ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @param list<string> $paths
     * @return list<string>
     */
    private function resolvePaths(array $paths): array
    {
        $resolved = [];
        foreach ($paths as $path) {
            $real = realpath($path);
            if ($real !== false && (is_dir($real) || is_file($real))) {
                $resolved[] = $real;
            }
        }
        return $resolved;
    }

    /**
     * @param list<string> $paths
     * @param list<string> $excludes
     * @return list<string>
     */
    private function collectFiles(array $paths, array $excludes): array
    {
        $directFiles = [];
        $directories = [];
        foreach ($paths as $path) {
            if (is_file($path)) {
                $directFiles[] = $path;
            } else {
                $directories[] = $path;
            }
        }

        $files = $directFiles;
        if ($directories !== []) {
            $finder = (new Finder())
                ->files()
                ->name('*.php')
                ->in($directories)
                ->exclude($excludes)
                ->ignoreUnreadableDirs()
                ->sortByName();
            foreach ($finder as $file) {
                $files[] = $file->getPathname();
            }
        }
        return array_values(array_unique($files));
    }

    /**
     * @param list<FileScanResult> $results
     * @param list<string> $scannedPaths
     * @return array{filesScanned:int,filesWithHits:int,filesIgnored:int,filesWithParseErrors:int,filesWithScanErrors:int,hits:array{total:int,byIndicator:array<string,int>},scannedPaths:list<string>,projectRoot:string,note:string}
     */
    private function buildSummary(array $results, array $scannedPaths, string $projectRoot): array
    {
        $byIndicator = [];
        $totalHits = 0;
        $filesWithHits = 0;
        $filesIgnored = 0;
        $filesWithParseErrors = 0;
        $filesWithScanErrors = 0;

        foreach ($results as $result) {
            if ($result->parseError !== null) {
                $filesWithParseErrors++;
                continue;
            }
            if ($result->scanError !== null) {
                $filesWithScanErrors++;
                continue;
            }
            if ($result->isFileIgnored) {
                $filesIgnored++;
            }
            if ($result->hits !== []) {
                $filesWithHits++;
            }
            foreach ($result->hits as $hit) {
                $totalHits++;
                $byIndicator[$hit->indicator] = ($byIndicator[$hit->indicator] ?? 0) + 1;
            }
        }
        ksort($byIndicator);

        return [
            'filesScanned' => count($results),
            'filesWithHits' => $filesWithHits,
            'filesIgnored' => $filesIgnored,
            'filesWithParseErrors' => $filesWithParseErrors,
            'filesWithScanErrors' => $filesWithScanErrors,
            'hits' => [
                'total' => $totalHits,
                'byIndicator' => $byIndicator,
            ],
            'scannedPaths' => array_map(
                static fn (string $p): string => str_starts_with($p, $projectRoot) ? substr($p, strlen($projectRoot) + 1) : $p,
                $scannedPaths,
            ),
            'projectRoot' => $projectRoot,
            'note' => self::NOTE,
        ];
    }

    /**
     * @param array<string,mixed> $summary
     * @param list<FileScanResult> $results
     */
    private function emitJson(OutputInterface $output, array $summary, array $results): void
    {
        $payload = [
            'summary' => $summary,
            'results' => array_values(array_map(
                static fn (FileScanResult $r): array => [
                    'file' => $r->relativeFilePath,
                    'absoluteFile' => $r->absoluteFilePath,
                    'isFileIgnored' => $r->isFileIgnored,
                    'effectiveCodeLines' => $r->effectiveCodeLines,
                    'ignoredLines' => $r->ignoredLines,
                    'parseError' => $r->parseError,
                    'scanError' => $r->scanError,
                    'hits' => array_map(static fn ($h) => $h->toArray(), $r->hits),
                ],
                array_values(array_filter($results, static fn (FileScanResult $r): bool => !$r->isClean())),
            )),
        ];
        $output->writeln(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * @param array<string,mixed> $summary
     * @param list<FileScanResult> $results
     */
    private function emitTable(SymfonyStyle $io, array $summary, array $results): void
    {
        $io->title('TYPO3 Core Extension Scanner (CLI)');
        $io->note(self::NOTE);

        $rows = [];
        foreach ($results as $result) {
            if ($result->parseError !== null) {
                $rows[] = [$result->relativeFilePath, '-', 'parse-error', '-', $result->parseError, ''];
                continue;
            }
            if ($result->scanError !== null) {
                $rows[] = [$result->relativeFilePath, '-', 'scan-error', '-', $result->scanError, ''];
                continue;
            }
            foreach ($result->hits as $hit) {
                $rows[] = [
                    $hit->relativeFilePath,
                    (string)$hit->line,
                    $hit->indicator,
                    $hit->matcher,
                    $hit->message,
                    implode(', ', $hit->restFiles),
                ];
            }
        }

        if ($rows === []) {
            $io->success('No matches found.');
        } else {
            $table = new Table($io);
            $table->setHeaders(['File', 'Line', 'Indicator', 'Matcher', 'Message', 'reST']);
            $table->setRows($rows);
            $table->render();
            $io->newLine();
        }

        $io->section('Summary');
        $io->definitionList(
            ['Files scanned' => (string)$summary['filesScanned']],
            ['Files with hits' => (string)$summary['filesWithHits']],
            ['Files fully ignored' => (string)$summary['filesIgnored']],
            ['Files with parse errors' => (string)$summary['filesWithParseErrors']],
            ['Files with scan errors' => (string)$summary['filesWithScanErrors']],
            ['Total hits' => (string)$summary['hits']['total']],
        );
        if ($summary['hits']['byIndicator'] !== []) {
            foreach ($summary['hits']['byIndicator'] as $indicator => $count) {
                $io->writeln(sprintf('  - %s: %d', $indicator, $count));
            }
        }
    }
}
