<?php

namespace Tests\Feature\Assessments;

use App\Models\Assessment;
use App\Models\AuditLog;
use App\Models\Question;
use App\Models\Submission;
use App\Services\Assessments\Assessments;
use App\Services\Assessments\Import\WordTemplate;
use App\Services\Assessments\Questions;
use App\Services\Shared\AssessmentContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\Assessments\Builders;
use Tests\TestCase;

class QuestionRoutesTest extends TestCase
{
    use RefreshDatabase;

    private const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    private Assessment $a;

    private string $base;

    private string $adminId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->adminId = $this->actingAsAdmin()->id;
        $this->a = Assessments::create(Builders::input(), $this->adminId);
        $this->base = "/admin/assessments/{$this->a->id}/preguntas";
    }

    private function lock(): void
    {
        $p = $this->createParticipant($this->a);
        Submission::create(['assessment_id' => $this->a->id, 'participant_id' => $p->id]);
    }

    public function test_editor_page_lists_questions_with_the_correct_option(): void
    {
        Questions::replace($this->a->id, Builders::questions(), $this->adminId);
        $this->get($this->base)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
            ->component('Admin/Assessments/Questions')
            ->where('context.questionCounts.total', 2)
            ->where('context.locked', false)
            ->has('questions', 2)
            ->where('questions.0.prompt', '¿Qué es confidencialidad?')
            ->where('questions.0.options.0.isCorrect', true)
            ->where('questions.1.required', false)
            ->where('result', null));
        $this->get('/admin/assessments/no-es-uuid/preguntas')->assertNotFound();
    }

    public function test_save_validates_per_question_and_persists(): void
    {
        $bad = json_encode([['type' => 'opcion_unica', 'prompt' => '', 'required' => true, 'options' => [
            ['text' => 'A', 'isCorrect' => true], ['text' => 'B', 'isCorrect' => false],
        ]]]);
        $this->post("{$this->base}/guardar", ['payload' => $bad])->assertRedirect($this->base)
            ->assertSessionHas('result', ['errors' => ['Pregunta 1: Escriba el enunciado.']]);
        $this->post("{$this->base}/guardar", ['payload' => '{no es json'])
            ->assertSessionHas('result', ['errors' => ['No se pudieron leer las preguntas enviadas.']]);
        $this->assertSame(0, Question::count());

        $this->post("{$this->base}/guardar", ['payload' => json_encode(Builders::questions())])
            ->assertStatus(303)->assertRedirect($this->base)->assertSessionHas('result', ['saved' => 2]);
        $this->assertCount(2, Questions::listForAdmin($this->a->id));
        $this->assertSame(1, AuditLog::where('action', 'preguntas.editadas')->count());
    }

    public function test_save_is_rejected_when_locked(): void
    {
        Questions::replace($this->a->id, Builders::questions(), $this->adminId);
        $this->lock();
        $this->post("{$this->base}/guardar", ['payload' => '[]'])
            ->assertSessionHas('result', ['errors' => [AssessmentContext::LOCKED_BY_RESPONSES]]);
        $this->assertCount(2, Questions::listForAdmin($this->a->id));
        $this->get($this->base)->assertInertia(fn (AssertableInertia $page) => $page
            ->where('context.locked', true)->where('context.lockReason', AssessmentContext::LOCKED_BY_RESPONSES));
    }

    public function test_excel_template_download_and_import_append_or_replace(): void
    {
        $tpl = $this->get("{$this->base}/plantilla")->assertOk();
        $this->assertStringContainsString('spreadsheetml', $tpl->headers->get('content-type'));
        $this->assertStringContainsString('plantilla-preguntas.xlsx', $tpl->headers->get('content-disposition'));
        $bytes = $tpl->getContent();

        Questions::replace($this->a->id, Builders::questions(), $this->adminId);
        $this->post("{$this->base}/importar", ['archivo' => Builders::upload('preguntas.xlsx', $bytes), 'modo' => 'agregar'])
            ->assertRedirect($this->base)->assertSessionHas('result', ['imported' => 2, 'modo' => 'agregar']);
        $this->assertCount(4, Questions::listForAdmin($this->a->id));
        $this->assertSame(1, AuditLog::where('action', 'preguntas.importadas')->count());

        $this->post("{$this->base}/importar", ['archivo' => Builders::upload('preguntas.xlsx', $bytes), 'modo' => 'reemplazar'])
            ->assertSessionHas('result', ['imported' => 2, 'modo' => 'reemplazar']);
        $this->assertCount(2, Questions::listForAdmin($this->a->id));
    }

    public function test_excel_import_errors_import_nothing(): void
    {
        $this->post("{$this->base}/importar", ['archivo' => Builders::upload('malo.csv', "tipo;enunciado\nencuesta;Hola\n", 'text/csv')])
            ->assertSessionHas('result', ['importErrors' => ['Fila 2: tipo "encuesta" no reconocido (use "seleccion" o "abierta").']]);
        $this->post("{$this->base}/importar", [])
            ->assertSessionHas('result', ['importErrors' => ['Seleccione un archivo.']]);
        $this->post("{$this->base}/importar", ['archivo' => Builders::upload('x.pdf', 'x')])
            ->assertSessionHas('result', ['importErrors' => ['Formato no soportado. Use un archivo .xlsx o .csv.']]);
        $this->assertSame(0, Question::count());

        $rows = "tipo;enunciado\n".str_repeat("abierta;P\n", 199);
        Questions::replace($this->a->id, Builders::questions(), $this->adminId);
        $this->post("{$this->base}/importar", ['archivo' => Builders::upload('m.csv', $rows), 'modo' => 'agregar'])
            ->assertSessionHas('result', ['importErrors' => ['El total superaría el máximo de 200 preguntas.']]);

        $this->lock();
        $this->post("{$this->base}/importar", ['archivo' => Builders::upload('m.csv', "tipo;enunciado\nabierta;P\n"), 'modo' => 'reemplazar'])
            ->assertSessionHas('result', ['importErrors' => [AssessmentContext::LOCKED_BY_RESPONSES]]);
    }

    public function test_word_template_download_and_preview_without_saving(): void
    {
        $tpl = $this->get("{$this->base}/plantilla-word")->assertOk();
        $this->assertSame(self::DOCX_MIME, $tpl->headers->get('content-type'));
        $this->assertStringContainsString('plantilla-preguntas.docx', $tpl->headers->get('content-disposition'));
        $bytes = $tpl->getContent();
        $this->assertSame(WordTemplate::build(), $bytes);

        $this->post("{$this->base}/leer-word", ['documento' => Builders::upload('plantilla-preguntas.docx', $bytes, self::DOCX_MIME)])
            ->assertOk()
            ->assertJsonPath('word.fileName', 'plantilla-preguntas.docx')
            ->assertJsonCount(3, 'word.questions')
            ->assertJsonPath('word.questions.1.options.1.isCorrect', true)
            ->assertJsonPath('word.warnings', []);
        $this->assertSame(0, Question::count());
    }

    public function test_word_preview_errors(): void
    {
        $this->post("{$this->base}/leer-word", ['documento' => Builders::upload('notas.docx', 'hola')])
            ->assertStatus(400)->assertJsonPath('wordErrors.0', 'El archivo no es un Word .docx. Si es un .doc antiguo, ábralo en Word y guárdelo como .docx.');
        $this->post("{$this->base}/leer-word", [])
            ->assertStatus(400)->assertJsonPath('wordErrors', ['Seleccione un documento Word (.docx).']);
        $this->post("{$this->base}/leer-word", ['documento' => Builders::upload('vacio.docx', Builders::docx(''))])
            ->assertStatus(400)->assertJsonPath('wordErrors.0', 'No se encontraron preguntas en el documento. Numere cada pregunta (1., 2., …) y ponga las opciones debajo con letras (a), b), …). Descargue la plantilla Word para ver un ejemplo.');

        $this->lock();
        $this->post("{$this->base}/leer-word", ['documento' => Builders::upload('p.docx', WordTemplate::build())])
            ->assertStatus(409)->assertJsonPath('wordErrors', [AssessmentContext::LOCKED_BY_RESPONSES]);
    }
}
