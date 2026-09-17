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
* on every engine except what is here: the connection, the schema, a handful
* of upserts, which table holds this server's players, and what a revision is.
*
* On a file the revision is a counter in memory. On a shared database it lives
* in a row, because it is how the other servers learn that something changed.
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

  /** Creates or upgrades the schema, and whatever this server needs of its own. */
  public function prepareStore(PDO $pdo): void;

  /** The table holding this server's players. */
  public function usersTable(): string;

  public function insertGroupIfMissing(): string;

  public function insertTrackIfMissing(): string;

  public function upsertUser(): string;

  public function upsertProfile(): string;

  /**
  * Refuses an XUID that already belongs to another player, where the engine
  * cannot say so itself.
  */
  public function validateIdentity(PDO $pdo, string $uniqueId, ?string $xuid): void;

  /**
  * Records that data changed and returns the new revision. Called inside the
  * transaction that made the change, so the number and the change commit
  * together or not at all.
  */
  public function bumpRevision(PDO $pdo, int $current): int;

  /** The revision as the database has it, for a server that did not write it. */
  public function readRevision(PDO $pdo, int $current): int;

  /**
  * The revision, held against other writers until this transaction ends. Taken
  * at the start of every write, so two servers writing at once are serialised
  * and each one sees what the other committed.
  */
  public function lockRevision(PDO $pdo, int $current): int;

  /**
  * Whether this failure means the connection died rather than the statement
  * being wrong. Only those are worth retrying.
  */
  public function isLostConnection(Throwable $error): bool;
}
