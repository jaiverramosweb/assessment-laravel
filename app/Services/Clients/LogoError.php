<?php

namespace App\Services\Clients;

use RuntimeException;

/** The uploaded logo is missing, too large or not a supported image. */
class LogoError extends RuntimeException {}
