<?php

namespace App\Services\Participants;

use RuntimeException;

class ParticipantExistsError extends RuntimeException
{
    public function __construct(string $email)
    {
        parent::__construct("Ya existe un participante con el email {$email}.");
    }
}
