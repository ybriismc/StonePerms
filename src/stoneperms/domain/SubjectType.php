<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* The two kinds of subject that can own permission nodes.
*/
enum SubjectType: string {
  case USER = 'user';
  case GROUP = 'group';
}
