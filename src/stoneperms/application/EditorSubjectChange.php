<?php

declare(strict_types = 1);

namespace stoneperms\application;

use stoneperms\domain\Node;
use stoneperms\domain\SubjectRef;

/**
* The complete before and after node list for one subject in a changeset.
*/
final class EditorSubjectChange {

  /**
  * @param list<Node> $before
  * @param list<Node> $after
  */
  public function __construct(
    public readonly SubjectRef $subject,
    public readonly array $before,
    public readonly array $after
  ) {}
}
