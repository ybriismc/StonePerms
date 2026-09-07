<?php

declare(strict_types = 1);

namespace stoneperms\application;

use RuntimeException;

/**
* Raised when a group, track or player that must already exist does not.
*/
final class UnknownSubjectException extends RuntimeException {}
