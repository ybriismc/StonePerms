<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* One entry of the resolved inheritance order: the subject, how far it sits
* from the user, and the weight it carries when breaking ties.
*/
final class InheritedSubject {

  public function __construct(
    public readonly SubjectRef $subject,
    public readonly int $distance,
    public readonly int $groupWeight
  ) {}
}
