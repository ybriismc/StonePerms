<?php

declare(strict_types = 1);

namespace stoneperms\infrastructure;

use PDO;
use PDOException;
use RuntimeException;
use Throwable;

/**
* MySQL or MariaDB, shared by every server pointed at the same database.
*
* Nothing is copied between servers and nothing is synchronised: there is one
* set of data and each server reads and writes it. What travels between them is
* the revision in `shared_state`, which a server polls to learn that someone
* else changed something and its cached snapshots are out of date.
*
* The connection is opened through LibDB when its MySQL driver exposes a PDO
* handle the way the SQLite one does, and directly through PDO otherwise, so a
* version of the library without that driver still works.
*/
final class MysqlDialect implements SqlDialect {

  private const LOST_CONNECTION_CODES = [2002, 2003, 2006, 2013, 1053, 1927, 4031];

  private ?object $connection = null;
  private readonly ServerScope $scope;

  public function __construct(
    private readonly string $host,
    private readonly int $port,
    private readonly string $database,
    private readonly string $username,
    private readonly string $password,
    private readonly string $charset = 'utf8mb4',
    ?ServerScope $scope = null
  ) {
    if (trim($this->host) === '' || trim($this->database) === '') {
      throw new RuntimeException('storage.mysql needs at least a host and a database name');
    }
    // Without a scope the store behaves as it did before players could belong
    // to a server: every row carries no owner and every server sees them all.
    $this->scope = $scope ?? ServerScope::shared();
  }

  public function name(): string {
    return 'mysql';
  }

  public function describe(): string {
    return 'MySQL (' . $this->host . ':' . $this->port . '/' . $this->database . ')';
  }

  public function isShared(): bool {
    return true;
  }

  public function open(): PDO {
    if (!extension_loaded('pdo_mysql')) {
      throw new RuntimeException(
        'StonePerms needs the pdo_mysql PHP extension to use storage.driver: mysql'
      );
    }

    $pdo = $this->openThroughLibrary() ?? new PDO(
      $this->dsn(),
      $this->username,
      $this->password,
      [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_TIMEOUT => 5
      ]
    );

    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

    // Strict mode so an oversized value is refused instead of being silently
    // truncated, and READ COMMITTED so a poll sees what the last commit wrote.
    $pdo->exec("SET SESSION sql_mode = 'STRICT_ALL_TABLES,NO_ENGINE_SUBSTITUTION'");
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL READ COMMITTED');

    return $pdo;
  }

  private function openThroughLibrary(): ?PDO {
    if (!class_exists('imperazim\db\DBManager')) {
      return null;
    }
    try {
      /** @var mixed $connection */
      $connection = \imperazim\db\DBManager::connect('mysql', [
        'host' => $this->host,
        'port' => $this->port,
        'database' => $this->database,
        'username' => $this->username,
        'password' => $this->password,
        'charset' => $this->charset
      ]);
    } catch (Throwable) {
      return null;
    }
    if (!is_object($connection) || !method_exists($connection, 'getPdo')) {
      return null;
    }
    $pdo = $connection->getPdo();
    if (!$pdo instanceof PDO) {
      return null;
    }
    $this->connection = $connection;
    return $pdo;
  }

  private function dsn(): string {
    return sprintf(
      'mysql:host=%s;port=%d;dbname=%s;charset=%s',
      $this->host,
      $this->port,
      $this->database,
      $this->charset
    );
  }

  public function close(): void {
    if ($this->connection !== null && method_exists($this->connection, 'close')) {
      $this->connection->close();
    }
    $this->connection = null;
  }

  public function migrations(): array {
    return MysqlSchema::migrations();
  }

  public function insertGroupIfMissing(): string {
    return 'INSERT IGNORE INTO permission_groups(name, display_name, weight, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)';
  }

  public function insertTrackIfMissing(): string {
    return 'INSERT IGNORE INTO tracks(name, created_at, updated_at) VALUES (?, ?, ?)';
  }

  public function upsertUser(): string {
    return 'INSERT INTO users(unique_id, xuid, last_name, created_at, updated_at' . $this->scope->column() . ')
            VALUES (?, ?, ?, ?, ?' . $this->scope->placeholder() . ')
            ON DUPLICATE KEY UPDATE
              xuid = VALUES(xuid),
              last_name = VALUES(last_name),
              updated_at = VALUES(updated_at)';
  }

