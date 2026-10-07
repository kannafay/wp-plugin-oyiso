<?php

declare(strict_types=1);

defined('ABSPATH') || exit;

final readonly class Oyiso_Variation_Image_Candidate
{
    public function __construct(
        public int $id,
        public string $filename,
        public string $url,
    ) {
    }
}

/**
 * @phpstan-type FlavorMatch array{start: int, phrase: string}
 * @phpstan-type FlavorSlot array{phrase: string, starts: list<int>}
 */
final class Oyiso_Variation_Image_Matcher
{
    public static function normalize(string $value): string
    {
        return self::normalizePhrase(html_entity_decode(rawurldecode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }

    /**
     * Explicit flavor separators allow an unordered combination of complete
     * phrases. Prefer the more complete combination; independent or identical
     * combinations in one filename still require an explicit choice.
     *
     * @param array<string, list<string>> $values Attribute value => label/slug aliases.
     * @param list<Oyiso_Variation_Image_Candidate> $images Images in product gallery order.
     * @return array<string, array{matches: list<Oyiso_Variation_Image_Candidate>, ambiguous: list<Oyiso_Variation_Image_Candidate>}>
     */
    public static function match(array $values, array $images, bool $multiFlavor = false, string $separator = ''): array
    {
        $aliases = [];
        $results = [];
        foreach ($values as $key => $labels) {
            $aliases[$key] = [];
            foreach ($labels as $label) {
                $flavors = self::splitFlavors($label, $multiFlavor, $separator);
                if ($flavors !== [] && !in_array('', $flavors, true) && !in_array($flavors, $aliases[$key], true)) {
                    $aliases[$key][] = $flavors;
                }
            }
            $results[$key] = ['matches' => [], 'ambiguous' => []];
        }

        foreach ($images as $image) {
            $filename = self::normalize(pathinfo($image->filename, PATHINFO_FILENAME));
            $hits = [];
            foreach ($aliases as $key => $groups) {
                foreach ($groups as $flavors) {
                    $ordered = self::findFlavors($filename, $flavors);
                    if ($ordered !== null) {
                        $hits[$key][] = ['phrase' => implode(' ', $ordered), 'flavors' => $ordered];
                    }
                }
            }

            $specific = [];
            foreach ($hits as $key => $matches) {
                foreach ($matches as $match) {
                    $covered = false;
                    foreach ($hits as $otherKey => $otherMatches) {
                        if ($otherKey === $key) {
                            continue;
                        }
                        foreach ($otherMatches as $otherMatch) {
                            if ($otherMatch['phrase'] !== $match['phrase'] && self::findFlavors($otherMatch['phrase'], $match['flavors']) !== null) {
                                $covered = true;
                                break 2;
                            }
                        }
                    }
                    if (!$covered) {
                        $specific[$key] = true;
                        break;
                    }
                }
            }

            foreach ($results as $key => $result) {
                if (!isset($specific[$key])) {
                    continue;
                }
                if (count($specific) === 1) {
                    $result['matches'][] = $image;
                } else {
                    $result['ambiguous'][] = $image;
                }
                $results[$key] = $result;
            }
        }

        return $results;
    }

    private static function normalizePhrase(string $value): string
    {
        $value = remove_accents($value);
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        // Treat Love66 and Love 66 alike while retaining complete word and number boundaries.
        $value = preg_replace('/(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', ' ', $value) ?? '';

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '');
    }

    /** @return list<string> */
    private static function splitFlavors(string $value, bool $multiFlavor, string $separator): array
    {
        if (!$multiFlavor) {
            return [self::normalize($value)];
        }
        $value = html_entity_decode(rawurldecode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $separator = trim($separator);
        // Spaces and hyphens remain inside a flavor name, such as Mango Peach.
        $parts = $separator !== '' ? explode($separator, $value) : (preg_split('~[/／|｜+＋,，;；、]~u', $value) ?: []);

        return array_map(self::normalizePhrase(...), $parts);
    }

    /**
     * Return flavors in filename order so equivalent aliases remain ambiguous.
     * Each flavor uses separate words; overlapping names cannot share a match.
     *
     * @param list<string> $flavors
     * @return list<string>|null
     */
    private static function findFlavors(string $filename, array $flavors): ?array
    {
        if (count($flavors) === 1) {
            return self::contains($filename, $flavors[0]) ? $flavors : null;
        }
        usort($flavors, static fn(string $a, string $b): int => (strlen($b) <=> strlen($a)) ?: strcmp($a, $b));
        $required = array_count_values($flavors);
        $slots = [];
        $filename = ' ' . $filename . ' ';
        foreach ($flavors as $flavor) {
            $starts = [];
            $offset = 0;
            while (($position = strpos($filename, ' ' . $flavor . ' ', $offset)) !== false) {
                $starts[] = $position + 1;
                $offset = $position + 1;
            }
            if (count($starts) < $required[$flavor]) {
                return null;
            }
            $slots[] = ['phrase' => $flavor, 'starts' => $starts];
        }
        $matches = self::placeFlavors($slots, []);
        if ($matches === null) {
            return null;
        }
        usort($matches, static fn(array $a, array $b): int => $a['start'] <=> $b['start']);

        return array_column($matches, 'phrase');
    }

    /**
     * Try alternate occurrences when a short flavor overlaps a longer name.
     * Repeated identical flavors are placed from left to right to avoid permutations.
     *
     * @param list<FlavorSlot> $slots
     * @param list<FlavorMatch> $matches
     * @return list<FlavorMatch>|null
     */
    private static function placeFlavors(array $slots, array $matches): ?array
    {
        $index = count($matches);
        if ($index === count($slots)) {
            return $matches;
        }
        $slot = $slots[$index];
        foreach ($slot['starts'] as $start) {
            if ($index > 0 && $slots[$index - 1]['phrase'] === $slot['phrase'] && $start <= $matches[$index - 1]['start']) {
                continue;
            }
            foreach ($matches as $match) {
                if ($start < $match['start'] + strlen($match['phrase']) && $start + strlen($slot['phrase']) > $match['start']) {
                    continue 2;
                }
            }
            $placed = self::placeFlavors($slots, [...$matches, ['start' => $start, 'phrase' => $slot['phrase']]]);
            if ($placed !== null) {
                return $placed;
            }
        }

        return null;
    }

    private static function contains(string $filename, string $phrase): bool
    {
        return $phrase !== '' && str_contains(' ' . $filename . ' ', ' ' . $phrase . ' ');
    }
}
