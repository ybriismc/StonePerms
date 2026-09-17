<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

use stoneperms\domain\SubjectRef;
use stoneperms\domain\SubjectType;

/**
* Which nodes in a shared database belong to this server.
*
* Groups, tracks and everything hanging off a group are the network's: one
* definition, seen by every server. What a *player* was given is the server's
* own, so somebody who is VIP on the lobby arrives at a minigame as whatever
* that server gives them, usually the default group.
*
* A player's rows live in this server's own user table; the nodes given to
* them sit in the shared table and name their server in a column. A node a
* server did not write is not merely hidden from it — no statement it runs can
* read or delete one.
*
* The scope is off for SQLite, which is one server's file and needs no
* dividing.
*/
final class ServerScope {

  private function __construct(public readonly string $server) {}

  /** Every server sees every player: the arrangement a single server has. */
  public static function shared(): self {
    return new self('');
  }

  /** Players belong to the server that saw them. */
  public static function of(string $server): self {
    $name = trim($server);
    return $name === '' ? self::shared() : new self($name);
  }

  public function isEnabled(): bool {
    return $this->server !== '';
  }

  /**
  * Appended to a query against `nodes`, where the network's rows and this
  * server's rows sit together: a group's nodes carry no owner and are read by
  * everyone, a user's carry this server's name and are read by nobody else.
  */
  public function nodeFilter(): string {
    return $this->isEnabled() ? " AND server IN ('', ?)" : '';
  }

  /** @return list<string> */
  public function params(): array {
    return $this->isEnabled() ? [$this->server] : [];
  }

  /** `, server` for an insert that has to say who a row belongs to. */
  public function column(): string {
    return $this->isEnabled() ? ', server' : '';
  }

  public function placeholder(): string {
    return $this->isEnabled() ? ', ?' : '';
  }

  /**
  * The owner to store for a row about this subject: this server for a user,
  * nobody for a group, because a group belongs to the network.
  *
  * @return list<string>
  */
  public function ownerParams(SubjectRef $subject): array {
    if (!$this->isEnabled()) {
      return [];
    }
    return [$subject->type === SubjectType::USER ? $this->server : ''];
  }
}
