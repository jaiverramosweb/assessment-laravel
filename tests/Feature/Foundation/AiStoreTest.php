<?php

namespace Tests\Feature\Foundation;

use App\Models\AiAnalysis;
use App\Models\Question;
use App\Services\Ai\AiStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AiStoreTest extends TestCase
{
    use RefreshDatabase;

    private const RESULT = [
        'summary' => 'Resumen',
        'themes' => [['name' => 'Tema', 'description' => 'Desc', 'frequency' => 'alta', 'approxCount' => 3]],
        'highlights' => ['Bien'],
        'concerns' => [],
        'quotes' => ['"cita"'],
    ];

    public function test_latest_keeps_the_newest_valid_analysis_per_key(): void
    {
        $a = $this->createAssessment();
        $q = Question::create(['assessment_id' => $a->id, 'type' => 'abierta', 'prompt' => 'P', 'position' => 0]);
        $base = ['assessment_id' => $a->id, 'model' => 'm', 'input_hash' => 'h', 'response_count' => 2];
        AiAnalysis::create([...$base, 'question_id' => $q->id, 'scope' => 'pregunta', 'result' => self::RESULT, 'created_at' => 1000]);
        $newest = AiAnalysis::create([...$base, 'question_id' => $q->id, 'scope' => 'pregunta', 'result' => self::RESULT, 'created_at' => 2000]);
        // A newer record with an invalid shape is ignored.
        AiAnalysis::create([...$base, 'question_id' => $q->id, 'scope' => 'pregunta', 'result' => ['summary' => ''], 'created_at' => 3000]);
        AiAnalysis::create([...$base, 'question_id' => null, 'scope' => 'global', 'result' => self::RESULT, 'created_at' => 1500]);

        $latest = AiStore::latest($a->id);

        $this->assertEqualsCanonicalizing(['global', $q->id], array_keys($latest));
        $this->assertSame($newest->id, $latest[$q->id]['id']);
        $this->assertSame(2000, $latest[$q->id]['createdAt']);
        $this->assertSame(self::RESULT, $latest['global']['result']);
    }

    public function test_result_validation(): void
    {
        $this->assertTrue(AiStore::isValidResult(self::RESULT));
        $bad = self::RESULT;
        $bad['themes'][0]['frequency'] = 'muy alta';
        $this->assertFalse(AiStore::isValidResult($bad));
        $bad = self::RESULT;
        $bad['quotes'] = array_fill(0, 13, 'x');
        $this->assertFalse(AiStore::isValidResult($bad));
        $this->assertFalse(AiStore::isValidResult(null));
    }
}
