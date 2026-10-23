<?php

namespace App\Services\Ai;

use RuntimeException;

/** A provider's model list could not be read (Spanish message with a `:proveedor` placeholder). */
final class ModelListError extends RuntimeException {}
