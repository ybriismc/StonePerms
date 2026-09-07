<?php

declare(strict_types = 1);

namespace stoneperms\web;

use Closure;
use imperazim\components\filesystem\File;
use imperazim\components\scheduler\TaskSchedulerAPI;
use InvalidArgumentException;
use pocketmine\plugin\Plugin;
use Ramsey\Uuid\Uuid;
use stoneperms\application\EditorActorMismatchException;
use stoneperms\application\EditorProtocolException;
use stoneperms\application\EditorSessionBusyException;
use stoneperms\application\EditorSessionConsumedException;
use stoneperms\application\EditorSessionExpiredException;
use stoneperms\application\EditorSessionNotFoundException;
use stoneperms\application\UnknownSubjectException;
use stoneperms\platform\DisplaySettings;
use stoneperms\platform\StonePermsEvents;
use stoneperms\platform\StonePermsService;
use Throwable;

/**
* The plugin half of the dashboard bridge.
*
* The connection is outbound, so the Minecraft server never needs an open port
* or a public address. PocketMine cannot hold a WebSocket without a dedicated
* thread, so this uses the API's HTTP long-poll transport instead: the plugin
* asks for work, runs it on the main thread, and posts the answer back. The
* wire format is identical either way, which is why this plugin pairs with the
* same public API and dashboard as the Endstone build.
*/
final class WebConnector {

  public const PROTOCOL_VERSION = 1;
  public const MAX_MESSAGE_BYTES = 1048576;

  private const POLL_TASK = 'stoneperms.web.poll';
  private const MIN_POLL_TICKS = 5;

  private bool $connected = false;
  private bool $ready = false;
  private bool $inFlight = false;
  private bool $shuttingDown = false;
  private string $lastError = '';
  private float $backoffSeconds = 1.0;
  private int $nextPollAt = 0;

  private readonly string $instanceId;
  private Closure $persistCredential;

  /**
  * @param callable(string, string, string, string): void $persistCredential
  *        receives the api url, server id, server token and server name
  * @param callable(DisplaySettings): void $persistDisplay
  */
  public function __construct(
    private readonly Plugin $plugin,
    private readonly StonePermsService $service,
    private bool $enabled,
    private string $apiUrl,
    private string $serverId,
    private string $serverToken,
    private string $serverName,
    callable $persistCredential,
    private readonly Closure $persistDisplay
  ) {
    $this->apiUrl = rtrim($apiUrl, '/');
    $this->persistCredential = Closure::fromCallable($persistCredential);
    $this->instanceId = $this->loadInstanceId();
  }

  public function status(): WebConnectionStatus {
    return new WebConnectionStatus(
      $this->enabled,
      $this->apiUrl !== '' && $this->serverId !== '' && $this->serverToken !== '',
      $this->connected,
      $this->ready,
      $this->apiUrl,
      $this->serverId,
      $this->lastError
    );
  }

  public function instanceId(): string {
    return $this->instanceId;
  }

  public function start(): void {
    if ($this->shuttingDown || !$this->status()->configured || !$this->enabled) {
      return;
    }
    if (TaskSchedulerAPI::isActive(self::POLL_TASK)) {
      return;
    }
    $this->nextPollAt = 0;
    TaskSchedulerAPI::repeat(self::MIN_POLL_TICKS, fn(): null => $this->tick(), self::POLL_TASK);
  }

  public function close(): void {
    TaskSchedulerAPI::cancel(self::POLL_TASK);
    $this->connected = false;
    $this->ready = false;
  }

  public function shutdown(): void {
    $this->shuttingDown = true;
    $this->close();
  }

  // ------------------------------------------------------------------ pairing

