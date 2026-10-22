<?php

namespace Tests\Fixtures\Assessments;

use App\Services\Assessments\AssessmentForm;
use Illuminate\Http\UploadedFile;
use RuntimeException;
use ZipArchive;

/** Test data shared by the assessment tests (same values as the original specs). */
final class Builders
{
    public const W = 'xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"';

    /** Raw form values (what the browser sends) for a valid assessment. */
    public static function formValues(array $overrides = []): array
    {
        return [
            'title' => 'Evaluación de seguridad',
            'clientId' => '',
            'description' => 'Descripción',
            'instructions' => 'Responda con calma',
            'timezone' => 'America/Bogota',
            'startsAt' => '2026-11-01T08:00',
            'endsAt' => '2026-11-15T18:00',
            'accessMode' => 'invitados',
            'openMaxChars' => '8000',
            ...$overrides,
        ];
    }

    /** Validated input, ready for the services. */
    public static function input(array $overrides = []): array
    {
        $result = AssessmentForm::validate(self::formValues($overrides));
        if (isset($result['errors'])) {
            throw new RuntimeException('Invalid fixture: '.json_encode($result['errors']));
        }

        return $result['data'];
    }

    /** Two questions: a choice one (first option is correct) and an optional open one. */
    public static function questions(): array
    {
        return [
            [
                'type' => 'opcion_unica',
                'prompt' => '¿Qué es confidencialidad?',
                'required' => true,
                'options' => [
                    ['text' => 'Que solo accedan los autorizados', 'isCorrect' => true],
                    ['text' => 'Que todo sea público', 'isCorrect' => false],
                ],
            ],
            ['type' => 'abierta', 'prompt' => 'Comente un caso real', 'required' => false],
        ];
    }

    /** ZIP bytes with the given entries. */
    public static function zip(array $files): string
    {
        $path = tempnam(sys_get_temp_dir(), 'zip');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        foreach ($files as $name => $content) {
            $zip->addFromString($name, $content);
        }
        $zip->close();
        $bytes = (string) file_get_contents($path);
        @unlink($path);

        return $bytes;
    }

    /** Minimal .docx with the given body (and optional numbering.xml). */
    public static function docx(string $body, ?string $numbering = null): string
    {
        $files = [
            'word/document.xml' => '<?xml version="1.0"?><w:document '.self::W."><w:body>{$body}</w:body></w:document>",
        ];
        if ($numbering !== null) {
            $files['word/numbering.xml'] = '<w:numbering '.self::W.">{$numbering}</w:numbering>";
        }

        return self::zip($files);
    }

    public static function p(string ...$runs): string
    {
        return '<w:p>'.implode('', $runs).'</w:p>';
    }

    public static function r(string $text, string $props = ''): string
    {
        return '<w:r>'.($props ? "<w:rPr>{$props}</w:rPr>" : '')."<w:t xml:space=\"preserve\">{$text}</w:t></w:r>";
    }

    public static function listItem(int $numId, int $ilvl, string ...$runs): string
    {
        return "<w:p><w:pPr><w:numPr><w:ilvl w:val=\"{$ilvl}\"/><w:numId w:val=\"{$numId}\"/></w:numPr></w:pPr>"
            .implode('', $runs).'</w:p>';
    }

    /** Uploaded file with the given bytes. */
    public static function upload(string $name, string $bytes, string $mime = 'application/octet-stream'): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'upl');
        file_put_contents($path, $bytes);

        return new UploadedFile($path, $name, $mime, null, true);
    }
}
