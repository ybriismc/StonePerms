<?php

declare(strict_types = 1);

namespace stoneperms\application;

use RuntimeException;

/**
* Raised when a changeset was built against a revision that storage has since
* moved past. The dashboard reloads and retries.
*/
final class RevisionConflictException extends RuntimeException {}
