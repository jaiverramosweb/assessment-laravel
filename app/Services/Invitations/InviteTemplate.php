<?php

namespace App\Services\Invitations;

/**
 * Invitation template with {{variables}}. Server side of resources/js/lib/invite-template.ts
 * (the UI keeps the TS version for the live preview): keep both in sync.
 */
final class InviteTemplate
{
    public const PLACEHOLDERS = [
        'nombre' => 'Nombre del participante',
        'titulo' => 'Título del assessment',
        'descripcion' => 'Descripción del assessment',
        'enlace' => 'Enlace de ingreso',
        'contrasena' => 'Contraseña de acceso',
        'inicio' => 'Fecha y hora de inicio',
        'fin' => 'Fecha y hora de finalización',
        'zona_horaria' => 'Zona horaria',
    ];

    public const DEFAULT_SUBJECT = 'Invitación: {{titulo}}';

    public const DEFAULT_BODY = <<<'TXT'
Hola {{nombre}},

Le invitamos a responder el assessment "{{titulo}}".

Enlace: {{enlace}}
Contraseña de acceso: {{contrasena}}

Disponible desde el {{inicio}} hasta el {{fin}} (hora de {{zona_horaria}}).

Para ingresar, abra el enlace, escriba la contraseña y su correo electrónico. Puede salir y volver a entrar: sus respuestas se guardan automáticamente hasta que las entregue.

Gracias por su participación.
TXT;

    /** Variables the body must contain. */
    private const REQUIRED = ['enlace', 'contrasena'];

    /**
     * Validates subject and body (trimmed).
     * `errors` holds the first message per field; `issues` every message in order.
     *
     * @return array{ok: true, data: array{subject: string, body: string}}|array{ok: false, errors: array<string, string>, issues: list<string>}
     */
    public static function validate(mixed $subject, mixed $body): array
    {
        $subject = trim((string) $subject);
        $body = trim((string) $body);

        $issues = [];
        if ($subject === '') {
            $issues[] = ['subject', 'Escriba el asunto.'];
        } elseif (mb_strlen($subject) > 200) {
            $issues[] = ['subject', 'El asunto no puede superar 200 caracteres.'];
        }
        if ($body === '') {
            $issues[] = ['body', 'Escriba el mensaje.'];
        } elseif (mb_strlen($body) > 5000) {
            $issues[] = ['body', 'El mensaje no puede superar 5.000 caracteres.'];
        }
        // Like the original schema, the required variables are checked once the fields are valid.
        if (! $issues) {
            foreach (self::REQUIRED as $key) {
                if (! preg_match('/\{\{\s*'.$key.'\s*\}\}/', $body)) {
                    $issues[] = ['body', "El mensaje debe incluir {{{$key}}}."];
                }
            }
        }

        if ($issues) {
            $errors = [];
            foreach ($issues as [$field, $message]) {
                $errors[$field] ??= $message;
            }

            return ['ok' => false, 'errors' => $errors, 'issues' => array_column($issues, 1)];
        }

        return ['ok' => true, 'data' => ['subject' => $subject, 'body' => $body]];
    }

    /** Replaces {{variable}}. Unknown variables are left as they are. */
    public static function render(string $template, array $vars): string
    {
        return preg_replace_callback(
            '/\{\{\s*(\w+)\s*\}\}/',
            fn ($m) => array_key_exists($m[1], $vars) ? (string) $vars[$m[1]] : $m[0],
            $template,
        );
    }

    /** Generic message (without name), to copy and send by another channel. */
    public static function renderGeneric(string $template, array $vars): string
    {
        // "Hola {{nombre}}," → "Hola,"
        return preg_replace('/[ \t]+([,.;:])/', '$1', self::render($template, [...$vars, 'nombre' => '']));
    }

    /**
     * HTML version of the email: all text is escaped (including what the admin wrote or the
     * participant's name), links become clickable and line breaks <br>.
     */
    public static function textToHtml(string $text): string
    {
        $body = htmlspecialchars($text, ENT_QUOTES | ENT_HTML5 | ENT_SUBSTITUTE, 'UTF-8');
        $body = str_replace('&apos;', '&#39;', $body);
        $body = preg_replace('/(https?:\/\/[^\s<]+)/', '<a href="$1">$1</a>', $body);
        $body = preg_replace('/\r?\n/', '<br>', $body);

        return '<div style="font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.5;color:#1e293b">'.$body.'</div>';
    }
}
