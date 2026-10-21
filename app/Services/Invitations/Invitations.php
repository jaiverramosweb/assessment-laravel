<?php

namespace App\Services\Invitations;

use App\Auth\Passwords;
use App\Mail\InvitationMail;
use App\Models\Assessment;
use App\Security\Audit;
use App\Security\SecretBox;
use App\Services\Participants\Participants;
use Closure;
use IntlDateFormatter;
use Illuminate\Support\Facades\Mail;
use Throwable;

/** Invitation template, recipients, email sending and the generic message to copy. */
final class Invitations
{
    public const RECIPIENT_MODES = ['sin_invitar', 'no_completados'];

    /** @return array{subject: string, body: string} */
    public static function template(Assessment $a): array
    {
        return [
            'subject' => $a->invite_subject ?: InviteTemplate::DEFAULT_SUBJECT,
            'body' => $a->invite_body ?: InviteTemplate::DEFAULT_BODY,
        ];
    }

    /** @param array{subject: string, body: string} $tpl validated by InviteTemplate::validate */
    public static function saveTemplate(string $assessmentId, array $tpl, string $adminId, ?string $ip = null): void
    {
        Assessment::whereKey($assessmentId)->firstOrFail()
            ->update(['invite_subject' => $tpl['subject'], 'invite_body' => $tpl['body']]);
        Audit::log($adminId, 'assessment.editado', 'assessment', $assessmentId, ['cambio' => 'plantilla de invitación'], $ip);
    }

    public static function publicLink(string $appUrl, string $slug): string
    {
        return rtrim($appUrl, '/')."/a/{$slug}";
    }

    /** Variables shared by every recipient (all but `nombre`). */
    public static function baseVars(Assessment $a, string $password, ?string $appUrl = null): array
    {
        return [
            'titulo' => $a->title,
            'descripcion' => (string) $a->description,
            'enlace' => self::publicLink($appUrl ?? (string) config('app.url'), $a->slug),
            'contrasena' => $password,
            'inicio' => self::formatLongDateTime($a->starts_at, $a->timezone),
            'fin' => self::formatLongDateTime($a->ends_at, $a->timezone),
            'zona_horaria' => str_replace('_', ' ', $a->timezone),
        ];
    }

    /** "1 de noviembre de 2026, 8:00 a. m." in the assessment's time zone (es-CO). */
    public static function formatLongDateTime(int $ms, string $timezone): string
    {
        $formatter = new IntlDateFormatter('es_CO', IntlDateFormatter::LONG, IntlDateFormatter::SHORT, $timezone);
        $text = (string) $formatter->format(intdiv($ms, 1000));

        // Non-breaking spaces so "6:00 p. m." is not split across lines.
        return preg_replace('/[\s\x{202F}\x{00A0}]([ap])\.[\s\x{00A0}]?m\./u', "\u{00A0}$1.\u{00A0}m.", $text);
    }

    /** Current access password when it is stored encrypted; null otherwise. */
    public static function storedPassword(Assessment $a): ?string
    {
        return $a->access_password_enc ? SecretBox::decrypt($a->access_password_enc) : null;
    }

    /** The typed (or stored) password must match the current access password hash. */
    public static function checkAccessPassword(Assessment $a, string $password): bool
    {
        if (! $a->access_password_hash || $password === '') {
            return false;
        }

        return Passwords::verify($a->access_password_hash, $password);
    }

    /** @param list<array> $list rows from Participants::listParticipants */
    public static function selectRecipients(array $list, string $mode): array
    {
        return array_values(array_filter($list, fn ($p) => $mode === 'sin_invitar'
            ? ! $p['invitedAt'] && $p['status'] !== 'completado'
            : $p['status'] !== 'completado'));
    }

    /**
     * Generic message to copy (WhatsApp, Teams, etc.).
     *
     * @return array{subject: string, body: string}
     */
    public static function genericMessage(Assessment $a, string $password, ?string $appUrl = null): array
    {
        $tpl = self::template($a);
        $vars = self::baseVars($a, $password, $appUrl);

        return [
            'subject' => InviteTemplate::renderGeneric($tpl['subject'], $vars),
            'body' => InviteTemplate::renderGeneric($tpl['body'], $vars),
        ];
    }

    /** "smtp" really sends; "consola" only writes the email to the log (development). */
    public static function mailerKind(): string
    {
        $mailer = (string) config('mail.default');
        $transport = config("mail.mailers.{$mailer}.transport", $mailer);

        return in_array($transport, ['log', 'array'], true) ? 'consola' : 'smtp';
    }

    /**
     * Sends the invitations one by one and records the result of each email. A failed
     * recipient never aborts the batch.
     *
     * @param  Closure(string $to, string $subject, string $text, string $html): void|null  $deliver
     * @return array{sent: int, failed: int, total: int}
     */
    public static function send(
        Assessment $a,
        string $mode,
        string $password,
        string $adminId,
        ?string $ip = null,
        ?string $appUrl = null,
        ?Closure $deliver = null,
    ): array {
        $deliver ??= fn (string $to, string $subject, string $text, string $html) => Mail::to($to)->send(new InvitationMail($subject, $text, $html));
        $recipients = self::selectRecipients(Participants::listParticipants($a->id), $mode);
        $tpl = self::template($a);
        $vars = self::baseVars($a, $password, $appUrl);

        $results = [];
        foreach ($recipients as $p) {
            $all = [...$vars, 'nombre' => $p['name']];
            $text = InviteTemplate::render($tpl['body'], $all);
            try {
                $deliver($p['email'], InviteTemplate::render($tpl['subject'], $all), $text, InviteTemplate::textToHtml($text));
                $results[] = ['participantId' => $p['id'], 'ok' => true];
            } catch (Throwable $e) {
                $results[] = ['participantId' => $p['id'], 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        Participants::recordEmailResults($results, $adminId);
        $sent = count(array_filter($results, fn ($r) => $r['ok']));
        $failed = count($results) - $sent;
        Audit::log($adminId, 'invitaciones.enviadas', 'assessment', $a->id, [
            'enviados' => $sent, 'fallidos' => $failed, 'destinatarios' => $mode, 'via' => self::mailerKind(),
        ], $ip);

        return ['sent' => $sent, 'failed' => $failed, 'total' => count($results)];
    }
}
