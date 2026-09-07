<?php

declare(strict_types = 1);

namespace stoneperms\domain;

/**
* Outcome of a promote or demote attempt.
*/
enum TrackMoveStatus: string {
  case SUCCESS = 'success';
  case ADDED_TO_FIRST_GROUP = 'added_to_first_group';
  case REMOVED_FROM_FIRST_GROUP = 'removed_from_first_group';
  case NOT_ON_TRACK = 'not_on_track';
  case END_OF_TRACK = 'end_of_track';
  case FIRST_GROUP_PROTECTED = 'first_group_protected';
  case AMBIGUOUS_CALL = 'ambiguous_call';
}
