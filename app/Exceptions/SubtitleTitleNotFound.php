<?php

namespace App\Exceptions;

use RuntimeException;

/** The online subtitle service has no entry for this title (as opposed to the service being unreachable). */
class SubtitleTitleNotFound extends RuntimeException {}