  /**
  * @param callable(?string, ?Throwable): void $callback receives the server id
  */
  public function pair(string $apiUrl, string $code, ?string $serverName, callable $callback): void {
    try {
      $normalized = self::validateApiUrl($apiUrl);
      $name = trim($serverName ?? $this->serverName);
      if ($name === '' || strlen($name) > 80) {
        throw new InvalidArgumentException('Server name must contain 1-80 characters');
      }
    } catch (Throwable $throwable) {
      $callback(null, $throwable);
      return;
    }

    $this->request('POST', $normalized . '/v1/plugin/pair', [
      'code' => trim($code),
      'instanceId' => $this->instanceId,
      'name' => $name,
      'pluginVersion' => $this->service->productVersion(),
      'protocolVersion' => self::PROTOCOL_VERSION
    ], '', function (HttpResult $result) use ($normalized, $name, $callback): void {
      try {
        $body = $result->json();
        $serverId = self::requiredText($body['serverId'] ?? null, 'serverId', 64);
        $serverToken = self::requiredText($body['serverToken'] ?? null, 'serverToken', 128);
        if (!str_starts_with($serverToken, 'sp_srv_')) {
          throw new WebRequestException('API returned an invalid server credential');
        }
        $this->saveCredential($normalized, $serverId, $serverToken, $name);
        $callback($serverId, null);
      } catch (Throwable $throwable) {
        $callback(null, $throwable);
      }
    });
  }

  /**
  * @param callable(?bool, ?Throwable): void $callback receives whether the API revoked the credential
  */
  public function unpair(callable $callback): void {
    $status = $this->status();
    $finish = function (bool $revoked) use ($callback): void {
      $this->close();
      $this->enabled = false;
      $this->serverId = '';
      $this->serverToken = '';
      ($this->persistCredential)($this->apiUrl, '', '', $this->serverName);
      $callback($revoked, null);
    };

    if (!$status->configured) {
      $finish(false);
      return;
    }
    $this->request(
      'DELETE',
      $this->apiUrl . '/v1/plugin/credential',
      null,
      $this->serverToken,
      static function (HttpResult $result) use ($finish): void {
        $finish($result->ok);
      }
    );
  }

  /**
  * Creates a dashboard sign-in code. On a server that has never been paired
  * this also claims the server, which is how a fresh install bootstraps its
  * first dashboard account.
  *
  * @param callable(?WebLoginCode, ?Throwable): void $callback
  */
  public function createLoginCode(callable $callback): void {
    $status = $this->status();
    $claimedServer = !$status->configured;

    if ($claimedServer) {
      try {
        $apiUrl = self::validateApiUrl($status->apiUrl);
      } catch (Throwable $throwable) {
        $callback(null, $throwable);
        return;
      }
      $this->request('POST', $apiUrl . '/v1/plugin/bootstrap-login-codes', [
        'instanceId' => $this->instanceId,
        'name' => $this->serverName,
        'pluginVersion' => $this->service->productVersion(),
        'protocolVersion' => self::PROTOCOL_VERSION
      ], '', function (HttpResult $result) use ($apiUrl, $callback): void {
        try {
          $body = $result->json();
          $serverId = self::requiredText($body['serverId'] ?? null, 'serverId', 64);
          $serverToken = self::requiredText($body['serverToken'] ?? null, 'serverToken', 128);
          if (!str_starts_with($serverToken, 'sp_srv_')) {
            throw new WebRequestException('API returned an invalid server credential');
          }
          $this->saveCredential($apiUrl, $serverId, $serverToken, $this->serverName);
          $callback(self::readLoginCode($body, true), null);
        } catch (Throwable $throwable) {
          $callback(null, $throwable);
        }
      });
      return;
    }

    $this->request(
      'POST',
      $this->apiUrl . '/v1/plugin/login-codes',
      [],
      $this->serverToken,
      static function (HttpResult $result) use ($callback): void {
        try {
          $body = $result->json();
          $callback(self::readLoginCode($body, false), null);
        } catch (Throwable $throwable) {
          $callback(null, $throwable);
        }
      }
    );
  }

  // ------------------------------------------------------------------ polling

  private function tick(): null {
    if ($this->shuttingDown || $this->inFlight || !$this->status()->configured || !$this->enabled) {
      return null;
    }
    $now = (int) (microtime(true) * 1000);
    if ($now < $this->nextPollAt) {
      return null;
    }

    $this->inFlight = true;
    $this->request(
      'POST',
      $this->apiUrl . '/v1/plugin/poll',
      [],
      $this->serverToken,
      function (HttpResult $result): void {
        $this->inFlight = false;
        try {
          $body = $result->json();
        } catch (Throwable $throwable) {
          $this->reportFailure($throwable->getMessage());
          return;
        }

        $wasConnected = $this->connected;
        $this->connected = true;
        $this->ready = true;
        $this->lastError = '';
        $this->backoffSeconds = 1.0;
        if (!$wasConnected) {
          StonePermsEvents::emit(StonePermsEvents::WEB_STATUS, ['connected' => true, 'error' => '']);
        }

        $delayMs = self::integer($body['pollAfterMs'] ?? null, 'pollAfterMs', 250, 5000);
        $this->nextPollAt = (int) (microtime(true) * 1000) + $delayMs;

        $request = $body['request'] ?? null;
        if (is_array($request)) {
          $this->handleRequest($request);
        }
      }
    );
    return null;
  }

