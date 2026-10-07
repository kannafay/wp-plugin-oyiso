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

final class Oyiso_Variation_Image_Matcher
{
    public static function normalize(string $value): string
    {
        $value = remove_accents(html_entity_decode(rawurldecode($value), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
        // Treat Love66 and Love 66 alike while retaining complete word and number boundaries.
        $value = preg_replace('/(?<=\p{L})(?=\p{N})|(?<=\p{N})(?=\p{L})/u', ' ', $value) ?? '';

        return trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $value) ?? '');
    }

    /**
     * Match complete attribute phrases, preferring a specific phrase such as
     * "blue mint" over its "mint" suffix. Independent values in one filename
     * remain ambiguous and require an explicit choice in the preview.
     *
     * @param array<string, list<string>> $values Attribute value => label/slug aliases.
     * @param list<Oyiso_Variation_Image_Candidate> $images Images in product gallery order.
     * @return array<string, array{matches: list<Oyiso_Variation_Image_Candidate>, ambiguous: list<Oyiso_Variation_Image_Candidate>}>
     */
    public static function match(array $values, array $images): array
    {
        $aliases = [];
        $results = [];
        foreach ($values as $key => $labels) {
            $aliases[$key] = array_values(array_unique(array_filter(array_map(self::normalize(...), $labels), static fn(string $alias): bool => $alias !== '')));
            $results[$key] = ['matches' => [], 'ambiguous' => []];
        }

        foreach ($images as $image) {
            $filename = self::normalize(pathinfo($image->filename, PATHINFO_FILENAME));
            $hits = [];
            foreach ($aliases as $key => $phrases) {
                foreach ($phrases as $phrase) {
                    if (self::contains($filename, $phrase)) {
                        $hits[$key][] = $phrase;
                    }
                }
            }

            $specific = [];
            foreach ($hits as $key => $phrases) {
                foreach ($phrases as $phrase) {
                    $covered = false;
                    foreach ($hits as $otherKey => $otherPhrases) {
                        if ($otherKey === $key) {
                            continue;
                        }
                        foreach ($otherPhrases as $otherPhrase) {
                            if ($otherPhrase !== $phrase && self::contains($otherPhrase, $phrase)) {
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

    private static function contains(string $filename, string $phrase): bool
    {
        return $phrase !== '' && str_contains(' ' . $filename . ' ', ' ' . $phrase . ' ');
    }
}
