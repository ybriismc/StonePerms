<?php

declare(strict_types = 1);

namespace stoneperms\application;

/**
* Reported to the dashboard as EDITOR_ACTOR_MISMATCH: the session belongs to a
* different dashboard account than the one applying the changeset.
*/
final class EditorActorMismatchException extends EditorProtocolException {}