  /** @param array<string, mixed> $message */
  private function handleRequest(array $message): void {
    try {
      self::exactKeys($message, ['type', 'requestId', 'action', 'actor', 'payload']);
      $requestId = self::requiredText($message['requestId'] ?? null, 'requestId', 128);
      $actor = self::requiredText($message['actor'] ?? null, 'actor', 128);
      $action = self::requiredText($message['action'] ?? null, 'action', 64);
      $payload = $message['payload'] ?? null;
      if (!is_array($payload)) {
        throw new InvalidArgumentException('Web request payload must be an object');
      }
    } catch (Throwable $throwable) {
      $this->plugin->getLogger()->warning(
        'StonePerms received an invalid dashboard request: ' . $throwable->getMessage()
      );
      return;
    }

    $response = ['requestId' => $requestId];
    try {
      $response['ok'] = true;
      $response['payload'] = $this->dispatch($action, $payload, $actor);
    } catch (Throwable $throwable) {
      [$code, $description] = self::publicError($throwable);
      if ($code === 'INTERNAL_ERROR') {
        $this->plugin->getLogger()->error('StonePerms web request failed: ' . $throwable->getMessage());
      }
      $response = ['requestId' => $requestId, 'ok' => false, 'error' => [
        'code' => $code,
        'message' => $description
      ]];
    }

    $encoded = self::encode($response);
    if (strlen($encoded) > self::MAX_MESSAGE_BYTES) {
      $response = ['requestId' => $requestId, 'ok' => false, 'error' => [
        'code' => 'INTERNAL_ERROR',
        'message' => 'The plugin could not complete the request'
      ]];
      $this->plugin->getLogger()->error('StonePerms web response exceeds 1 MiB');
    }

    $this->sendResponse($response, 0);
  }

  /** @param array<string, mixed> $response */
  private function sendResponse(array $response, int $attempt): void {
    $this->request(
      'POST',
      $this->apiUrl . '/v1/plugin/responses',
      $response,
      $this->serverToken,
      function (HttpResult $result) use ($response, $attempt): void {
        if ($result->ok) {
          return;
        }
        if ($attempt >= 2) {
          $this->plugin->getLogger()->warning(
            'StonePerms could not return a dashboard response after 3 attempts'
          );
          return;
        }
        $delayTicks = (int) (20 * 0.5 * (2 ** $attempt));
        TaskSchedulerAPI::once(max(1, $delayTicks), function () use ($response, $attempt): void {
          $this->sendResponse($response, $attempt + 1);
        });
      }
    );
  }

  /**
  * The eighteen actions the API broker is allowed to ask for. Anything else is
  * refused rather than guessed at.
  *
  * @param array<string, mixed> $payload
  */
  private function dispatch(string $action, array $payload, string $actor): mixed {
    return match ($action) {
      'editor.createSession' => $this->editorCreateSession($payload, $actor),
      'editor.applyChanges' => $this->service->applyEditorChanges($payload, $actor),
      'directory.snapshot' => $this->withNoPayload($payload, fn(): array => $this->service->getWebDirectory()),
      'display.getSettings' => $this->withNoPayload($payload, fn(): array => $this->service->getWebDisplaySettings()),
      'display.updateSettings' => $this->displayUpdate($payload),
      'settings.get' => $this->withNoPayload($payload, fn(): array => $this->service->getWebPluginSettings()),
      'settings.update' => $this->settingsUpdate($payload, $actor),
      'player.inspect' => $this->service->getWebPlayer($this->identifierOf($payload)),
      'player.avatar' => $this->service->getWebPlayerAvatar($this->identifierOf($payload)),
      'group.create' => $this->groupCreate($payload, $actor),
      'group.setWeight' => $this->groupSetWeight($payload, $actor),
      'group.delete' => $this->groupDelete($payload, $actor),
      'track.create' => $this->trackCreate($payload, $actor),
      'track.rename' => $this->trackRename($payload, $actor),
      'track.clone' => $this->trackClone($payload, $actor),
      'track.delete' => $this->trackDelete($payload, $actor),
      'track.moveUser' => $this->trackMoveUser($payload, $actor),
      'audit.list' => $this->auditList($payload),
      default => throw new InvalidArgumentException("Unsupported web action '$action'")
    };
  }

