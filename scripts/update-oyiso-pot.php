<?php
declare(strict_types=1);

const OYISO_I18N_DOMAIN = 'oyiso';
const OYISO_SOURCE_PATHS = [
    'oyiso.php',
    'src',
];

const OYISO_POT_PATH = 'languages/oyiso.pot';

if (realpath($argv[0] ?? '') === __FILE__) {
    main($argv);
}

function main(array $argv): void
{
    $root = dirname(__DIR__);
    $options = parse_cli_options($argv);

    if ($options['check'] && ($options['sync'] || $options['prune'])) {
        throw new InvalidArgumentException('The --check option cannot be used together with --sync or --prune.');
    }

    $phpFiles = collect_php_files($root, OYISO_SOURCE_PATHS);
    $entries = extract_entries($phpFiles, $root);
    $potPath = $root . DIRECTORY_SEPARATOR . OYISO_POT_PATH;

    if (!$options['check']) {
        write_pot_file($potPath, $entries);
        fwrite(STDOUT, sprintf("Updated %s with %d entries.\n", relative_path($root, $potPath), count($entries)));
    } else {
        fwrite(STDOUT, sprintf("Checked %d extracted entries without writing %s.\n", count($entries), relative_path($root, $potPath)));
    }

    $localeFiles = glob($root . DIRECTORY_SEPARATOR . 'languages' . DIRECTORY_SEPARATOR . 'oyiso-*.po') ?: [];
    sort($localeFiles, SORT_STRING);

    if ($options['sync']) {
        foreach ($localeFiles as $localeFile) {
            $addedCount = sync_locale_with_entries($localeFile, $entries);

            if ($addedCount > 0) {
                fwrite(
                    STDOUT,
                    sprintf(
                        "[%s] synced %d missing entries\n",
                        basename($localeFile),
                        $addedCount
                    )
                );
            }
        }
    }

    if ($options['prune']) {
        foreach ($localeFiles as $localeFile) {
            $removedCount = prune_locale_with_entries($localeFile, $entries);

            if ($removedCount > 0) {
                fwrite(
                    STDOUT,
                    sprintf(
                        "[%s] pruned %d extra entries\n",
                        basename($localeFile),
                        $removedCount
                    )
                );
            }
        }
    }

    $hasBlockingIssues = false;

    foreach ($localeFiles as $localeFile) {
        $comparison = compare_locale_with_entries($localeFile, $entries);
        $missingCount = count($comparison['missing']);
        $extraCount = count($comparison['extra']);
        $emptyCount = count($comparison['empty']);
        $fuzzyCount = count($comparison['fuzzy']);

        fwrite(
            STDOUT,
            sprintf(
                "[%s] missing=%d extra=%d empty=%d fuzzy=%d\n",
                basename($localeFile),
                $missingCount,
                $extraCount,
                $emptyCount,
                $fuzzyCount
            )
        );

        if ($missingCount > 0 || $emptyCount > 0 || $fuzzyCount > 0) {
            $hasBlockingIssues = true;
        }
    }

    if ($options['strict'] && $hasBlockingIssues) {
        exit(1);
    }
}

function parse_cli_options(array $argv): array
{
    $options = [
        'check' => false,
        'prune' => false,
        'strict' => false,
        'sync' => false,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--check') {
            $options['check'] = true;
            continue;
        }

        if ($arg === '--strict') {
            $options['strict'] = true;
            continue;
        }

        if ($arg === '--sync') {
            $options['sync'] = true;
            continue;
        }

        if ($arg === '--prune') {
            $options['prune'] = true;
            continue;
        }

        throw new InvalidArgumentException(sprintf('Unknown option: %s', $arg));
    }

    return $options;
}

/**
 * @param list<string> $paths
 * @return list<string>
 */
function collect_php_files(string $root, array $paths): array
{
    $files = [];

    foreach ($paths as $path) {
        $absolutePath = $root . DIRECTORY_SEPARATOR . $path;

        if (is_file($absolutePath)) {
            if (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION)) === 'php') {
                $files[] = $absolutePath;
            }
            continue;
        }

        if (!is_dir($absolutePath)) {
            continue;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($absolutePath, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $fileInfo) {
            if (!$fileInfo instanceof SplFileInfo || !$fileInfo->isFile()) {
                continue;
            }

            if (strtolower($fileInfo->getExtension()) !== 'php') {
                continue;
            }

            $files[] = $fileInfo->getPathname();
        }
    }

    sort($files, SORT_STRING);

    return array_values(array_unique($files));
}

/**
 * @param list<string> $phpFiles
 * @return array<string, list<string>>
 */
