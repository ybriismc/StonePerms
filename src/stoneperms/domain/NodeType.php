<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* Every kind of value a subject can store.
*/
enum NodeType: string {
  case PERMISSION = 'permission';
  case PARENT = 'parent';
  case META = 'meta';
  case PREFIX = 'prefix';
  case SUFFIX = 'suffix';
}
