<?php

namespace Tests\Feature\Invitations;

use App\Services\Invitations\InviteTemplate;
use PHPUnit\Framework\TestCase;

/** Port of the "plantilla de invitación" cases of the original participants.spec.ts. */
class InviteTemplateTest extends TestCase
{
    public function test_replaces_variables_and_keeps_unknown_ones(): void
    {
        $this->assertSame('Hola Ana, {{otra}}', InviteTemplate::render('Hola {{ nombre }}, {{otra}}', ['nombre' => 'Ana']));
    }

    public function test_generic_message_does_not_leave_hola_comma(): void
    {
        $vars = [
            'titulo' => 'T', 'descripcion' => '', 'enlace' => 'http://x/a/abc', 'contrasena' => 'k',
            'inicio' => 'i', 'fin' => 'f', 'zona_horaria' => 'z',
        ];
        $this->assertSame("Hola,\nhttp://x/a/abc", InviteTemplate::renderGeneric("Hola {{nombre}},\n{{enlace}}", $vars));
    }

    public function test_html_escapes_content_and_links_urls(): void
    {
        $html = InviteTemplate::textToHtml("Hola <script>alert(1)</script>\nhttps://x.co/a/abc");
        $this->assertStringNotContainsString('<script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('<a href="https://x.co/a/abc">', $html);
        $this->assertStringContainsString('<br>', $html);
    }

    public function test_body_must_include_enlace_and_contrasena(): void
    {
        $r = InviteTemplate::validate('S', 'Hola {{nombre}}');
        $this->assertFalse($r['ok']);
        $this->assertSame(['El mensaje debe incluir {{enlace}}.', 'El mensaje debe incluir {{contrasena}}.'], $r['issues']);
        $this->assertSame(['body' => 'El mensaje debe incluir {{enlace}}.'], $r['errors']);
    }

    public function test_subject_and_body_limits_and_trim(): void
    {
        $this->assertSame(
            ['subject' => 'Escriba el asunto.', 'body' => 'Escriba el mensaje.'],
            InviteTemplate::validate('  ', '')['errors'],
        );
        $this->assertSame(
            ['subject' => 'El asunto no puede superar 200 caracteres.', 'body' => 'El mensaje no puede superar 5.000 caracteres.'],
            InviteTemplate::validate(str_repeat('s', 201), str_repeat('b', 5001))['errors'],
        );
        $ok = InviteTemplate::validate(' Hola ', " {{enlace}} {{ contrasena }} \n");
        $this->assertTrue($ok['ok']);
        $this->assertSame(['subject' => 'Hola', 'body' => '{{enlace}} {{ contrasena }}'], $ok['data']);
    }

    public function test_default_template_is_valid(): void
    {
        $this->assertTrue(InviteTemplate::validate(InviteTemplate::DEFAULT_SUBJECT, InviteTemplate::DEFAULT_BODY)['ok']);
    }
}
