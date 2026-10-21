<?php

namespace App\Services\Participants;

use RuntimeException;

class ParticipantHasResponsesError extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('No se puede eliminar: este participante ya empezó a responder.');
    }
}