function extract_entries(array $phpFiles, string $root): array
{
    $entries = [];

    foreach ($phpFiles as $file) {
        $code = file_get_contents($file);

        if ($code === false) {
            throw new RuntimeException(sprintf('Unable to read file: %s', $file));
        }

        try {
            $tokens = token_get_all($code, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new RuntimeException(sprintf('Failed to parse %s: %s', $file, $error->getMessage()), 0, $error);
        }

        $tokenCount = count($tokens);

        for ($index = 0; $index < $tokenCount; $index++) {
            $token = $tokens[$index];

            if (!is_array($token) || $token[0] !== T_STRING) {
                continue;
            }

            $functionName = strtolower($token[1]);
            $referenceLine = (int) $token[2];
            $openParenIndex = next_non_whitespace_index($tokens, $index + 1);

            if ($openParenIndex === null || token_text($tokens[$openParenIndex]) !== '(') {
                continue;
            }

            if (!is_supported_translation_function($functionName)) {
                continue;
            }

            [$arguments, $endIndex] = parse_call_arguments($tokens, $openParenIndex);
            $message = extract_message_from_call($functionName, $arguments);

            if ($message === null || $message === '') {
                $index = $endIndex;
                continue;
            }

            $reference = relative_path($root, $file) . ':' . $referenceLine;
            $entries[$message] ??= [];

            if (!in_array($reference, $entries[$message], true)) {
                $entries[$message][] = $reference;
            }

            $index = $endIndex;
        }
    }

    ksort($entries, SORT_STRING);

    foreach ($entries as &$references) {
        sort($references, SORT_STRING);
    }
    unset($references);

    return $entries;
}

function is_supported_translation_function(string $functionName): bool
{
    static $functions = [
        'oyiso_editor_t' => true,
        'oyiso_t' => true,
        'oyiso_t_sprintf' => true,
    ];

    return isset($functions[$functionName]);
}

/**
 * @param list<mixed> $tokens
 * @return array{0: list<list<mixed>>, 1: int}
 */
function parse_call_arguments(array $tokens, int $openParenIndex): array
{
    $arguments = [];
    $current = [];
    $depth = 0;
    $tokenCount = count($tokens);

    for ($index = $openParenIndex + 1; $index < $tokenCount; $index++) {
        $text = token_text($tokens[$index]);

        if ($text === '(' || $text === '[' || $text === '{') {
            $depth++;
            $current[] = $tokens[$index];
            continue;
        }

        if ($text === ')' && $depth === 0) {
            $arguments[] = $current;
            return [$arguments, $index];
        }

        if (($text === ')' || $text === ']' || $text === '}') && $depth > 0) {
            $depth--;
            $current[] = $tokens[$index];
            continue;
        }

        if ($text === ',' && $depth === 0) {
            $arguments[] = $current;
            $current = [];
            continue;
        }

        $current[] = $tokens[$index];
    }

    throw new RuntimeException('Unclosed function call while parsing translation arguments.');
}

/**
 * @param list<list<mixed>> $arguments
 */
function extract_message_from_call(string $functionName, array $arguments): ?string
{
    if (in_array($functionName, ['oyiso_t', 'oyiso_t_sprintf', 'oyiso_editor_t'], true)) {
        return resolve_string_argument($arguments[0] ?? []);
    }

    return null;
}

/**
 * @param list<mixed> $argumentTokens
 */
function resolve_string_argument(array $argumentTokens): ?string
{
    $filtered = [];

    foreach ($argumentTokens as $token) {
        if (is_array($token) && in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            continue;
        }

        $filtered[] = $token;
    }

    if ($filtered === []) {
        return null;
    }

    $result = '';
    $expectLiteral = true;

    foreach ($filtered as $token) {
        $text = token_text($token);

        if ($expectLiteral) {
            if (!is_array($token) || $token[0] !== T_CONSTANT_ENCAPSED_STRING) {
                return null;
            }

            $result .= decode_php_string_literal($text);
            $expectLiteral = false;
            continue;
        }

        if ($text !== '.') {
            return null;
        }

        $expectLiteral = true;
    }

    return $expectLiteral ? null : $result;
}

function decode_php_string_literal(string $literal): string
{
    $quote = $literal[0] ?? '';
    $body = substr($literal, 1, -1);

    if ($quote === "'") {
        return str_replace(["\\\\", "\\'"], ["\\", "'"], $body);
    }

    return stripcslashes($body);
}

function write_pot_file(string $potPath, array $entries): void
{
    $timestamp = (new DateTimeImmutable('now', new DateTimeZone(date_default_timezone_get() ?: 'UTC')))
        ->format('Y-m-d H:iO');

    $lines = [
        'msgid ""',
        'msgstr ""',
        '"Project-Id-Version: Oyiso\n"',
        '"POT-Creation-Date: ' . $timestamp . '\n"',
        '"MIME-Version: 1.0\n"',
        '"Content-Type: text/plain; charset=UTF-8\n"',
        '"Content-Transfer-Encoding: 8bit\n"',
        '"X-Domain: ' . OYISO_I18N_DOMAIN . '\n"',
        '"X-Generator: scripts/update-oyiso-pot.php\n"',
        '',
    ];

    foreach ($entries as $message => $references) {
        if ($references !== []) {
            $lines[] = '#: ' . implode(' ', $references);
        }

        array_push($lines, ...format_po_string('msgid', $message));
        $lines[] = 'msgstr ""';
        $lines[] = '';
    }

    $content = implode(PHP_EOL, $lines);

    if (file_put_contents($potPath, $content . PHP_EOL) === false) {
        throw new RuntimeException(sprintf('Unable to write POT file: %s', $potPath));
    }
}

/**
 * @return list<string>
 */
function format_po_string(string $keyword, string $value): array
{
    $normalized = str_replace(["\r\n", "\r"], "\n", $value);

    if (!str_contains($normalized, "\n")) {
        return [$keyword . ' "' . escape_po_text($normalized) . '"'];
    }

    $lines = [$keyword . ' ""'];
    $parts = explode("\n", $normalized);
    $lastIndex = count($parts) - 1;

    foreach ($parts as $index => $part) {
        $suffix = $index < $lastIndex ? '\n' : '';
        $lines[] = '"' . escape_po_text($part . $suffix) . '"';
    }

    return $lines;
}

function escape_po_text(string $text): string
{
    return addcslashes($text, "\\\"\t");
}

function next_non_whitespace_index(array $tokens, int $startIndex): ?int
{
    $tokenCount = count($tokens);

    for ($index = $startIndex; $index < $tokenCount; $index++) {
        $token = $tokens[$index];

        if (is_array($token) && $token[0] === T_WHITESPACE) {
            continue;
        }

        return $index;
    }

    return null;
}

function token_text(mixed $token): string
{
    return is_array($token) ? $token[1] : $token;
}

function relative_path(string $root, string $path): string
{
    $normalizedRoot = str_replace('\\', '/', rtrim($root, '\\/'));
    $normalizedPath = str_replace('\\', '/', $path);

    if (str_starts_with($normalizedPath, $normalizedRoot . '/')) {
        return substr($normalizedPath, strlen($normalizedRoot) + 1);
    }

    return $normalizedPath;
}

/**
 * @return array{missing: list<string>, extra: list<string>, empty: list<string>, fuzzy: list<string>}
 */
function compare_locale_with_entries(string $localeFile, array $entries): array
{
    $localeEntries = parse_locale_entries($localeFile);
    $potIds = array_keys($entries);
    $localeIds = array_keys($localeEntries['translations']);

    sort($potIds, SORT_STRING);
    sort($localeIds, SORT_STRING);

    return [
        'missing' => array_values(array_diff($potIds, $localeIds)),
        'extra' => array_values(array_diff($localeIds, $potIds)),
        'empty' => $localeEntries['empty'],
        'fuzzy' => $localeEntries['fuzzy'],
    ];
}

function sync_locale_with_entries(string $localeFile, array $entries): int
{
    $comparison = compare_locale_with_entries($localeFile, $entries);
    $missing = $comparison['missing'];

    if ($missing === []) {
        return 0;
    }

    $content = file_get_contents($localeFile);

    if ($content === false) {
        throw new RuntimeException(sprintf('Unable to read locale file: %s', $localeFile));
    }

    $blocks = [];

    foreach ($missing as $msgid) {
        $references = $entries[$msgid] ?? [];

        if ($references !== []) {
            $blocks[] = '#: ' . implode(' ', $references);
        }

        array_push($blocks, ...format_po_string('msgid', $msgid));
        $blocks[] = 'msgstr ""';
        $blocks[] = '';
    }

    $existingContent = rtrim($content, "\r\n");
    $appendedContent = implode(PHP_EOL, $blocks);

    if ($existingContent === '') {
        $newContent = $appendedContent . PHP_EOL;
    } else {
        $newContent = $existingContent . PHP_EOL . PHP_EOL . $appendedContent . PHP_EOL;
    }

    if (file_put_contents($localeFile, $newContent) === false) {
        throw new RuntimeException(sprintf('Unable to write locale file: %s', $localeFile));
    }

    return count($missing);
}

function prune_locale_with_entries(string $localeFile, array $entries): int
{
    $blocks = parse_locale_blocks($localeFile);
    $retainedBlocks = [];
    $removedCount = 0;

    foreach ($blocks as $block) {
        $msgid = $block['msgid'];

        if ($msgid !== null && $msgid !== '' && !isset($entries[$msgid])) {
            $removedCount++;
            continue;
        }

        $retainedBlocks[] = $block['lines'];
    }

    if ($removedCount === 0) {
        return 0;
    }

    $renderedBlocks = array_map(
        static fn(array $lines): string => implode(PHP_EOL, $lines),
        array_filter($retainedBlocks, static fn(array $lines): bool => $lines !== [])
    );

    $content = implode(PHP_EOL . PHP_EOL, $renderedBlocks);

    if ($content !== '') {
        $content .= PHP_EOL;
    }

    if (file_put_contents($localeFile, $content) === false) {
        throw new RuntimeException(sprintf('Unable to write locale file: %s', $localeFile));
    }

    return $removedCount;
}

/**
 * @return list<array{lines: list<string>, msgid: ?string}>
 */
function parse_locale_blocks(string $file): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException(sprintf('Unable to read locale file: %s', $file));
    }

    $blocks = [];
    $blockLines = [];
    $msgid = null;
    $state = null;

    $finalize = static function () use (&$blocks, &$blockLines, &$msgid, &$state): void {
        if ($blockLines === []) {
            $msgid = null;
            $state = null;
            return;
        }

        $blocks[] = [
            'lines' => $blockLines,
            'msgid' => $msgid,
        ];

        $blockLines = [];
        $msgid = null;
        $state = null;
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $finalize();
            continue;
        }

        $blockLines[] = $line;

        if (str_starts_with($trimmed, 'msgid ')) {
            $state = 'msgid';
            $msgid = decode_php_string_literal(substr($trimmed, 6));
            continue;
        }

        if (str_starts_with($trimmed, 'msgstr ')) {
            $state = 'msgstr';
            continue;
        }

        if ($trimmed[0] === '"' && $state === 'msgid' && $msgid !== null) {
            $msgid .= decode_php_string_literal($trimmed);
        }
    }

    $finalize();

    return $blocks;
}

