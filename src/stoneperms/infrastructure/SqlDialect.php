<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

use PDO;
use Throwable;

/**
* What changes between one database engine and another.
*
* The store is written once. Everything it does — encoding nodes, loading a
* snapshot, applying an editor batch, writing the audit log — is the same SQL
* on every engine except the handful of statements below, and each engine
* supplies its own wording for those.
*
* The engine also decides what a revision is. On a database used by one server
* a counter in memory is enough. On a shared one the number has to live in the
* database, because it is how the other servers learn that something changed.
*/
interface SqlDialect {

  /** `sqlite` or `mysql`, as written in the config. */
  public function name(): string;

  /** One line for the startup report: engine and where it points. */
  public function describe(): string;

  /** Whether more than one server can be pointed at this store. */
  public function isShared(): bool;

  public function open(): PDO;

  public function close(): void;

  /**
  * Schema versions in order, each a list of statements applied in one go.
  *
  * @return array<int, list<string>>
  */
  public function migrations(): array;

  public function insertGroupIfMissing(): string;

  public function insertTrackIfMissing(): string;

  public function upsertUser(): string;

  public function upsertProfile(): string;

  /**
  * Appended to a comparison or an ordering that must ignore case. SQLite needs
  * it spelled out; MySQL gets it from the column's collation.
  */
  public function caseInsensitive(): string;

  /**
  * Records that data changed and returns the new revision. Called inside the
  * transaction that made the change, so the number and the change commit
  * together or not at all.
  */
  public function bumpRevision(PDO $pdo, int $current): int;

  /** The revision as the database has it, for a server that did not write it. */
  public function readRevision(PDO $pdo, int $current): int;

  /**
  * Whether this failure means the connection died rather than the statement
  * being wrong. Only those are worth retrying.
  */
  public function isLostConnection(Throwable $error): bool;
}
