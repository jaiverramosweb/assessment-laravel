<?php

namespace Tests\Feature\Assessments;

use App\Services\Assessments\Import\Docx;
use App\Services\Assessments\Import\WordQuestions;
use App\Services\Assessments\Import\WordTemplate;
use App\Services\Import\ImportFileError;
use Tests\Fixtures\Assessments\Builders as B;
use Tests\TestCase;

/** Port of src/lib/server/import/questions-word.spec.ts. */
class WordImportTest extends TestCase
{
    private const U = '<w:u w:val="single"/>';

    private const HL = '<w:highlight w:val="yellow"/>';

    private function parse(string $bytes): array
    {
        return WordQuestions::parse(Docx::read($bytes));
    }

    private function ok(string $bytes): array
    {
        $result = $this->parse($bytes);
        $this->assertTrue($result['ok'], json_encode($result));

        return $result;
    }

    public function test_reads_the_template_underline_highlight_open_and_optional(): void
    {
        $this->assertSame([
            'ok' => true,
            'questions' => [
                [
                    'type' => 'opcion_unica',
                    'prompt' => '¿Cuál es el principal objetivo de la política de seguridad de la información?',
                    'required' => true,
                    'options' => [
                        ['text' => 'Proteger la confidencialidad, integridad y disponibilidad', 'isCorrect' => true],
                        ['text' => 'Reducir costos de tecnología', 'isCorrect' => false],
                        ['text' => 'Cumplir un requisito administrativo', 'isCorrect' => false],
                    ],
                ],
                [
                    'type' => 'opcion_unica',
                    'prompt' => '¿Qué debe hacer si recibe un correo sospechoso?',
                    'required' => true,
                    'options' => [
                        ['text' => 'Abrir el adjunto para revisarlo', 'isCorrect' => false],
                        ['text' => 'Reportarlo al área de seguridad', 'isCorrect' => true],
                        ['text' => 'Reenviarlo a sus compañeros', 'isCorrect' => false],
                    ],
                ],
                [
                    'type' => 'abierta',
                    'prompt' => 'Describa una situación reciente en la que aplicó este concepto.',
                    'required' => false,
                    'options' => [],
                ],
            ],
            'warnings' => [],
        ], $this->parse(WordTemplate::build()));
    }

    public function test_understands_word_automatic_lists(): void
    {
        $numbering =
            '<w:abstractNum w:abstractNumId="0"><w:lvl w:ilvl="0"><w:numFmt w:val="decimal"/></w:lvl>'.
            '<w:lvl w:ilvl="1"><w:numFmt w:val="lowerLetter"/></w:lvl></w:abstractNum>'.
            '<w:abstractNum w:abstractNumId="1"><w:lvl w:ilvl="0"><w:numFmt w:val="bullet"/></w:lvl></w:abstractNum>'.
            '<w:num w:numId="1"><w:abstractNumId w:val="0"/></w:num>'.
            '<w:num w:numId="2"><w:abstractNumId w:val="1"/></w:num>';
        $body = B::p(B::r('Evaluación de ciberseguridad'))
            .B::listItem(1, 0, B::r('¿Qué es phishing?'))
            .B::listItem(1, 1, B::r('Un tipo de pesca'))
            .B::listItem(1, 1, B::r('Un engaño para robar datos', self::HL))
            .B::listItem(1, 0, B::r('¿Dónde se reporta?'))
            .B::listItem(2, 0, B::r('En la mesa de ayuda', self::U))
            .B::listItem(2, 0, B::r('En ningún lado'));
        $result = $this->ok(B::docx($body, $numbering));
        $this->assertSame([], $result['warnings']);
        $this->assertSame(['¿Qué es phishing?', '¿Dónde se reporta?'], array_column($result['questions'], 'prompt'));
        $this->assertSame([false, true], array_column($result['questions'][0]['options'], 'isCorrect'));
        $this->assertSame([true, false], array_column($result['questions'][1]['options'], 'isCorrect'));
    }

