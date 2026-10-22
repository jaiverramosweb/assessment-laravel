<?php

namespace Tests\Feature\Invitations;

use App\Services\Invitations\Invitations;
use App\Services\ParticipantFlow\Dates;
use Tests\TestCase;

class InviteDateFormatTest extends TestCase
{
    public function test_invitation_dates_use_the_original_long_format(): void
    {
        // 2026-11-01 13:00 UTC = 8:00 a. m. in Bogotá.
        $ms = gmmktime(13, 0, 0, 11, 1, 2026) * 1000;

        $text = Invitations::formatLongDateTime($ms, 'America/Bogota');

        $this->assertSame("1 de noviembre de 2026 a las 8:00\u{00A0}a.\u{00A0}m.", $text);
        $this->assertSame(Dates::long($ms, 'America/Bogota'), $text);
    }
}
