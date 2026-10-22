<?php

namespace App\Services\Assessments\Import;

use ZipArchive;

/**
 * Word (.docx) template to import questions (port of src/lib/server/import/word-template.ts):
 * a minimal document with instructions and examples that use the same marks WordQuestions
 * recognizes (underline and highlight).
 */
final class WordTemplate
{
    /** Fixed entry date so the file is byte-identical on every download (2026-01-01). */
    private const ENTRY_MTIME = 1767225600;

    public static function build(): string
    {
        $body = [
            self::para(['runs' => [['text' => 'Plantilla de preguntas', 'bold' => true]]], 32),
            self::para(self::text('Cómo usarla: escriba cada pregunta con su número (1., 2., …) y debajo sus opciones con letras (a), b), …).')),
            self::para(self::text('Para indicar la respuesta correcta, subráyela o resáltela. Una pregunta sin opciones se toma como abierta.')),
            self::para(self::text('Si una pregunta no es obligatoria, escriba (opcional) al final. Este texto de introducción se ignora al importar.')),
            self::para([
                'runs' => [['text' => '1. ¿Cuál es el principal objetivo de la política de seguridad de la información?', 'bold' => true]],
                'spaceBefore' => true,
            ]),
            self::para([
                'runs' => [['text' => 'a) '], ['text' => 'Proteger la confidencialidad, integridad y disponibilidad', 'underline' => true]],
                'indent' => true,
            ]),
            self::para(['runs' => [['text' => 'b) Reducir costos de tecnología']], 'indent' => true]),
            self::para(['runs' => [['text' => 'c) Cumplir un requisito administrativo']], 'indent' => true]),
            self::para([
                'runs' => [['text' => '2. ¿Qué debe hacer si recibe un correo sospechoso?', 'bold' => true]],
                'spaceBefore' => true,
            ]),
            self::para(['runs' => [['text' => 'a) Abrir el adjunto para revisarlo']], 'indent' => true]),
            self::para([
                'runs' => [['text' => 'b) '], ['text' => 'Reportarlo al área de seguridad', 'highlight' => true]],
                'indent' => true,
            ]),
            self::para(['runs' => [['text' => 'c) Reenviarlo a sus compañeros']], 'indent' => true]),
            self::para([
                'runs' => [['text' => '3. Describa una situación reciente en la que aplicó este concepto. (opcional)', 'bold' => true]],
                'spaceBefore' => true,
            ]),
        ];

        $document = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            .'<w:body>'.implode('', $body).'<w:sectPr><w:pgSz w:w="12240" w:h="15840"/>'
            .'<w:pgMar w:top="1440" w:right="1440" w:bottom="1440" w:left="1440"/></w:sectPr></w:body></w:document>';

        $files = [
            '[Content_Types].xml' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
                .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
                .'<Default Extension="xml" ContentType="application/xml"/>'
                .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
                .'</Types>',
            '_rels/.rels' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
                .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
                .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
                .'</Relationships>',
            'word/document.xml' => $document,
        ];

        $path = tempnam(sys_get_temp_dir(), 'wtpl');
        try {
            $zip = new ZipArchive;
            $zip->open($path, ZipArchive::OVERWRITE);
            foreach ($files as $name => $content) {
                $zip->addFromString($name, $content);
                $zip->setMtimeName($name, self::ENTRY_MTIME);
            }
            $zip->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    private static function text(string $t): array
    {
        return ['runs' => [['text' => $t]]];
    }

    private static function esc(string $s): string
    {
        return str_replace(['&', '<', '>'], ['&amp;', '&lt;', '&gt;'], $s);
    }

    private static function run(array $r, int $size): string
    {
        $props = '<w:rFonts w:ascii="Calibri" w:hAnsi="Calibri" w:cs="Calibri"/>'
            .(! empty($r['bold']) ? '<w:b/>' : '')
            .(! empty($r['underline']) ? '<w:u w:val="single"/>' : '')
            .(! empty($r['highlight']) ? '<w:highlight w:val="yellow"/>' : '')
            ."<w:sz w:val=\"{$size}\"/>";

        return "<w:r><w:rPr>{$props}</w:rPr><w:t xml:space=\"preserve\">".self::esc($r['text']).'</w:t></w:r>';
    }

    private static function para(array $p, int $size = 22): string
    {
        $ppr = (! empty($p['indent']) ? '<w:ind w:left="567"/>' : '')
            .'<w:spacing w:before="'.(! empty($p['spaceBefore']) ? 240 : 0).'" w:after="60"/>';

        return "<w:p><w:pPr>{$ppr}</w:pPr>".implode('', array_map(fn ($r) => self::run($r, $size), $p['runs'])).'</w:p>';
    }
}