    public function test_without_numbers_loose_text_with_options_is_a_question(): void
    {
        $body = B::p(B::r('¿Usa contraseñas largas?')).B::p(B::r('a) Sí', self::U)).B::p(B::r('b) No')).B::p(B::r('Comentarios finales'));
        $result = $this->ok(B::docx($body));
        $this->assertSame(
            [['opcion_unica', '¿Usa contraseñas largas?'], ['abierta', 'Comentarios finales']],
            array_map(fn ($q) => [$q['type'], $q['prompt']], $result['questions']),
        );
    }

    public function test_warns_without_blocking_about_what_to_review(): void
    {
        $body = B::p(B::r('1. Sin marca')).B::p(B::r('a) Uno')).B::p(B::r('b) Dos'))
            .B::p(B::r('2. Dos marcadas')).B::p(B::r('a) Uno', self::U)).B::p(B::r('b) Dos', self::HL))
            .B::p(B::r('3. Una sola opción')).B::p(B::r('a) Única', self::U))
            .B::p(B::r('Nota al pie suelta'));
        $result = $this->ok(B::docx($body));
        $this->assertSame([false, false], array_column($result['questions'][0]['options'], 'isCorrect'));
        $this->assertSame([true, false], array_column($result['questions'][1]['options'], 'isCorrect'));
        $this->assertSame([
            'Se ignoró el texto "Nota al pie suelta" (después de la pregunta 3).',
            'Pregunta 1: no tiene la respuesta subrayada ni resaltada. Márquela en el editor.',
            'Pregunta 2: tiene varias opciones marcadas; se tomó la primera. Revísela.',
            'Pregunta 3: tiene una sola opción (mínimo 2).',
        ], $result['warnings']);
    }

    public function test_multi_line_prompt_correct_tag_and_marks_that_are_not_options(): void
    {
        $body = B::p(B::r('1.'))
            .B::p(B::r('Lea el caso:'))
            .B::p(B::r('E-mail recibido a las 3 p. m. por 1.5 horas'))
            .B::p(B::r('a. Lo ignoro'))
            .B::p(B::r('b. Lo reporto (correcta)'))
            // Only a small underlined part: it does not count as a mark.
            .B::p(B::r('c. Lo reenvío a '), B::r('todos', self::U), B::r(' los compañeros del área'));
        $result = $this->ok(B::docx($body));
        $this->assertSame("Lea el caso:\nE-mail recibido a las 3 p. m. por 1.5 horas", $result['questions'][0]['prompt']);
        $this->assertSame([
            ['text' => 'Lo ignoro', 'isCorrect' => false],
            ['text' => 'Lo reporto', 'isCorrect' => true],
            ['text' => 'Lo reenvío a todos los compañeros del área', 'isCorrect' => false],
        ], $result['questions'][0]['options']);
    }

    public function test_decodes_entities_and_does_not_interpret_xml_as_instructions(): void
    {
        $body = B::p(B::r('1. ¿&lt;b&gt; &amp; &#233;?')).B::p(B::r('a) S&#xED;', self::U)).B::p(B::r('b) No'));
        $result = $this->ok(B::docx($body));
        $this->assertSame('¿<b> & é?', $result['questions'][0]['prompt']);
        $this->assertSame('Sí', $result['questions'][0]['options'][0]['text']);
    }

    public function test_rejects_files_that_are_not_docx_or_have_no_questions(): void
    {
        foreach (['{\rtf1 hola}', B::zip(['otro.txt' => 'x'])] as $bytes) {
            try {
                Docx::read($bytes);
                $this->fail('Expected ImportFileError');
            } catch (ImportFileError) {
                $this->addToAssertionCount(1);
            }
        }
        // Without numbers, loose text is taken as an open question: the admin reviews it.
        $this->assertTrue($this->parse(B::docx(B::p(B::r('Solo un título'))))['ok']);
        $none = $this->parse(B::docx(''));
        $this->assertFalse($none['ok']);
        $this->assertStringStartsWith('No se encontraron preguntas en el documento.', $none['errors'][0]);
    }

    public function test_not_a_docx_message(): void
    {
        $this->expectExceptionMessage('El archivo no es un Word .docx. Si es un .doc antiguo, ábralo en Word y guárdelo como .docx.');
        Docx::read('hola');
    }
}
