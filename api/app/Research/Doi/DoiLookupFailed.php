<?php

namespace App\Research\Doi;

use RuntimeException;

/** The registry did not answer (timeout, outage); distinct from "no such DOI". */
class DoiLookupFailed extends RuntimeException {}