  /** @param array<string, mixed> $payload */
  private function editorCreateSession(array $payload, string $actor): array {
    self::exactKeys($payload, ['users', 'includeGroups', 'includeTracks']);
    $users = $payload['users'];
    if (!is_array($users) || !array_is_list($users)) {
      throw new InvalidArgumentException('Editor users must be an array of strings');
    }
    foreach ($users as $user) {
      if (!is_string($user)) {
        throw new InvalidArgumentException('Editor users must be an array of strings');
      }
    }
    return $this->service->createEditorSession(
      $actor,
      $users,
      self::boolean($payload['includeGroups'], 'includeGroups'),
      self::boolean($payload['includeTracks'], 'includeTracks')
    );
  }

  /** @param array<string, mixed> $payload */
  private function displayUpdate(array $payload): array {
    self::exactKeys($payload, ['chatEnabled', 'chatFormat', 'nametagEnabled', 'nametagFormat']);
    return $this->service->updateWebDisplaySettings(
      self::boolean($payload['chatEnabled'], 'chatEnabled'),
      self::displayText($payload['chatFormat'], 'chatFormat'),
      self::boolean($payload['nametagEnabled'], 'nametagEnabled'),
      self::displayText($payload['nametagFormat'], 'nametagFormat'),
      $this->persistDisplay
    );
  }

  /** @param array<string, mixed> $payload */
  private function settingsUpdate(array $payload, string $actor): array {
    self::exactKeys($payload, [
      'defaultGroup',
      'serverContext',
      'includeDeviceOsContext',
      'includeLocaleContext',
      'expiryCheckSeconds',
      'catalogRefreshSeconds',
      'debug'
    ]);
    return $this->service->updateWebPluginSettings(
      self::requiredText($payload['defaultGroup'], 'defaultGroup', 64),
      self::requiredText($payload['serverContext'], 'serverContext', 64),
      self::boolean($payload['includeDeviceOsContext'], 'includeDeviceOsContext'),
      self::boolean($payload['includeLocaleContext'], 'includeLocaleContext'),
      self::integer($payload['expiryCheckSeconds'], 'expiryCheckSeconds', 1, 60),
      self::integer($payload['catalogRefreshSeconds'], 'catalogRefreshSeconds', 1, 300),
      self::boolean($payload['debug'], 'debug'),
      $actor
    );
  }

  /** @param array<string, mixed> $payload */
  private function groupCreate(array $payload, string $actor): array {
    self::exactKeys($payload, ['name', 'displayName', 'weight']);
    return $this->service->createWebGroup(
      self::requiredText($payload['name'], 'name', 64),
      self::optionalText($payload['displayName'], 'displayName', 128),
      self::integer($payload['weight'], 'weight', -2147483648, 2147483647),
      $actor
    );
  }

  /** @param array<string, mixed> $payload */
  private function groupSetWeight(array $payload, string $actor): array {
    self::exactKeys($payload, ['name', 'weight']);
    return $this->service->setWebGroupWeight(
      self::requiredText($payload['name'], 'name', 64),
      self::integer($payload['weight'], 'weight', -2147483648, 2147483647),
      $actor
    );
  }

  /** @param array<string, mixed> $payload */
  private function groupDelete(array $payload, string $actor): array {
    self::exactKeys($payload, ['name']);
    return $this->service->deleteWebGroup(self::requiredText($payload['name'], 'name', 64), $actor);
  }

  /** @param array<string, mixed> $payload */
  private function trackCreate(array $payload, string $actor): array {
    self::exactKeys($payload, ['name']);
    return $this->service->createWebTrack(self::requiredText($payload['name'], 'name', 64), $actor);
  }

  /** @param array<string, mixed> $payload */
  private function trackRename(array $payload, string $actor): array {
    self::exactKeys($payload, ['name', 'newName']);
    return $this->service->renameWebTrack(
      self::requiredText($payload['name'], 'name', 64),
      self::requiredText($payload['newName'], 'newName', 64),
      $actor
    );
  }

