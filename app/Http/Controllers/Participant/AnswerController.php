<?php

namespace App\Http\Controllers\Participant;

use App\Auth\ParticipantSessions;
use App\Http\Controllers\Controller;
use App\Http\Middleware\LoadParticipantSession;
use App\Services\ParticipantFlow\AlreadySubmittedException;
use App\Services\ParticipantFlow\Answers;
use App\Services\Shared\Brand;
use App\Support\Clock;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * /a/{slug}/responder: questions, autosave (guardar) and final submission (entregar).
 * Every route goes through LoadParticipantSession, which provides the participant context.
 */
class AnswerController extends Controller
{
    public function show(Request $request, string $slug)
    {
        $ctx = $request->attributes->get('participant');
        $a = $ctx['assessment'];

        return Inertia::render('Participant/Answer', [
            'assessment' => [
                'title' => $a->title,
                'instructions' => $a->instructions,
                'openMaxChars' => $a->open_max_chars,
                'endsAt' => $a->ends_at,
                'timezone' => $a->timezone,
                ...Brand::forParticipant($a->slug, $a->client_id),
            ],
            'participant' => ['name' => $ctx['participant']['name'], 'email' => $ctx['participant']['email']],
            // Without the correct option (see Answers::questionsFor).
            'questions' => Answers::questionsFor($a->id),
            // An object even when empty, so the browser can index it by question id.
            'answers' => (object) Answers::savedFor($ctx['submission']->id),
            'savedAt' => $ctx['submission']->updated_at,
            // Result of the last failed submission: {errors, missing}, or null.
            'form' => $request->session()->get('participantForm'),
        ]);
    }

    /** Autosave (and the "Guardar borrador" button). */
    public function save(Request $request, string $slug)
    {
        $ctx = $request->attributes->get('participant');
        $json = LoadParticipantSession::wantsActionJson($request);
        try {
            $r = Answers::save($ctx, Answers::readFields($request));
        } catch (AlreadySubmittedException) {
            return $json
                ? response()->json(['type' => 'redirect', 'location' => "/a/{$slug}/entregado"])
                : redirect("/a/{$slug}/entregado", 303);
        }

        if (! $r['ok']) {
            return $json
                ? response()->json(['type' => 'failure', 'errors' => $r['errors']], 400)
                : $this->back($slug, $r['errors']);
        }

        return $json
            ? response()->json(['type' => 'success', 'savedAt' => Clock::nowMs()])
            : redirect("/a/{$slug}/responder", 303);
    }

    /** Final submission: saves whatever comes in the form and then submits. */
    public function submit(Request $request, string $slug)
    {
        $ctx = $request->attributes->get('participant');
        try {
            $saved = Answers::save($ctx, Answers::readFields($request));
            if (! $saved['ok']) {
                return $this->back($slug, $saved['errors']);
            }

            $r = Answers::submit($ctx);
            if (! $r['ok']) {
                $list = implode(', ', $r['missing']);

                return $this->back($slug, [
                    count($r['missing']) === 1
                        ? "Falta responder la pregunta obligatoria {$list}."
                        : "Faltan preguntas obligatorias por responder: {$list}.",
                ], $r['missing']);
            }
        } catch (AlreadySubmittedException) {
            // Delivered by another request in the meantime: same outcome.
        }

        return redirect("/a/{$slug}/entregado", 303)->withCookie(ParticipantSessions::forgetCookie($slug));
    }

    private function back(string $slug, array $errors, array $missing = [])
    {
        return redirect("/a/{$slug}/responder", 303)
            ->with('participantForm', ['errors' => $errors, 'missing' => $missing]);
    }
}
