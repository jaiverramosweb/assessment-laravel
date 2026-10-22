<?php

namespace App\Services\Ai\Analysis;

use App\Services\Ai\AiStore;

/**
 * Model output schema and its normalization (port of `aiOutputSchema` / `normalizeAnalysis`
 * in resources/js/lib/ai-analysis.ts): lengths are cut instead of failing.
 */
final class AnalysisNormalizer
{
    /**
     * JSON schema sent as `output_config.format.schema`: the exact object the original SDK helper
     * (`betaZodOutputFormat(aiOutputSchema)`) produced.
     */
    public static function outputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'summary' => ['type' => 'string'],
                'themes' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'frequency' => ['type' => 'string', 'description' => '{enum: ["alta","media","baja"]}'],
                            'approxCount' => ['type' => 'integer', 'description' => '{minimum: -9007199254740991, maximum: 9007199254740991}'],
                        ],
                        'additionalProperties' => false,
                        'required' => ['name', 'description', 'frequency', 'approxCount'],
                    ],
                ],
                'highlights' => ['type' => 'array', 'items' => ['type' => 'string']],
                'concerns' => ['type' => 'array', 'items' => ['type' => 'string']],
                'quotes' => ['type' => 'array', 'items' => ['type' => 'string']],
            ],
            'additionalProperties' => false,
            'required' => ['summary', 'themes', 'highlights', 'concerns', 'quotes'],
            'description' => '{$schema: "https://json-schema.org/draft/2020-12/schema"}',
        ];
    }

    /** Fits the model output to the stored limits, or null when it does not match the output schema. */
    public static function normalize(mixed $raw): ?array
    {
        if (! self::matchesOutputSchema($raw)) {
            return null;
        }
        $result = [
            'summary' => self::orDefault(self::cut(self::trim($raw['summary']), 4000), 'Sin resumen.'),
            'themes' => array_map(fn ($t) => [
                'name' => self::orDefault(self::cut(self::trim($t['name']), 200), 'Tema'),
                'description' => self::cut(self::trim($t['description']), 1000),
                'frequency' => $t['frequency'],
                'approxCount' => max(0, (int) round($t['approxCount'])),
            ], array_slice($raw['themes'], 0, 15)),
            'highlights' => array_map(fn ($s) => self::cut($s, 600), array_slice($raw['highlights'], 0, 15)),
            'concerns' => array_map(fn ($s) => self::cut($s, 600), array_slice($raw['concerns'], 0, 15)),
            'quotes' => array_map(fn ($s) => self::cut($s, 400), array_slice($raw['quotes'], 0, 12)),
        ];

        return AiStore::isValidResult($result) ? $result : null;
    }

    private static function matchesOutputSchema(mixed $raw): bool
    {
        if (! is_array($raw) || ! is_string($raw['summary'] ?? null)) {
            return false;
        }
        foreach (['themes', 'highlights', 'concerns', 'quotes'] as $key) {
            if (! is_array($raw[$key] ?? null) || ! array_is_list($raw[$key])) {
                return false;
            }
        }
        foreach ($raw['themes'] as $t) {
            if (! is_array($t)) {
                return false;
            }
            $count = $t['approxCount'] ?? null;
            if ( ! is_string($t['name'] ?? null)
                || ! is_string($t['description'] ?? null)
                || ! in_array($t['frequency'] ?? null, AiStore::FREQUENCIES, true)
                || ! (is_int($count) || (is_float($count) && floor($count) === $count))) {
                return false;
            }
        }
        foreach (['highlights', 'concerns', 'quotes'] as $key) {
            foreach ($raw[$key] as $s) {
                if (! is_string($s)) {
                    return false;
                }
            }
        }

        return true;
    }

    private static function orDefault(string $s, string $default): string
    {
        return $s === '' ? $default : $s;
    }

    private static function cut(string $s, int $n): string
    {
        return mb_strlen($s) > $n ? self::trimEnd(mb_substr($s, 0, $n - 1)).'…' : $s;
    }

    /** Like JS `String.prototype.trim` (Unicode white space). */
    private static function trim(string $s): string
    {
        return preg_replace('/^[\s\p{Z}\x{FEFF}]+|[\s\p{Z}\x{FEFF}]+$/u', '', $s) ?? $s;
    }

    private static function trimEnd(string $s): string
    {
        return preg_replace('/[\s\p{Z}\x{FEFF}]+$/u', '', $s) ?? $s;
    }

    /** Unicode-aware trim shared with the analysis service. */
    public static function trimText(string $s): string
    {
        return self::trim($s);
    }
}
