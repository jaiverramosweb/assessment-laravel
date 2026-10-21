<?php

namespace App\Services\ParticipantFlow;

use RuntimeException;

final class AlreadySubmittedException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('Este assessment ya fue entregado.');
    }
}