  /** @param array<string, mixed> $payload */
  private function trackClone(array $payload, string $actor): array {
    self::exactKeys($payload, ['name', 'cloneName']);
    return $this->service->cloneWebTrack(
      self::requiredText($payload['name'], 'name', 64),
      self::requiredText($payload['cloneName'], 'cloneName', 64),
      $actor
    );
  }

  /** @param array<string, mixed> $payload */
  private function trackDelete(array $payload, string $actor): array {
    self::exactKeys($payload, ['name']);
    return $this->service->deleteWebTrack(self::requiredText($payload['name'], 'name', 64), $actor);
  }

  /** @param array<string, mixed> $payload */
  private function trackMoveUser(array $payload, string $actor): array {
    self::exactKeys($payload, ['identifier', 'track', 'direction']);
    return $this->service->moveWebPlayer(
      self::requiredText($payload['identifier'], 'identifier', 128),
      self::requiredText($payload['track'], 'track', 64),
      self::requiredText($payload['direction'], 'direction', 16),
      $actor
    );
  }

  /** @param array<string, mixed> $payload */
  private function auditList(array $payload): array {
    self::exactKeys($payload, ['limit']);
    return $this->service->getWebAudit(self::integer($payload['limit'], 'limit', 1, 200));
  }

  /** @param array<string, mixed> $payload */
  private function identifierOf(array $payload): string {
    self::exactKeys($payload, ['identifier']);
    return self::requiredText($payload['identifier'], 'identifier', 128);
  }

  /** @param array<string, mixed> $payload */
  private function withNoPayload(array $payload, callable $action): mixed {
    self::exactKeys($payload, []);
    return $action();
  }

  // ------------------------------------------------------------------ helpers

  /** @param array<string, mixed>|null $body */
  private function request(string $method, string $url, ?array $body, string $bearer, callable $callback): void {
    $encoded = $body === null ? '' : self::encode($body);
    $task = new HttpRequestTask(
      $method,
      $url,
      $encoded,
      $bearer,
      'StonePerms-PocketMine/' . $this->service->productVersion(),
      $callback
    );
    $this->plugin->getServer()->getAsyncPool()->submitTask($task);
  }

  private function reportFailure(string $message): void {
    $wasConnected = $this->connected;
    $this->connected = false;
    $this->ready = false;
    $this->lastError = substr($message, 0, 300);
    $this->plugin->getLogger()->warning('StonePerms API connection failed: ' . $this->lastError);

    $this->backoffSeconds = min(30.0, $this->backoffSeconds * 2);
    $this->nextPollAt = (int) (microtime(true) * 1000 + $this->backoffSeconds * 1000);
    if ($wasConnected) {
      StonePermsEvents::emit(StonePermsEvents::WEB_STATUS, [
        'connected' => false,
        'error' => $this->lastError
      ]);
    }
  }

  private function saveCredential(string $apiUrl, string $serverId, string $serverToken, string $serverName): void {
    if ($this->shuttingDown) {
      throw new WebRequestException('StonePerms is shutting down');
    }
    $this->close();
    ($this->persistCredential)($apiUrl, $serverId, $serverToken, $serverName);
    $this->enabled = true;
    $this->apiUrl = $apiUrl;
    $this->serverId = $serverId;
    $this->serverToken = $serverToken;
    $this->serverName = $serverName;
    $this->lastError = '';
    $this->start();
  }

  /**
  * A stable identifier for this installation, so re-pairing the same server
  * updates its dashboard entry instead of creating a second one.
  */
  private function loadInstanceId(): string {
    $file = new File($this->plugin->getDataFolder(), 'instance', File::TYPE_JSON, true);
    $stored = $file->get('id');
    if (is_string($stored) && Uuid::isValid($stored)) {
      return $stored;
    }
    $generated = Uuid::uuid4()->toString();
    $file->set(['id' => $generated]);
    return $generated;
  }

  /** @param array<string, mixed> $body */
  private static function readLoginCode(array $body, bool $claimedServer): WebLoginCode {
    $username = self::optionalText($body['username'] ?? null, 'username', 32);
    return new WebLoginCode(
      self::requiredText($body['code'] ?? null, 'code', 64),
      self::integer($body['expiresAt'] ?? null, 'expiresAt', 1, 4102444800),
      $username,
      self::requiredText($body['dashboardUrl'] ?? null, 'dashboardUrl', 512),
      $claimedServer || $username === null
    );
  }

