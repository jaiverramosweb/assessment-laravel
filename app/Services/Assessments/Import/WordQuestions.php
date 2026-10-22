<?php

namespace App\Services\Assessments\Import;

use App\Services\Assessments\QuestionValidator;

/**
 * Turns Word paragraphs into questions (port of src/lib/server/import/questions-word.ts).
 * Expected format (see the Word template):
 *
 *   1. ¿Question prompt?                 ← number (typed or Word automatic list)
 *      a) Option                         ← letter, bullet or sub-list
 *      b) Correct option                 ← underlined or highlighted
 *   2. Open question (opcional)          ← no options = open; "(opcional)" = not required
 *
 * The result is NOT saved: it is loaded in the editor for review. So instead of rejecting
 * everything on a problem, warnings per question are returned.
 */
final class WordQuestions
{
    // "1." / "1)" / "Pregunta 1:" (but not "1.5 millones").
    private const QUESTION_MARK = '/^(?:pregunta\s*)?\d{1,3}\s*[.)\-–:](?!\d)\s*/iu';

    // "a)" / "B." / "(c)" followed by a space (so "E-mail" does not count), or a bullet.
    private const OPTION_MARK = '/^(?:\(?[a-hA-H]\s*[.)-](?=\s)|[•·\-–—*▪◦●■□○➢►]|o(?=\t))\s*/u';

    private const OPTIONAL_TAG = '/\s*\((?:opcional|no obligatoria)\)\s*$/iu';

    private const REQUIRED_TAG = '/\s*\((?:obligatoria|requerida)\)\s*$/iu';

    private const CORRECT_TAG = '/\s*\((?:correcta|respuesta correcta|x)\)\s*$/iu';

    private const QUESTION_FORMATS = ['decimal', 'decimalZero', 'ordinal', 'cardinalText', 'ordinalText'];

    /**
     * @return array{ok: true, questions: array, warnings: string[]}|array{ok: false, errors: string[]}
     */
    public static function parse(array $paragraphs): array
    {
        $lines = array_values(array_filter(array_map([self::class, 'classify'], $paragraphs)));
        // When the document numbers its questions, loose text does not start questions (titles or notes).
        $numbered = (bool) array_filter($lines, fn ($l) => $l['explicitQuestion']);

        $questions = [];
        $marks = [];
        $warnings = [];
        $ignoredBefore = 0;
        $current = null; // index into $questions

        $start = function (string $text) use (&$questions, &$marks, &$current) {
            $questions[] = ['type' => 'abierta', 'prompt' => $text, 'required' => true, 'options' => []];
            $marks[] = [];
            $current = count($questions) - 1;
        };

        foreach ($lines as $line) {
            if ($line['kind'] === 'question') {
                $start($line['text']);
            } elseif ($line['kind'] === 'option') {
                if ($current === null) {
                    $ignoredBefore++;

                    continue;
                }
                $correctByText = (bool) preg_match(self::CORRECT_TAG, $line['text']);
                $questions[$current]['type'] = 'opcion_unica';
                $questions[$current]['options'][] = ['text' => preg_replace(self::CORRECT_TAG, '', $line['text']), 'isCorrect' => false];
                $marks[$current][] = $line['marked'] || $correctByText;
            } elseif ($current !== null && count($questions[$current]['options']) === 0) {
                // Loose text before the options: continues the prompt.
                $questions[$current]['prompt'] .= "\n".$line['text'];
            } elseif (! $numbered) {
                $start($line['text']);
            } elseif ($current === null) {
                continue; // Title or introduction before the first question.
            } else {
                $warnings[] = 'Se ignoró el texto "'.self::snippet($line['text']).'" (después de la pregunta '.count($questions).').';
            }
        }

        if (count($questions) === 0) {
            return ['ok' => false, 'errors' => [
                'No se encontraron preguntas en el documento. Numere cada pregunta (1., 2., …) y ponga las opciones debajo con letras (a), b), …). Descargue la plantilla Word para ver un ejemplo.',
            ]];
        }
        if (count($questions) > QuestionValidator::MAX_QUESTIONS) {
            return ['ok' => false, 'errors' => [
                'El documento tiene '.count($questions).' preguntas; el máximo es '.QuestionValidator::MAX_QUESTIONS.'.',
            ]];
        }

        foreach ($questions as $i => &$q) {
            $n = $i + 1;
            $q['prompt'] = self::jsTrim($q['prompt']);
            if (preg_match(self::OPTIONAL_TAG, $q['prompt'])) {
                $q['required'] = false;
                $q['prompt'] = preg_replace(self::OPTIONAL_TAG, '', $q['prompt']);
            } else {
                $q['prompt'] = preg_replace(self::REQUIRED_TAG, '', $q['prompt']);
            }
            if (mb_strlen($q['prompt']) > 2000) {
                $warnings[] = "Pregunta {$n}: el enunciado supera 2.000 caracteres.";
            }
            if ($q['type'] === 'abierta') {
                continue;
            }

            $marked = $marks[$i];
            $first = array_search(true, $marked, true);
            if ($first !== false) {
                $q['options'][$first]['isCorrect'] = true;
            }
            if ($first === false) {
                $warnings[] = "Pregunta {$n}: no tiene la respuesta subrayada ni resaltada. Márquela en el editor.";
            } elseif (count(array_filter($marked)) > 1) {
                $warnings[] = "Pregunta {$n}: tiene varias opciones marcadas; se tomó la primera. Revísela.";
            }
            if (count($q['options']) === 1) {
                $warnings[] = "Pregunta {$n}: tiene una sola opción (mínimo 2).";
            }
            if (count($q['options']) > QuestionValidator::MAX_OPTIONS) {
                $warnings[] = "Pregunta {$n}: tiene ".count($q['options']).' opciones (máximo '.QuestionValidator::MAX_OPTIONS.').';
            }
            foreach ($q['options'] as $o) {
                if (mb_strlen($o['text']) > 500) {
                    $warnings[] = "Pregunta {$n}: una opción supera 500 caracteres.";
                    break;
                }
            }
        }
        unset($q);

        if ($ignoredBefore) {
            array_unshift($warnings, "Se ignoraron {$ignoredBefore} ".($ignoredBefore === 1 ? 'opción' : 'opciones')
                .' que aparecen antes de la primera pregunta.');
        }

        return ['ok' => true, 'questions' => $questions, 'warnings' => $warnings];
    }

