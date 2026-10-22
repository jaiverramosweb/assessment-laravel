<?php

namespace App\Services\Assessments\Import;

use App\Services\Import\ImportFileError;
use Generator;
use ZipArchive;

/**
 * Minimal reading of Word documents (.docx) to import questions (port of
 * src/lib/server/import/docx.ts). A .docx is a ZIP with XML. Only word/document.xml (paragraphs,
 * text, underline, highlight) and word/numbering.xml (whether an automatic list is 1., a) or
 * bullets) are read. The XML is walked with an own tokenizer: no external entities or DTD are
 * resolved.
 *
 * A paragraph is ['runs' => [['text' => string, 'marked' => bool], ...], 'list' => ['level' => int, 'format' => string]?].
 */
final class Docx
{
    /** Uncompressed cap: protects against "zip bombs". */
    private const MAX_XML_BYTES = 20 * 1024 * 1024;

    private const MAX_PARAGRAPHS = 5000;

    private const TAG_RE = '/\G<(\/?)([A-Za-z_][\w:.-]*)((?:\s+[\w:.-]+\s*=\s*(?:"[^"]*"|\'[^\']*\'))*)\s*(\/?)>/';

    private const ATTR_RE = '/([\w:.-]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\')/';

    /** Opens the .docx bytes and returns its paragraphs. Throws ImportFileError when invalid. */
    public static function read(string $data): array
    {
        // A .docx starts with the ZIP signature "PK\x03\x04". An old binary .doc does not.
        if (strlen($data) < 2 || $data[0] !== 'P' || $data[1] !== 'K') {
            throw new ImportFileError(
                'El archivo no es un Word .docx. Si es un .doc antiguo, ábralo en Word y guárdelo como .docx.'
            );
        }

        $path = tempnam(sys_get_temp_dir(), 'docx');
        file_put_contents($path, $data);
        try {
            $zip = new ZipArchive;
            if ($zip->open($path, ZipArchive::RDONLY) !== true) {
                throw new ImportFileError('No se pudo abrir el archivo Word. Verifique que no esté dañado.');
            }
            try {
                $doc = self::entry($zip, 'word/document.xml');
                $numberingXml = self::entry($zip, 'word/numbering.xml');
            } finally {
                $zip->close();
            }
        } finally {
            @unlink($path);
        }

        if ($doc === null) {
            throw new ImportFileError('El archivo Word no tiene contenido legible o es demasiado grande.');
        }
        $numbering = $numberingXml !== null ? self::readNumbering($numberingXml) : [];

        return self::readParagraphs($doc, $numbering);
    }

    /** Entry contents, or null when missing, unreadable or bigger than the cap (counting real bytes). */
    private static function entry(ZipArchive $zip, string $name): ?string
    {
        if ($zip->locateName($name) === false) {
            return null;
        }
        $stream = $zip->getStream($name);
        if ($stream === false) {
            throw new ImportFileError('No se pudo abrir el archivo Word. Verifique que no esté dañado.');
        }
        $data = '';
        try {
            while (! feof($stream)) {
                $chunk = fread($stream, 65536);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $data .= $chunk;
                if (strlen($data) > self::MAX_XML_BYTES) {
                    return null;
                }
            }
        } finally {
            fclose($stream);
        }

        return $data;
    }

    // ---- XML tokenizer -----------------------------------------------------------

    private static function decodeEntities(string $s): string
    {
        return preg_replace_callback('/&(#x[0-9a-f]+|#\d+|amp|lt|gt|quot|apos);/i', function ($m) {
            $k = strtolower($m[1]);

            return match ($k) {
                'amp' => '&',
                'lt' => '<',
                'gt' => '>',
                'quot' => '"',
                'apos' => "'",
                default => (function () use ($k) {
                    $code = str_starts_with($k, '#x') ? hexdec(substr($k, 2)) : (int) substr($k, 1);

                    return $code > 0 && $code <= 0x10FFFF ? (mb_chr((int) $code, 'UTF-8') ?: '') : '';
                })(),
            };
        }, $s) ?? $s;
    }

    /** @return Generator<array{tag: array{name: string, attrs: array<string, string>, close: bool, selfClose: bool}}|array{text: string}> */
    private static function tokens(string $xml): Generator
    {
        $i = 0;
        $len = strlen($xml);
        while ($i < $len) {
            $lt = strpos($xml, '<', $i);
            if ($lt === false) {
                yield ['text' => self::decodeEntities(substr($xml, $i))];

                return;
            }
            if ($lt > $i) {
                yield ['text' => self::decodeEntities(substr($xml, $i, $lt - $i))];
            }

            // Declarations, comments, CDATA and processing instructions are skipped.
            if (substr_compare($xml, '<?', $lt, 2) === 0 || substr_compare($xml, '<!', $lt, 2) === 0) {
                if (substr_compare($xml, '<!--', $lt, 4) === 0) {
                    $found = strpos($xml, '-->', $lt);
                    $end = $found === false ? -1 : $found + 3;
                } elseif (substr_compare($xml, '<![CDATA[', $lt, 9) === 0) {
                    $found = strpos($xml, ']]>', $lt);
                    $end = $found === false ? -1 : $found + 3;
                } else {
                    $found = strpos($xml, '>', $lt);
                    $end = $found === false ? -1 : $found + 1;
                }
                if ($end <= $lt) {
                    return;
                }
                $i = $end;

                continue;
            }

            if (! preg_match(self::TAG_RE, $xml, $m, 0, $lt)) {
                $i = $lt + 1; // stray "<": ignored

                continue;
            }
            $attrs = [];
            if (preg_match_all(self::ATTR_RE, $m[3], $am, PREG_SET_ORDER)) {
                foreach ($am as $a) {
                    $attrs[$a[1]] = self::decodeEntities(($a[2] ?? '') !== '' ? $a[2] : ($a[3] ?? ''));
                }
            }
            yield ['tag' => ['name' => $m[2], 'attrs' => $attrs, 'close' => $m[1] === '/', 'selfClose' => $m[4] === '/']];
            $i = $lt + strlen($m[0]);
        }
    }

