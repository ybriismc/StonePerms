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
* This is the same store the Endstone build opens: same tables, same types,
* same collation, same revision row, same per-server user table named after
* the server's own id. A network can run both plugins over one database.
*
* Nothing is copied between servers and nothing is reconciled: there is a
* single set of rows that every server reads and writes. What travels between
* them is the revision in `storage_state`, which a server polls to learn that
* someone else changed something.
*
* The connection is opened through LibDB when its MySQL driver exposes a PDO
* handle the way the SQLite one does, and directly through PDO otherwise, so a
* version of the library without that driver still works.
*/
final class MysqlDialect implements SqlDialect {

  private const LOST_CONNECTION_CODES = [2002, 2003, 2006, 2013, 1053, 1927, 4031];
  private const LOCK_TIMEOUT_SECONDS = 10;

  private ?object $connection = null;
  private readonly string $usersTable;

  public function __construct(
    private readonly string $host,
    private readonly int $port,
    private readonly string $database,
    private readonly string $username,
    private readonly string $password,
    private readonly string $serverId,
    private readonly int $connectTimeout = 5,
    private readonly string $sslCa = ''
  ) {
    if (trim($this->host) === '' || trim($this->database) === '') {
      throw new RuntimeException('storage.mysql needs at least a host and a database name');
    }
    if (trim($this->serverId) === '') {
      throw new RuntimeException('storage.server_id must identify this server when using MySQL');
    }
    $this->usersTable = self::usersTableFor($this->serverId);
  }