    /** @return array{kind: string, text: string, marked: bool, explicitQuestion: bool}|null */
    private static function classify(array $p): ?array
    {
        $raw = implode('', array_column($p['runs'], 'text'));
        // Marks are looked up before cleaning: Word's "o" bullet is followed by a tab.
        $lead = preg_replace('/^\s+/u', '', $raw);
        $text = self::clean($lead);
        if ($text === '') {
            return null;
        }

        // Marked when at least half of the visible text is underlined or highlighted.
        $total = self::nonSpace($raw);
        $markedChars = self::nonSpace(implode('', array_column(array_filter($p['runs'], fn ($r) => $r['marked']), 'text')));
        $marked = $markedChars > 0 && $markedChars * 2 >= $total;

        if (preg_match(self::QUESTION_MARK, $lead)) {
            return ['kind' => 'question', 'text' => self::clean(preg_replace(self::QUESTION_MARK, '', $lead, 1)), 'marked' => $marked, 'explicitQuestion' => true];
        }
        if (preg_match(self::OPTION_MARK, $lead)) {
            return ['kind' => 'option', 'text' => self::clean(preg_replace(self::OPTION_MARK, '', $lead, 1)), 'marked' => $marked, 'explicitQuestion' => false];
        }
        if (isset($p['list'])) {
            $isQuestion = $p['list']['level'] === 0 && in_array($p['list']['format'], self::QUESTION_FORMATS, true);

            return ['kind' => $isQuestion ? 'question' : 'option', 'text' => $text, 'marked' => $marked, 'explicitQuestion' => $isQuestion];
        }

        return ['kind' => 'plain', 'text' => $text, 'marked' => $marked, 'explicitQuestion' => false];
    }

    private static function nonSpace(string $s): int
    {
        return mb_strlen(preg_replace('/\s+/u', '', $s));
    }

    /** Spaces, tabs and non-breaking spaces collapse to one space, without touching line breaks. */
    private static function clean(string $s): string
    {
        $s = preg_replace('/[^\S\n]+/u', ' ', $s);
        $s = preg_replace('/ *\n */', "\n", $s);

        return self::jsTrim($s);
    }

    private static function jsTrim(string $s): string
    {
        return preg_replace('/^\s+|\s+$/u', '', $s);
    }

    private static function snippet(string $s): string
    {
        return mb_strlen($s) > 60 ? mb_substr($s, 0, 57).'…' : $s;
    }
}