/**
 * @return array{translations: array<string, string>, empty: list<string>, fuzzy: list<string>}
 */
function parse_locale_entries(string $file): array
{
    $lines = file($file, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        throw new RuntimeException(sprintf('Unable to read locale file: %s', $file));
    }

    $translations = [];
    $empty = [];
    $fuzzy = [];
    $msgid = null;
    $msgstr = null;
    $state = null;
    $isFuzzy = false;

    $finalize = static function () use (&$translations, &$empty, &$fuzzy, &$msgid, &$msgstr, &$state, &$isFuzzy): void {
        if ($msgid === null) {
            $state = null;
            $isFuzzy = false;
            return;
        }

        if ($msgid !== '') {
            $translations[$msgid] = $msgstr ?? '';

            if ($isFuzzy) {
                $fuzzy[] = $msgid;
            }

            if (($msgstr ?? '') === '') {
                $empty[] = $msgid;
            }
        }

        $msgid = null;
        $msgstr = null;
        $state = null;
        $isFuzzy = false;
    };

    foreach ($lines as $line) {
        $trimmed = trim($line);

        if ($trimmed === '') {
            $finalize();
            continue;
        }

        if (str_starts_with($trimmed, '#,')) {
            if (stripos($trimmed, 'fuzzy') !== false) {
                $isFuzzy = true;
            }
            continue;
        }

        if ($trimmed[0] === '#') {
            continue;
        }

        if (str_starts_with($trimmed, 'msgid ')) {
            $finalize();
            $state = 'msgid';
            $msgid = decode_php_string_literal(substr($trimmed, 6));
            $msgstr = '';
            continue;
        }

        if (str_starts_with($trimmed, 'msgstr ')) {
            $state = 'msgstr';
            $msgstr = decode_php_string_literal(substr($trimmed, 7));
            continue;
        }

        if ($trimmed[0] === '"') {
            $value = decode_php_string_literal($trimmed);

            if ($state === 'msgid') {
                $msgid .= $value;
            } elseif ($state === 'msgstr') {
                $msgstr .= $value;
            }
        }
    }

    $finalize();

    sort($empty, SORT_STRING);
    sort($fuzzy, SORT_STRING);

    return [
        'translations' => $translations,
        'empty' => $empty,
        'fuzzy' => $fuzzy,
    ];
}
