<?php

declare(strict_types = 1);

namespace stoneperms\web;

use RuntimeException;

/**
* Any failure talking to the API: transport, HTTP status, or malformed JSON.
*/
final class WebRequestException extends RuntimeException {}
