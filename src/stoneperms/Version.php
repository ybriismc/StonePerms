<?php

declare(strict_types = 1);

namespace stoneperms;

/**
* This build's own version, reported to the dashboard.
*
* It does not track the Endstone build's number: the two share a database and
* a protocol, not a release line, so each one is versioned where it lives.
*/
final class Version {

  public const VERSION = '1.1.0';
}
