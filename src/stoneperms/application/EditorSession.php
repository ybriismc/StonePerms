<?php

declare(strict_types = 1);

namespace stoneperms\application;

use stoneperms\domain\Node;

/**
* One open editing window over a captured slice of the permission data.
*/
final class EditorSession {

  public string $status = 'open';

  /**
  * @param array<string, list<Node>> $subjects keyed by SubjectRef::key()
  * @param array<string, list<string>> $tracks keyed by track name
  * @param array<string, mixed> $document
  */
  public function __construct(
    public readonly string $sessionId,
    public readonly string $actor,
    public readonly int $baseRevision,
    public readonly int $createdAt,
    public readonly int $expiresAt,
    public readonly array $subjects,
    public readonly array $tracks,
    public readonly array $document
  ) {}
}
