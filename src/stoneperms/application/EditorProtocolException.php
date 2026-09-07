<?php

declare(strict_types = 1);

namespace stoneperms\application;

use InvalidArgumentException;

/**
* Base for every rejection the editor protocol can report to the dashboard.
*/
class EditorProtocolException extends InvalidArgumentException {}
