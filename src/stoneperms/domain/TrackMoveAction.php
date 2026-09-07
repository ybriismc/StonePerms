<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* Direction of a track movement.
*/
enum TrackMoveAction: string {
  case PROMOTE = 'promote';
  case DEMOTE = 'demote';
}
