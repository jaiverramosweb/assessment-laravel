<?php

namespace App\Http\Controllers\Admin\Assessments;

use App\Http\Controllers\Controller;
use App\Services\Assessments\Import\Docx;
use App\Services\Assessments\Import\QuestionRows;
use App\Services\Assessments\Import\WordQuestions;
use App\Services\Assessments\Import\WordTemplate;
use App\Services\Assessments\Questions;
use App\Services\Assessments\QuestionsLockedError;
use App\Services\Assessments\QuestionValidator;
use App\Services\Import\ImportFileError;
use App\Services\Import\Table;
use App\Services\Shared\AssessmentContext;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Inertia\Inertia;

/** Questions tab: editor, Excel/CSV import, Word preview and templates. */
class QuestionController extends Controller
{
    private const WORD_MAX_BYTES = 10 * 1024 * 1024;

    public function index(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);

        return Inertia::render('Admin/Assessments/Questions', [
            'context' => AssessmentContext::props($a),
            'questions' => Questions::listForAdmin($a->id),
            'result' => $request->session()->get('result'),
        ]);
    }

    public function save(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);

        $payload = json_decode((string) $request->input('payload', '[]'), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            return $this->toEditor($a->id, ['errors' => ['No se pudieron leer las preguntas enviadas.']]);
        }

        $parsed = QuestionValidator::validateList($payload);
        if (! $parsed['ok']) {
            return $this->toEditor($a->id, ['errors' => $parsed['errors']]);
        }

        try {
            Questions::replace($a->id, $parsed['questions'], $this->adminId($request), ip: $request->ip());
        } catch (QuestionsLockedError $e) {
            return $this->toEditor($a->id, ['errors' => [$e->getMessage()]]);
        }

        return $this->toEditor($a->id, ['saved' => count($parsed['questions'])]);
    }

    public function import(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $mode = $request->input('modo') === 'reemplazar' ? 'reemplazar' : 'agregar';
        $file = $request->file('archivo');
        if (! $file instanceof UploadedFile) {
            return $this->toEditor($a->id, ['importErrors' => ['Seleccione un archivo.']]);
        }

        try {
            $result = QuestionRows::parse(Table::read($file));
        } catch (ImportFileError $e) {
            return $this->toEditor($a->id, ['importErrors' => [$e->getMessage()]]);
        }
        if (! $result['ok']) {
            return $this->toEditor($a->id, ['importErrors' => $result['errors']]);
        }

        $existing = $mode === 'agregar' ? Questions::toInputs(Questions::listForAdmin($a->id)) : [];
        $all = [...$existing, ...$result['questions']];
        if (count($all) > QuestionValidator::MAX_QUESTIONS) {
            return $this->toEditor($a->id, [
                'importErrors' => ['El total superaría el máximo de '.QuestionValidator::MAX_QUESTIONS.' preguntas.'],
            ]);
        }

        try {
            Questions::replace($a->id, $all, $this->adminId($request), 'preguntas.importadas', $request->ip());
        } catch (QuestionsLockedError $e) {
            return $this->toEditor($a->id, ['importErrors' => [$e->getMessage()]]);
        }

        return $this->toEditor($a->id, ['imported' => count($result['questions']), 'modo' => $mode]);
    }

    /**
     * Reads a Word document and returns the questions WITHOUT saving them (JSON): the browser
     * loads them in the editor for review, and they are saved with "Guardar preguntas" (which
     * validates everything again).
     */
    public function readWord(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $locked = AssessmentContext::questionsLockReason($a->id);
        if ($locked) {
            return response()->json(['wordErrors' => [$locked]], 409);
        }
        $file = $request->file('documento');
        if (! $file instanceof UploadedFile || $file->getSize() === 0) {
            return response()->json(['wordErrors' => ['Seleccione un documento Word (.docx).']], 400);
        }
        if ($file->getSize() > self::WORD_MAX_BYTES) {
            return response()->json(['wordErrors' => ['El documento no puede superar 10 MB.']], 400);
        }

        try {
            $result = WordQuestions::parse(Docx::read((string) file_get_contents($file->getRealPath())));
        } catch (ImportFileError $e) {
            return response()->json(['wordErrors' => [$e->getMessage()]], 400);
        }
        if (! $result['ok']) {
            return response()->json(['wordErrors' => $result['errors']], 400);
        }

        return response()->json(['word' => [
            'fileName' => $file->getClientOriginalName(),
            'questions' => $result['questions'],
            'warnings' => $result['warnings'],
        ]]);
    }

    /** Excel template to import questions. */
    public function excelTemplate()
    {
        return response(QuestionRows::buildTemplate(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="plantilla-preguntas.xlsx"',
        ]);
    }

    /** Word template to import questions. */
    public function wordTemplate()
    {
        return response(WordTemplate::build(), 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'Content-Disposition' => 'attachment; filename="plantilla-preguntas.docx"',
        ]);
    }

    private function toEditor(string $id, array $result)
    {
        return redirect("/admin/assessments/{$id}/preguntas", 303)->with('result', $result);
    }

    private function adminId(Request $request): string
    {
        return $request->attributes->get('admin')->id;
    }
}
