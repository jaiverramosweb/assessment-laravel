<?php

namespace Tests\Feature\Ai;

use App\Services\Ai\Analysis\Anonymizer;
use PHPUnit\Framework\TestCase;

/** Port of src/lib/server/ai/anonymize.spec.ts. */
class AnonymizerTest extends TestCase
{
    private function anon(): Anonymizer
    {
        return new Anonymizer(['Ana María Pérez', 'José Gómez', 'Li']);
    }

    public function test_replaces_emails_and_phones(): void
    {
        $this->assertSame(
            'Escríbanme a [email] o al [teléfono].',
            $this->anon()->apply('Escríbanme a ana.perez@empresa.com o al +57 300 123 4567.')
        );
    }

    public function test_replaces_full_names_and_parts_ignoring_accents_and_case(): void
    {
        $this->assertSame('[persona] y yo hablamos con [persona].', $this->anon()->apply('Ana María Pérez y yo hablamos con jose.'));
        $this->assertSame('Según [persona], el proceso falla.', $this->anon()->apply('Según PEREZ, el proceso falla.'));
    }

    public function test_does_not_touch_words_that_only_contain_the_name(): void
    {
        // "Li" is shorter than 3 letters: only replaced as a full name, never inside another word.
        $this->assertSame(
            'La política de Lima es clara. [persona] respondió.',
            $this->anon()->apply('La política de Lima es clara. Li respondió.')
        );
        $this->assertSame('Mariano y Anabel', $this->anon()->apply('Mariano y Anabel'));
    }

    public function test_keeps_accents_emojis_and_the_rest_of_the_text(): void
    {
        $this->assertSame('La información está en [persona].', $this->anon()->apply('La información está en José.'));
        $this->assertSame('👍 Gracias [persona], 🎉 buen trabajo', $this->anon()->apply('👍 Gracias José, 🎉 buen trabajo'));
    }

    public function test_a_name_made_of_common_words_does_not_erase_the_text(): void
    {
        $anon = new Anonymizer(['que para con', 'Ana Ruiz']);
        $this->assertSame(
            'Creo que para mejorar hay que hablar con [persona]',
            $anon->apply('Creo que para mejorar hay que hablar con Ruiz')
        );
    }

    public function test_anonymizes_long_texts_quickly(): void
    {
        $anon = new Anonymizer(['Ana María Pérez', 'José Gómez']);
        $text = str_repeat('palabra ', 50_000).str_repeat('x', 200_000).str_repeat(' 1', 10_000);
        $t0 = microtime(true);
        $out = $anon->apply($text);
        $this->assertLessThan(2.0, microtime(true) - $t0);
        $this->assertStringStartsWith('palabra palabra', $out);
    }

    public function test_chunks_without_splitting_answers_and_respects_the_maximum(): void
    {
        $this->assertSame([['aaaa', 'bbb'], ['cc'], ['dddddd']], Anonymizer::chunk(['aaaa', 'bbb', 'cc', 'dddddd'], 7));
        $this->assertSame([], Anonymizer::chunk([], 10));
        // An answer longer than the maximum goes alone in its block.
        $this->assertCount(1, Anonymizer::chunk([str_repeat('x', 20)], 10));
    }
}
