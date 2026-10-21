<?php

namespace Tests\Feature\Foundation;

use App\Models\Client;
use App\Models\Question;
use App\Models\Submission;
use App\Services\Shared\AssessmentContext;
use App\Support\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\TestCase;

class AssessmentContextTest extends TestCase
{
    use RefreshDatabase;

    public function test_require_rejects_non_uuid_and_unknown_ids(): void
    {
        $a = $this->createAssessment();
        $this->assertSame($a->id, AssessmentContext::require($a->id)->id);

        foreach (['../etc', '00000000-0000-0000-0000-000000000000'] as $bad) {
            try {
                AssessmentContext::require($bad);
                $this->fail("{$bad} should be 404");
            } catch (NotFoundHttpException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_props_hide_secrets_and_include_brand_counts_and_link(): void
    {
        config(['app.url' => 'https://assesment.sisteltestserver.com/']);
        $client = Client::create(['name' => 'ACME', 'logo_mime' => 'image/png', 'logo_updated_at' => 123, 'theme_primary' => '#112233']);
        $a = $this->createAssessment(['client_id' => $client->id, 'access_password_hash' => 'h', 'access_password_enc' => 'e']);
        Question::create(['assessment_id' => $a->id, 'type' => 'opcion_unica', 'prompt' => 'P1', 'position' => 0]);
        Question::create(['assessment_id' => $a->id, 'type' => 'abierta', 'prompt' => 'P2', 'position' => 1]);
        $this->createParticipant($a);

        $props = AssessmentContext::props($a);

        $this->assertTrue($props['assessment']['hasPassword']);
        $this->assertTrue($props['assessment']['passwordViewable']);
        $this->assertArrayNotHasKey('access_password_hash', $props['assessment']);
        $this->assertSame("/a/{$a->slug}/logo?v=123", $props['assessment']['logoUrl']);
        $this->assertSame('#112233', $props['assessment']['client']['themePrimary']);
        $this->assertSame(['choice' => 1, 'open' => 1, 'total' => 2], $props['questionCounts']);
        $this->assertSame(1, $props['participantCount']);
        $this->assertSame("https://assesment.sisteltestserver.com/a/{$a->slug}", $props['publicLink']);
        $this->assertNull($props['series']);
        $this->assertFalse($props['locked']);
    }

    public function test_questions_lock_by_responses_and_by_series(): void
    {
        $a = $this->createAssessment();
        $p = $this->createParticipant($a);
        $this->assertNull(AssessmentContext::questionsLockReason($a->id));

        Submission::create(['participant_id' => $p->id, 'assessment_id' => $a->id]);
        $this->assertSame(AssessmentContext::LOCKED_BY_RESPONSES, AssessmentContext::questionsLockReason($a->id));

        $first = $this->createAssessment(['series_order' => 1]);
        $first->update(['series_id' => $first->id]);
        $second = $this->createAssessment(['series_id' => $first->id, 'series_order' => 2]);
        $this->assertSame(AssessmentContext::LOCKED_BY_SERIES, AssessmentContext::questionsLockReason($second->id));
        $this->assertSame([$first->id, $second->id], AssessmentContext::seriesMembers($second)->pluck('id')->all());
        $this->assertCount(2, AssessmentContext::props($first)['series']);
    }

    public function test_theme_style_matches_the_original_formula(): void
    {
        $this->assertNull(Theme::style(null, 'no-es-color'));
        $style = Theme::style('#1a1c23', '#374ef7');
        $this->assertStringContainsString('--color-ink-950: #1a1c23', $style);
        $this->assertStringContainsString('--color-brand-600: #374ef7', $style);
        $this->assertStringContainsString('--color-brand-50: '.Theme::mix('#374ef7', '#ffffff', 0.9), $style);
        $this->assertTrue(Theme::readableWithWhite('#1a1c23'));
        $this->assertFalse(Theme::readableWithWhite('#ffff00'));
    }
}
