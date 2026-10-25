<?php

namespace App\Commerce\Print;

use RuntimeException;

/** Lulu refused, failed or could not be reached; the message says which. */
class LuluException extends RuntimeException {}