    // ---- numbering.xml: numId → format of each level --------------------------------

    /** @return array<string, array<int, string>> */
    private static function readNumbering(string $xml): array
    {
        $abstractFormats = [];
        $numToAbstract = [];
        $abstractId = null;
        $level = -1;
        $numId = null;

        foreach (self::tokens($xml) as $t) {
            if (! isset($t['tag'])) {
                continue;
            }
            ['name' => $name, 'attrs' => $attrs, 'close' => $close] = $t['tag'];
            if ($name === 'w:abstractNum') {
                $abstractId = $close ? null : ($attrs['w:abstractNumId'] ?? null);
                if ($abstractId !== null) {
                    $abstractFormats[$abstractId] = [];
                }
            } elseif ($name === 'w:lvl' && ! $close) {
                $level = (int) ($attrs['w:ilvl'] ?? 0);
            } elseif ($name === 'w:numFmt' && $abstractId !== null && $level >= 0 && $level < 9) {
                $abstractFormats[$abstractId][$level] = $attrs['w:val'] ?? 'decimal';
            } elseif ($name === 'w:num') {
                $numId = $close ? null : ($attrs['w:numId'] ?? null);
            } elseif ($name === 'w:abstractNumId' && $numId !== null) {
                $numToAbstract[$numId] = $attrs['w:val'] ?? '';
            }
        }

        $out = [];
        foreach ($numToAbstract as $id => $abs) {
            $out[(string) $id] = $abstractFormats[$abs] ?? [];
        }

        return $out;
    }

    // ---- document.xml: paragraphs and runs ---------------------------------------

    /** Does the run format mark the answer? Underline or highlight (or a colored shading). */
    private static function isMarkTag(array $tag): bool
    {
        $val = strtolower($tag['attrs']['w:val'] ?? '');

        return match ($tag['name']) {
            'w:u' => $val !== 'none' && $val !== '0' && $val !== 'false',
            'w:highlight' => $val !== 'none' && $val !== '',
            'w:shd' => (function () use ($tag) {
                $fill = strtolower($tag['attrs']['w:fill'] ?? '');

                return $fill !== '' && $fill !== 'auto' && $fill !== 'ffffff';
            })(),
            default => false,
        };
    }

    private static function readParagraphs(string $xml, array $numbering): array
    {
        $paragraphs = [];
        $p = null;
        $inRun = false;
        $inRunProps = false;
        $inParaProps = false;
        $inText = false;
        $marked = false;
        $numId = null;
        $ilvl = 0;
        // Tables, text boxes and notes can nest paragraphs: they are flattened in order.
        $push = function (string $text) use (&$p, &$marked) {
            if ($p === null || $text === '') {
                return;
            }
            $last = array_key_last($p['runs']);
            if ($last !== null && $p['runs'][$last]['marked'] === $marked) {
                $p['runs'][$last]['text'] .= $text;
            } else {
                $p['runs'][] = ['text' => $text, 'marked' => $marked];
            }
        };

        foreach (self::tokens($xml) as $t) {
            if (! isset($t['tag'])) {
                if ($inText) {
                    $push($t['text']);
                }

                continue;
            }
            $tag = $t['tag'];
            ['name' => $name, 'attrs' => $attrs, 'close' => $close, 'selfClose' => $selfClose] = $tag;
            switch ($name) {
                case 'w:p':
                    if (! $close) {
                        $p = ['runs' => []];
                        $numId = null;
                        $ilvl = 0;
                        if ($selfClose) {
                            $paragraphs[] = $p;
                            $p = null;
                        }
                    } elseif ($p !== null) {
                        $formats = $numId !== null ? ($numbering[$numId] ?? null) : null;
                        if ($numId !== null && $numId !== '0') {
                            $p['list'] = ['level' => $ilvl, 'format' => $formats[$ilvl] ?? 'decimal'];
                        }
                        $paragraphs[] = $p;
                        $p = null;
                        if (count($paragraphs) > self::MAX_PARAGRAPHS) {
                            throw new ImportFileError(
                                'El documento es demasiado largo (más de '.self::MAX_PARAGRAPHS.' párrafos).'
                            );
                        }
                    }
                    break;
                case 'w:pPr':
                    $inParaProps = ! $close && ! $selfClose;
                    break;
                case 'w:numId':
                    if ($inParaProps && ! $inRunProps) {
                        $numId = $attrs['w:val'] ?? null;
                    }
                    break;
                case 'w:ilvl':
                    if ($inParaProps && ! $inRunProps) {
                        $ilvl = (int) ($attrs['w:val'] ?? 0);
                    }
                    break;
                case 'w:r':
                    $inRun = ! $close && ! $selfClose;
                    $marked = false;
                    break;
                case 'w:rPr':
                    $inRunProps = ! $close && ! $selfClose;
                    break;
                case 'w:u':
                case 'w:highlight':
                case 'w:shd':
                    if ($inRun && $inRunProps && self::isMarkTag($tag)) {
                        $marked = true;
                    }
                    break;
                case 'w:t':
                    $inText = ! $close && ! $selfClose && $inRun;
                    break;
                case 'w:tab':
                    if ($inRun && ! $inRunProps) {
                        $push("\t");
                    }
                    break;
                case 'w:br':
                case 'w:cr':
                    if ($inRun) {
                        $push("\n");
                    }
                    break;
            }
        }

        return $paragraphs;
    }
}
