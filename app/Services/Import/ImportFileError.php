<?php

namespace App\Services\Import;

use RuntimeException;

/** An uploaded import file cannot be read; the message is shown to the admin. */
class ImportFileError extends RuntimeException {}