  /**
  * The same name the Endstone build derives, so both plugins open the same
  * table for the same server.
  */
  public static function usersTableFor(string $serverId): string {
    return 'users_' . substr(hash('sha256', $serverId), 0, 24);
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
        'StonePerms needs the pdo_mysql PHP extension to use storage.backend: mysql'
      );
    }

    $pdo = $this->openThroughLibrary() ?? new PDO($this->dsn(), $this->username, $this->password, $this->options());
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    return $pdo;
  }

  /** @return array<int, mixed> */
  private function options(): array {
    $options = [
      PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
      PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
      PDO::ATTR_EMULATE_PREPARES => false,
      PDO::ATTR_TIMEOUT => $this->connectTimeout
    ];
    if ($this->sslCa !== '') {
      $options[PDO::MYSQL_ATTR_SSL_CA] = $this->sslCa;
      $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    }
    return $options;
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
        'charset' => 'utf8mb4'
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
      'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
      $this->host,
      $this->port,
      $this->database
    );
  }

  public function close(): void {
    if ($this->connection !== null && method_exists($this->connection, 'close')) {
      $this->connection->close();
    }
    $this->connection = null;
  }

  /**
  * Two servers starting together would otherwise race each other creating the
  * same tables, so the schema is built under a lock named after the database.
  */
  public function prepareStore(PDO $pdo): void {
    $lock = 'stoneperms.schema.' . substr(hash('sha256', $this->database), 0, 24);
    $acquired = $pdo->prepare('SELECT GET_LOCK(?, ?) AS acquired');
    $acquired->execute([$lock, self::LOCK_TIMEOUT_SECONDS]);
    if ((int) ($acquired->fetch()['acquired'] ?? 0) !== 1) {
      throw new RuntimeException('Could not acquire the StonePerms schema migration lock');
    }

    try {
      $pdo->exec(MysqlSchema::MIGRATIONS_TABLE);
      $current = (int) ($pdo->query('SELECT MAX(version) AS version FROM schema_migrations')
        ->fetch()['version'] ?? 0);
      if ($current > MysqlSchema::VERSION) {
        throw new RuntimeException(
          "StonePerms MySQL schema $current is newer than supported " . MysqlSchema::VERSION
        );
      }

      $migrations = MysqlSchema::migrations();
      foreach ($migrations[1] as $statement) {
        $pdo->exec($statement);
      }
      // A database from before a version already has its tables, so what it is
      // missing is applied on top instead of created.
      if ($current >= 1) {
        for ($version = $current + 1; $version <= MysqlSchema::VERSION; $version++) {
          foreach ($migrations[$version] ?? [] as $statement) {
            $pdo->exec($statement);
          }
        }
      }

      $pdo->exec(sprintf(MysqlSchema::USER_SCHEMA, $this->usersTable));
      $pdo->exec('INSERT IGNORE INTO storage_state(id, revision) VALUES (1, 0)');

      $pdo->beginTransaction();
      try {
        $version = $pdo->prepare('INSERT IGNORE INTO schema_migrations(version, applied_at) VALUES (?, ?)');
        $version->execute([MysqlSchema::VERSION, time()]);
        $server = $pdo->prepare('INSERT IGNORE INTO storage_servers(server_id, users_table) VALUES (?, ?)');
        $server->execute([$this->serverId, $this->usersTable]);
        $pdo->exec("UPDATE {$this->usersTable} SET online = 0 WHERE online <> 0");
        $pdo->commit();
      } catch (Throwable $throwable) {
        if ($pdo->inTransaction()) {
          $pdo->rollBack();
        }
        throw $throwable;
      }
    } finally {
      $release = $pdo->prepare('SELECT RELEASE_LOCK(?)');
      $release->execute([$lock]);
    }
  }

  public function usersTable(): string {
    return $this->usersTable;
  }

  public function insertGroupIfMissing(): string {
    return 'INSERT IGNORE INTO permission_groups(name, display_name, weight, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)';
  }

  public function insertTrackIfMissing(): string {
    return 'INSERT IGNORE INTO tracks(name, created_at, updated_at) VALUES (?, ?, ?)';
  }

  public function upsertUser(): string {
    return "INSERT INTO {$this->usersTable}(unique_id, xuid, last_name, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              xuid = VALUES(xuid),
              last_name = VALUES(last_name),
              updated_at = VALUES(updated_at)";
  }

  /**
  * The assignments are evaluated in order and each one is visible to the next,
  * so `skin_updated_at` — the only clause that compares the new skin with the
  * stored one — is written before `skin_hash` is overwritten.
  */
  public function upsertProfile(): string {
    $table = $this->usersTable;
    return "INSERT INTO $table(
              unique_id, xuid, last_name, created_at, updated_at,
              locale, device_os, game_version, game_mode, ping_ms, total_exp, exp_level,
              skin_id, skin_hash, skin_width, skin_height, skin_rgba, cape_id,
              first_seen_at, last_seen_at, last_joined_at, last_quit_at,
              skin_updated_at, online
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
              skin_updated_at = CASE
                WHEN VALUES(skin_hash) IS NOT NULL
                     AND ($table.skin_hash IS NULL OR VALUES(skin_hash) <> $table.skin_hash)
                THEN VALUES(skin_updated_at)
                ELSE $table.skin_updated_at
              END,
              xuid = COALESCE(VALUES(xuid), $table.xuid),
              last_name = VALUES(last_name),
              updated_at = VALUES(updated_at),
              locale = COALESCE(VALUES(locale), $table.locale),
              device_os = COALESCE(VALUES(device_os), $table.device_os),
              game_version = COALESCE(VALUES(game_version), $table.game_version),
              game_mode = COALESCE(VALUES(game_mode), $table.game_mode),
              ping_ms = COALESCE(VALUES(ping_ms), $table.ping_ms),
              total_exp = COALESCE(VALUES(total_exp), $table.total_exp),
              exp_level = COALESCE(VALUES(exp_level), $table.exp_level),
              skin_id = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(skin_id) ELSE $table.skin_id END,
              skin_width = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(skin_width) ELSE $table.skin_width END,
              skin_height = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(skin_height) ELSE $table.skin_height END,
              skin_rgba = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(skin_rgba) ELSE $table.skin_rgba END,
              cape_id = CASE WHEN VALUES(skin_hash) IS NOT NULL THEN VALUES(cape_id) ELSE $table.cape_id END,
              skin_hash = COALESCE(VALUES(skin_hash), $table.skin_hash),
              first_seen_at = COALESCE($table.first_seen_at, VALUES(first_seen_at)),
              last_seen_at = VALUES(last_seen_at),
              last_joined_at = COALESCE(VALUES(last_joined_at), $table.last_joined_at),
              last_quit_at = COALESCE(VALUES(last_quit_at), $table.last_quit_at),
              online = VALUES(online)";
  }

  /**
  * The unique index would refuse this too, but with an error nobody can read.
  */
  public function validateIdentity(PDO $pdo, string $uniqueId, ?string $xuid): void {
    if ($xuid === null) {
      return;
    }
    $statement = $pdo->prepare("SELECT unique_id FROM {$this->usersTable} WHERE xuid = ?");
    $statement->execute([$xuid]);
    $row = $statement->fetch();
    if (is_array($row) && (string) $row['unique_id'] !== $uniqueId) {
      throw new RuntimeException('This XUID is already associated with another player UUID');
    }
  }

  public function bumpRevision(PDO $pdo, int $current): int {
    $pdo->exec('UPDATE storage_state SET revision = revision + 1 WHERE id = 1');
    return $this->readRevision($pdo, $current + 1);
  }

  public function readRevision(PDO $pdo, int $current): int {
    $row = $pdo->query('SELECT revision FROM storage_state WHERE id = 1')->fetch();
    if (!is_array($row) || !isset($row['revision'])) {
      return $current;
    }
    return (int) $row['revision'];
  }

  public function lockRevision(PDO $pdo, int $current): int {
    $row = $pdo->query('SELECT revision FROM storage_state WHERE id = 1 FOR UPDATE')->fetch();
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