  /**
  * Mirrors the API's own rule: absolute HTTP(S), no credentials, no path,
  * query or fragment, and plain HTTP only when the API is on this machine.
  */
  public static function validateApiUrl(string $value): string {
    $raw = rtrim(trim($value), '/');
    $parts = parse_url($raw);
    if (
      $parts === false
      || !isset($parts['scheme'], $parts['host'])
      || !in_array($parts['scheme'], ['http', 'https'], true)
      || isset($parts['user'], $parts['pass'])
    ) {
      throw new InvalidArgumentException('API URL must be an absolute HTTP(S) URL without credentials');
    }
    if (isset($parts['query']) || isset($parts['fragment']) || ($parts['path'] ?? '') !== '') {
      throw new InvalidArgumentException('API URL must not contain a path, query, or fragment');
    }
    if (
      $parts['scheme'] === 'http'
      && !in_array($parts['host'], ['localhost', '127.0.0.1', '::1', '[::1]'], true)
    ) {
      throw new InvalidArgumentException('Remote StonePerms APIs require HTTPS');
    }
    return $parts['scheme'] . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : '');
  }

  /**
  * Encodes a request body.
  *
  * An empty PHP array encodes as the JSON array `[]`, but every body this
  * sends is a JSON object, and the API validates that: `POST /v1/plugin/poll`
  * takes `{}` and rejects `[]` outright. Empty bodies therefore become an
  * explicit object.
  *
  * @param array<string, mixed> $value
  */
  private static function encode(array $value): string {
    return json_encode(
      $value === [] ? new \stdClass() : $value,
      JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
    );
  }

  /**
  * @param array<string, mixed> $value
  * @param list<string> $expected
  */
  private static function exactKeys(array $value, array $expected): void {
    $actual = array_keys($value);
    sort($actual);
    $wanted = $expected;
    sort($wanted);
    if ($actual !== $wanted) {
      throw new InvalidArgumentException('Web message has missing or unknown fields');
    }
  }

  private static function requiredText(mixed $value, string $label, int $maximum): string {
    if (!is_string($value) || trim($value) === '' || strlen($value) > $maximum) {
      throw new InvalidArgumentException("$label must contain 1-$maximum characters");
    }
    return trim($value);
  }

  private static function optionalText(mixed $value, string $label, int $maximum): ?string {
    return $value === null ? null : self::requiredText($value, $label, $maximum);
  }

  private static function displayText(mixed $value, string $label): string {
    if (!is_string($value) || $value === '' || strlen($value) > 256) {
      throw new InvalidArgumentException("$label must contain 1-256 characters");
    }
    return $value;
  }

  private static function boolean(mixed $value, string $label): bool {
    if (!is_bool($value)) {
      throw new InvalidArgumentException("$label must be true or false");
    }
    return $value;
  }

  private static function integer(mixed $value, string $label, int $minimum, int $maximum): int {
    if (is_bool($value) || !is_int($value) || $value < $minimum || $value > $maximum) {
      throw new InvalidArgumentException("$label must be an integer between $minimum and $maximum");
    }
    return $value;
  }

  /**
  * Maps an internal failure onto the small set of codes the dashboard knows,
  * without leaking internal detail for anything unexpected.
  *
  * @return array{0: string, 1: string}
  */
  private static function publicError(Throwable $error): array {
    $code = match (true) {
      $error instanceof EditorSessionNotFoundException => 'EDITOR_SESSION_NOT_FOUND',
      $error instanceof EditorSessionExpiredException => 'EDITOR_SESSION_EXPIRED',
      $error instanceof EditorSessionConsumedException => 'EDITOR_SESSION_CONSUMED',
      $error instanceof EditorSessionBusyException => 'EDITOR_SESSION_BUSY',
      $error instanceof EditorActorMismatchException => 'EDITOR_ACTOR_MISMATCH',
      $error instanceof EditorProtocolException => 'EDITOR_PROTOCOL_ERROR',
      $error instanceof UnknownSubjectException => 'NOT_FOUND',
      $error instanceof InvalidArgumentException => 'INVALID_REQUEST',
      default => 'INTERNAL_ERROR'
    };
    if ($code === 'INTERNAL_ERROR') {
      return [$code, 'The plugin could not complete the request'];
    }
    $message = trim($error->getMessage());
    return [$code, substr($message === '' ? $code : $message, 0, 500)];
  }
}
