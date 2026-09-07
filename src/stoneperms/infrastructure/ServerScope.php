<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

use stoneperms\domain\SubjectRef;
use stoneperms\domain\SubjectType;

/**
* Which rows in a shared database belong to this server.
*
* Groups, tracks and everything hanging off a group are the network's: one
* definition, seen by every server. What a *player* has is the server's own —
* the rows in `users` and the nodes attached to a user — so a player who is VIP
* on the lobby arrives at a minigame as whatever that server says, usually the
* default group.
*
* The split is a column, not a second set of tables: one schema, one
* transaction, one revision, and a query that names its server. A row a server
* did not write is not merely hidden from it — no statement it runs can read
* or delete one.
*
* The scope is off for SQLite, which is one server's file and needs no
* dividing, and off when a network asks for its players to be shared, where
* every row simply carries the same empty owner.
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

  /** Appended to a query against `users`, which is per server outright. */
  public function userFilter(): string {
    return $this->isEnabled() ? ' AND server = ?' : '';
  }

  /** The same, for a query that has no WHERE of its own yet. */
  public function userWhere(): string {
    return $this->isEnabled() ? ' WHERE server = ?' : '';
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

  /** @return list<string> */
  public function auditParams(): array {
    return $this->isEnabled() ? [$this->server] : [];
  }
}
