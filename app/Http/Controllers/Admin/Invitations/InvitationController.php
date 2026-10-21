<?php

namespace App\Http\Controllers\Admin\Invitations;

use App\Http\Controllers\Controller;
use App\Models\Assessment;
use App\Services\Invitations\Invitations;
use App\Services\Invitations\InviteTemplate;
use App\Services\Participants\Participants;
use App\Services\Shared\AssessmentContext;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Invitations tab: template editor with preview, email sending and the generic message to copy.
 * Action results come back as the one-shot `outcome` prop (like the original form actions).
 */
class InvitationController extends Controller
{
    private const WRONG_PASSWORD = 'La contraseña no coincide con la contraseña de acceso vigente.';

    public function index(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $list = Participants::listParticipants($a->id);

        return Inertia::render('Admin/Assessments/Invitations', [
            'context' => AssessmentContext::props($a),
            'template' => Invitations::template($a),
            'placeholders' => InviteTemplate::PLACEHOLDERS,
            // Preview: the real password is never sent to the page, a marker is shown instead.
            'previewVars' => [
                ...Invitations::baseVars($a, '(contraseña de acceso)'),
                'nombre' => $list[0]['name'] ?? 'Ana Pérez',
            ],
            'recipientCounts' => [
                'sin_invitar' => count(Invitations::selectRecipients($list, 'sin_invitar')),
                'no_completados' => count(Invitations::selectRecipients($list, 'no_completados')),
            ],
            'mailerKind' => Invitations::mailerKind(),
            // When the password is stored encrypted, it does not have to be typed to invite.
            'passwordStored' => Invitations::storedPassword($a) !== null,
            'outcome' => $request->session()->get('outcome'),
        ]);
    }

    public function saveTemplate(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $parsed = InviteTemplate::validate($request->input('subject', ''), $request->input('body', ''));
        if (! $parsed['ok']) {
            return back(303)->withErrors($parsed['errors']);
        }
        Invitations::saveTemplate($a->id, $parsed['data'], $request->attributes->get('admin')->id, $request->ip());

        return back(303)->with('outcome', ['templateSaved' => true]);
    }

    public function send(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $password = $this->passwordFor($a, $request);
        $mode = (string) $request->input('destinatarios', '');
        if (! in_array($mode, Invitations::RECIPIENT_MODES, true)) {
            $mode = 'sin_invitar';
        }

        if ($a->status !== 'publicado') {
            return back(303)->with('outcome', ['sendError' => 'Publique el assessment antes de enviar invitaciones.']);
        }
        if (! Invitations::checkAccessPassword($a, $password)) {
            return back(303)->with('outcome', ['sendError' => self::WRONG_PASSWORD]);
        }

        $result = Invitations::send($a, $mode, $password, $request->attributes->get('admin')->id, $request->ip());
        if ($result['total'] === 0) {
            return back(303)->with('outcome', ['sendError' => 'No hay participantes que cumplan el criterio seleccionado.']);
        }

        return back(303)->with('outcome', ['sendResult' => $result]);
    }

    public function copy(Request $request, string $id)
    {
        $a = AssessmentContext::require($id);
        $password = $this->passwordFor($a, $request);
        if (! Invitations::checkAccessPassword($a, $password)) {
            return back(303)->with('outcome', ['copyError' => self::WRONG_PASSWORD]);
        }

        return back(303)->with('outcome', ['generic' => Invitations::genericMessage($a, $password)]);
    }

    /** Password for the message: the one typed in the form or, when empty, the stored one. */
    private function passwordFor(Assessment $a, Request $request): string
    {
        $typed = (string) $request->input('password', '');

        return $typed !== '' ? $typed : (Invitations::storedPassword($a) ?? '');
    }
}
