<?php

namespace App\Services\Clients;

use RuntimeException;

/** A client with assessments cannot be deleted. */
class ClientInUseError extends RuntimeException {}