  /**
  * The assignments are evaluated in order and each one is visible to the next,
  * so `skin_updated_at` — the only clause that compares the new skin with the
  * stored one — is written before `skin_hash` is overwritten. Every other
  * clause reads a column nothing else assigns, so their order does not matter.
  */
  public function upsertProfile(): string {
    return 'INSERT INTO users(
              unique_id, xuid, last_name, created_at, updated_at,
              locale, device_os, game_version, game_mode, ping_ms, total_exp, exp_level,
              skin_id, skin_hash, skin_width, skin_height, skin_rgba, cape_id,
              first_seen_at, last_seen_at, last_joined_at, last_quit_at,
              skin_updated_at, online' . $this->scope->column() . '
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?'
            . $this->scope->placeholder() . ')
            ON DUPLICATE KEY UPDATE
              skin_updated_at = CASE
                WHEN VALUES(skin_hash) IS NOT NULL
                     AND (users.skin_hash IS NULL OR VALUES(skin_hash) <> users.skin_hash)
                THEN VALUES(skin_updated_at)
                ELSE users.skin_updated_at
              END,
              xuid = COALESCE(VALUES(xuid), users.xuid),
              last_name = VALUES(last_name),
              updated_at = VALUES(updated_at),
              locale = COALESCE(VALUES(locale), users.locale),
              device_os = COALESCE(VALUES(device_os), users.device_os),
              game_version = COALESCE(VALUES(game_version), users.game_version),
              game_mode = COALESCE(VALUES(game_mode), users.game_mode),
              ping_ms = COALESCE(VALUES(ping_ms), users.ping_ms),
              total_exp = COALESCE(VALUES(total_exp), users.total_exp),
              exp_level = COALESCE(VALUES(exp_level), users.exp_level),
              skin_id = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(skin_id) ELSE users.skin_id END,
              skin_width = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(skin_width) ELSE users.skin_width END,
              skin_height = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(skin_height) ELSE users.skin_height END,
              skin_rgba = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(skin_rgba) ELSE users.skin_rgba END,
              cape_id = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(cape_id) ELSE users.cape_id END,
              skin_hash = COALESCE(VALUES(skin_hash), users.skin_hash),
              first_seen_at = COALESCE(users.first_seen_at, VALUES(first_seen_at)),
              last_seen_at = VALUES(last_seen_at),
              last_joined_at = COALESCE(VALUES(last_joined_at), users.last_joined_at),
              last_quit_at = COALESCE(VALUES(last_quit_at), users.last_quit_at),
              online = VALUES(online)';
  }

  /** The columns are declared with a case-insensitive collation. */
  public function caseInsensitive(): string {
    return '';
  }

  /**
  * The update takes a row lock that is held until the transaction commits, so
  * two servers writing at the same time are serialised here: revisions come
  * out in the same order the changes commit, with no gap for a poll to miss.
  */
  public function bumpRevision(PDO $pdo, int $current): int {
    $pdo->exec('UPDATE shared_state SET revision = revision + 1 WHERE id = 1');
    return $this->readRevision($pdo, $current + 1);
  }

  public function readRevision(PDO $pdo, int $current): int {
    $row = $pdo->query('SELECT revision FROM shared_state WHERE id = 1')->fetch();
    if (!is_array($row) || !isset($row['revision'])) {
      return $current;
    }
    return (int) $row['revision'];
  }

  public function lockRevision(PDO $pdo, int $current): int {
    $row = $pdo->query('SELECT revision FROM shared_state WHERE id = 1 FOR UPDATE')->fetch();
    if (!is_array($row) || !isset($row['revision'])) {
      return $current;
    }
    return (int) $row['revision'];
  }

  public function isLostConnection(Throwable $error): bool {
    if (!$error instanceof PDOException) {
      return false;
    }
    $code = $error->errorInfo[1] ?? null;
    if (is_int($code) && in_array($code, self::LOST_CONNECTION_CODES, true)) {
      return true;
    }
    $message = strtolower($error->getMessage());
    return str_contains($message, 'gone away')
      || str_contains($message, 'lost connection')
      || str_contains($message, 'broken pipe');
  }
}
