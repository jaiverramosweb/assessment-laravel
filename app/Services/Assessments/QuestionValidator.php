<?php

namespace App\Services\Assessments;

/**
 * Validation of a question list (port of src/lib/schemas/question.ts). Issues are collected in
 * the same order as the original zod schema and turned into readable messages, e.g.
 * "Pregunta 3, opción 2: Hay una opción vacía." (`rowLabel` = "Fila" for the Excel import).
 */
final class QuestionValidator
{
    public const MIN_OPTIONS = 2;

    public const MAX_OPTIONS = 6;

    public const MAX_QUESTIONS = 200;

    /**
     * @return array{ok: true, questions: array}|array{ok: false, errors: string[]}
     */
    public static function validateList(mixed $payload, string $rowLabel = 'Pregunta', int $rowOffset = 1): array
    {
        if (! is_array($payload) || ! array_is_list($payload)) {
            return ['ok' => false, 'errors' => ['Invalid input: expected array']];
        }

        /** @var array<int, array{0: ?int, 1: ?int, 2: string}> $issues [question index, option index, message] */
        $issues = [];
        $questions = [];
        foreach ($payload as $i => $item) {
            $questions[] = self::question($item, $i, $issues);
        }
        if (count($payload) > self::MAX_QUESTIONS) {
            $issues[] = [null, null, 'Máximo '.self::MAX_QUESTIONS.' preguntas por assessment.'];
        }

        if ($issues) {
            $messages = array_map(function ($issue) use ($rowLabel, $rowOffset) {
                [$index, $optIndex, $message] = $issue;
                if ($index === null) {
                    return $message;
                }
                $where = $rowLabel.' '.($index + $rowOffset);
                if ($optIndex !== null) {
                    $where .= ', opción '.($optIndex + 1);
                }

                return "{$where}: {$message}";
            }, $issues);

            return ['ok' => false, 'errors' => array_values(array_unique($messages))];
        }

        return ['ok' => true, 'questions' => $questions];
    }

    private static function question(mixed $item, int $i, array &$issues): ?array
    {
        $type = is_array($item) ? ($item['type'] ?? null) : null;
        if (! in_array($type, ['opcion_unica', 'abierta'], true)) {
            $issues[] = [$i, null, 'Tipo de pregunta no válido.'];

            return null;
        }

        $prompt = self::text($item['prompt'] ?? null, $i, null, $issues, 2000, [
            'type' => 'Escriba el enunciado.',
            'empty' => 'Escriba el enunciado.',
            'long' => 'El enunciado no puede superar 2.000 caracteres.',
        ]);
        $required = $item['required'] ?? null;
        if (! is_bool($required)) {
            $issues[] = [$i, null, 'Invalid input: expected boolean, received '.self::typeName($required)];
        }

        if ($type === 'abierta') {
            return ['type' => 'abierta', 'prompt' => $prompt, 'required' => $required];
        }

        $options = $item['options'] ?? null;
        if (! is_array($options) || ! array_is_list($options)) {
            $issues[] = [$i, null, 'Invalid input: expected array, received '.self::typeName($options)];

            return null;
        }

        $parsed = [];
        $typeErrors = false;
        foreach ($options as $j => $o) {
            if (! is_array($o)) {
                $issues[] = [$i, $j, 'Invalid input: expected object, received '.self::typeName($o)];
                $typeErrors = true;

                continue;
            }
            $before = count($issues);
            $text = self::text($o['text'] ?? null, $i, $j, $issues, 500, [
                'type' => 'Escriba el texto de la opción.',
                'empty' => 'Hay una opción vacía.',
                'long' => 'Una opción no puede superar 500 caracteres.',
            ]);
            if (! is_bool($o['isCorrect'] ?? null)) {
                $issues[] = [$i, $j, 'Invalid input: expected boolean, received '.self::typeName($o['isCorrect'] ?? null)];
                $typeErrors = true;
            }
            if ($text === null && count($issues) > $before) {
                $typeErrors = true;
            }
            $parsed[] = ['text' => $text ?? '', 'isCorrect' => (bool) ($o['isCorrect'] ?? false)];
        }

        if (count($options) < self::MIN_OPTIONS) {
            $issues[] = [$i, null, 'Debe tener al menos '.self::MIN_OPTIONS.' opciones.'];
        }
        if (count($options) > self::MAX_OPTIONS) {
            $issues[] = [$i, null, 'Puede tener máximo '.self::MAX_OPTIONS.' opciones.'];
        }
        // Refinements only run when every option has the right shape (as in zod).
        if (! $typeErrors) {
            if (count(array_filter($parsed, fn ($o) => $o['isCorrect'])) !== 1) {
                $issues[] = [$i, null, 'Marque exactamente una opción correcta.'];
            }
            $keys = array_map(fn ($o) => mb_strtolower(self::trim($o['text'])), $parsed);
            if (count(array_unique($keys)) !== count($parsed)) {
                $issues[] = [$i, null, 'Hay opciones repetidas.'];
            }
        }

        return ['type' => 'opcion_unica', 'prompt' => $prompt, 'required' => $required, 'options' => $parsed];
    }

    /** Trimmed text with its checks; null when it is not a string. */
    private static function text(mixed $value, int $i, ?int $j, array &$issues, int $max, array $messages): ?string
    {
        if (! is_string($value)) {
            $issues[] = [$i, $j, $messages['type']];

            return null;
        }
        $text = self::trim($value);
        if ($text === '') {
            $issues[] = [$i, $j, $messages['empty']];
        }
        if (mb_strlen($text) > $max) {
            $issues[] = [$i, $j, $messages['long']];
        }

        return $text;
    }

    private static function trim(string $v): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $v) ?? trim($v);
    }

    private static function typeName(mixed $v): string
    {
        return match (true) {
            $v === null => 'undefined',
            is_bool($v) => 'boolean',
            is_int($v), is_float($v) => 'number',
            is_string($v) => 'string',
            is_array($v) && array_is_list($v) => 'array',
            default => 'object',
        };
    }
}
