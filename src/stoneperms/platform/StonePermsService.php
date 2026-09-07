<?php

declare(strict_types = 1);

namespace stoneperms\platform;

use Closure;
use InvalidArgumentException;
use pocketmine\player\Player;
use stoneperms\application\EditorProtocol;
use stoneperms\application\StonePermsManager;
use stoneperms\domain\ContextSet;
use stoneperms\domain\GroupRecord;
use stoneperms\domain\MetaDecision;
use stoneperms\domain\Node;
use stoneperms\domain\PermissionDecision;
use stoneperms\domain\SubjectRef;
use stoneperms\domain\TrackMoveResult;
use stoneperms\domain\TrackRecord;
use stoneperms\domain\Validation;

/**
* The API other plugins consume, and the surface the dashboard bridge calls.
*
* PocketMine has no service manager, so this is reached through
* `StonePermsPlugin::api()`. Everything here accepts either a live `Player` or
* any identifier the plugin can resolve, so callers never need to know whether
* the target is online.
*/
final class StonePermsService {

  private ?Closure $onUserChange;
  private ?Closure $onEditorChange;

  /**
  * @param (callable(string): void)|null $onUserChange re-applies one user's permissions
  * @param (callable(): void)|null $onEditorChange re-applies everyone after a bulk edit
  */
  public function __construct(
    private readonly StonePermsManager $manager,
    private readonly ContextCalculator $contexts,
    private readonly EditorProtocol $editor,
    private readonly string $productVersion,
    ?callable $onUserChange = null,
    ?callable $onEditorChange = null,
    private readonly ?DisplayService $display = null,
    private readonly ?ConfigurationService $configuration = null
  ) {
    $this->onUserChange = $onUserChange !== null ? Closure::fromCallable($onUserChange) : null;
    $this->onEditorChange = $onEditorChange !== null ? Closure::fromCallable($onEditorChange) : null;
  }

  public function manager(): StonePermsManager {
    return $this->manager;
  }

  public function contexts(): ContextCalculator {
    return $this->contexts;
  }

  public function editorProtocolVersion(): int {
    return EditorProtocol::PROTOCOL_VERSION;
  }

  public function productVersion(): string {
    return $this->productVersion;
  }

  // ---------------------------------------------------------------- plugin API

  public function hasPermission(Player|string $subject, string $permission, ?ContextSet $contexts = null): bool {
    return $this->checkPermission($subject, $permission, $contexts)->value === true;
  }

  public function checkPermission(
    Player|string $subject,
    string $permission,
    ?ContextSet $contexts = null
  ): PermissionDecision {
    [$reference, $active] = $this->resolveSubject($subject, $contexts);
    return $this->manager->checkPermission($reference, $permission, $active);
  }

  public function getPrimaryGroup(Player|string $subject, ?ContextSet $contexts = null): string {
    [$reference, $active] = $this->resolveSubject($subject, $contexts);
    return $this->manager->primaryGroup($reference, $active);
  }

  /** @return list<string> */
  public function getGroups(Player|string $subject, ?ContextSet $contexts = null): array {
    [$reference, $active] = $this->resolveSubject($subject, $contexts);
    return $this->manager->effectiveGroups($reference, $active);
  }

  public function getPrefix(Player|string $subject, ?ContextSet $contexts = null): ?string {
    [$reference, $active] = $this->resolveSubject($subject, $contexts);
    return $this->manager->resolvePrefix($reference, $active)->value;
  }

  public function getSuffix(Player|string $subject, ?ContextSet $contexts = null): ?string {
    [$reference, $active] = $this->resolveSubject($subject, $contexts);
    return $this->manager->resolveSuffix($reference, $active)->value;
  }

  public function getMeta(Player|string $subject, string $key, ?ContextSet $contexts = null): ?string {
    [$reference, $active] = $this->resolveSubject($subject, $contexts);
    return $this->manager->resolveMeta($reference, $key, $active)->value;
  }

  public function resolveMeta(Player|string $subject, string $key, ?ContextSet $contexts = null): MetaDecision {
    [$reference, $active] = $this->resolveSubject($subject, $contexts);
    return $this->manager->resolveMeta($reference, $key, $active);
  }

  /** @return array<string, string> */
  public function getMetaMap(Player|string $subject, ?ContextSet $contexts = null): array {
    [$reference, $active] = $this->resolveSubject($subject, $contexts);
    return $this->manager->metaMap($reference, $active);
  }

  /** @return list<TrackRecord> */
  public function getTracks(): array {
    return $this->manager->listTracks();
  }

  /** @return array<string, list<string>> */
  public function getUserTracks(Player|string $subject, ?ContextSet $contexts = null): array {
    [$reference] = $this->resolveSubject($subject, $contexts);
    return $this->manager->userTracks($reference);
  }

  /** @return list<GroupRecord> */
  public function getGroupRecords(): array {
    return $this->manager->listGroups();
  }

  public function promote(
    Player|string $subject,
    string $track,
    string $actor,
    ?ContextSet $contexts = null,
    bool $addToFirst = true
  ): TrackMoveResult {
    [$reference] = $this->resolveSubject($subject, null);
    $result = $this->manager->promote($reference, $track, $actor, $contexts, $addToFirst);
    if ($result->changed) {
      $this->notifyUserChange($reference->identifier);
    }
    return $result;
  }

  public function demote(
    Player|string $subject,
    string $track,
    string $actor,
    ?ContextSet $contexts = null,
    bool $removeFromFirst = true
  ): TrackMoveResult {
    [$reference] = $this->resolveSubject($subject, null);
    $result = $this->manager->demote($reference, $track, $actor, $contexts, $removeFromFirst);
    if ($result->changed) {
      $this->notifyUserChange($reference->identifier);
    }
    return $result;
  }

  /**
  * @param list<string> $users
  * @return array<string, mixed>
  */
  public function createEditorSession(
    string $actor,
    array $users = [],
    bool $includeGroups = true,
    bool $includeTracks = true
  ): array {
    return $this->editor->createSession($actor, $users, $includeGroups, $includeTracks);
  }

  /**
  * @param string|array<string, mixed> $payload
  * @return array<string, mixed>
  */
  public function applyEditorChanges(string|array $payload, string $actor): array {
    $result = $this->editor->applyChanges($actor, $payload);
    if (($result['changed'] ?? false) === true) {
      $this->notifyEditorChange();
    }
    return $result;
  }

  /**
  * @param callable(Player): (string|list<string>|null) $provider
  */
  public function registerContextProvider(string $owner, string $key, callable $provider): void {
    $this->contexts->registerProvider($owner, $key, $provider);
  }

  public function unregisterContextProviders(string $owner): void {
    $this->contexts->unregisterOwner($owner);
  }

  // -------------------------------------------------------------- web bridge

  /** @return array<string, mixed> */
  public function getWebDirectory(): array {
    return [
      'revision' => $this->manager->repository()->revision(),
      'defaultGroup' => $this->manager->defaultGroup(),
      'groups' => array_map(
        static fn(GroupRecord $group): array => [
          'name' => $group->name,
          'displayName' => $group->displayName,
          'weight' => $group->weight
        ],
        $this->manager->listGroups()
      ),
      'tracks' => array_map(self::serializeTrack(...), $this->manager->listTracks()),
      'users' => array_map(
        static fn($profile): array => $profile->toSummaryWire(),
        $this->manager->listPlayerProfiles()
      )
    ];
  }

  /** @return array<string, mixed> */
  public function getWebPlayer(string $identifier): array {
    $user = $this->manager->findUser($identifier);
    $subject = SubjectRef::user($user->uniqueId);
    $profile = $this->manager->getPlayerProfile($user->uniqueId);

    return [
      'user' => $profile !== null
        ? $profile->toSummaryWire()
        : ['id' => $user->uniqueId, 'name' => $user->lastName, 'xuid' => $user->xuid],
      'profile' => $profile?->toDetailWire(),
      'nodes' => array_map(static fn(Node $node): array => $node->toWire(), $this->manager->nodesFor($subject)),
      'effectiveGroups' => $this->manager->effectiveGroups($subject),
      'primaryGroup' => $this->manager->primaryGroup($subject),
      'prefix' => $this->manager->resolvePrefix($subject)->value,
      'suffix' => $this->manager->resolveSuffix($subject)->value,
      'meta' => (object) $this->manager->metaMap($subject),
      'tracks' => (object) $this->manager->userTracks($subject)
    ];
  }

  /** @return array<string, mixed> */
  public function getWebPlayerAvatar(string $identifier): array {
    $user = $this->manager->findUser($identifier);
    $profile = $this->manager->getPlayerProfile($user->uniqueId);
    $png = $profile !== null ? ProfileCapture::renderFacePng($profile) : null;

    return [
      'name' => $profile?->lastName ?? $user->lastName,
      'xuid' => $profile?->xuid ?? $user->xuid,
      'skinHash' => $profile?->skinHash,
      'skinId' => $profile?->skinId,
      'mimeType' => $png !== null ? 'image/png' : null,
      'data' => $png !== null ? base64_encode($png) : null
    ];
  }

  /** @return array<string, mixed> */
  public function createWebGroup(string $name, ?string $displayName, int $weight, string $actor): array {
    if (!$this->manager->createGroup($name, $displayName, $weight, $actor)) {
      throw new InvalidArgumentException("Group '$name' already exists");
    }
    return self::serializeGroup($this->manager->getGroup($name));
  }

  /** @return array<string, mixed> */
  public function setWebGroupWeight(string $name, int $weight, string $actor): array {
    $this->manager->setGroupWeight($name, $weight, $actor);
    $this->notifyEditorChange();
    return self::serializeGroup($this->manager->getGroup($name));
  }

  /** @return array<string, mixed> */
  public function deleteWebGroup(string $name, string $actor): array {
    $normalized = Validation::groupName($name);
    $deleted = $this->manager->deleteGroup($normalized, $actor);
    if ($deleted) {
      $this->notifyEditorChange();
    }
    return ['name' => $normalized, 'deleted' => $deleted];
  }

  /** @return array<string, mixed> */
  public function createWebTrack(string $name, string $actor): array {
    if (!$this->manager->createTrack($name, $actor)) {
      throw new InvalidArgumentException("Track '$name' already exists");
    }
    return self::serializeTrack($this->manager->getTrack($name));
  }

  /** @return array<string, mixed> */
  public function renameWebTrack(string $name, string $newName, string $actor): array {
    return self::serializeTrack($this->manager->renameTrack($name, $newName, $actor));
  }

  /** @return array<string, mixed> */
  public function cloneWebTrack(string $name, string $cloneName, string $actor): array {
    return self::serializeTrack($this->manager->cloneTrack($name, $cloneName, $actor));
  }

  /** @return array<string, mixed> */
  public function deleteWebTrack(string $name, string $actor): array {
    return ['name' => $name, 'deleted' => $this->manager->deleteTrack($name, $actor)];
  }

  /** @return array<string, mixed> */
  public function moveWebPlayer(string $identifier, string $track, string $direction, string $actor): array {
    $result = match ($direction) {
      'promote' => $this->promote($identifier, $track, $actor),
      'demote' => $this->demote($identifier, $track, $actor),
      default => throw new InvalidArgumentException('Track direction must be promote or demote')
    };
    return $result->toWire();
  }

  /** @return array<string, mixed> */
  public function getWebAudit(int $limit): array {
    return ['entries' => $this->manager->recentAudit($limit)];
  }

  /** @return array<string, mixed> */
  public function getWebDisplaySettings(): array {
    return $this->requireDisplay()->webSettings();
  }

  /**
  * @param callable(DisplaySettings): void $persist
  * @return array<string, mixed>
  */
  public function updateWebDisplaySettings(
    bool $chatEnabled,
    string $chatFormat,
    bool $nametagEnabled,
    string $nametagFormat,
    callable $persist
  ): array {
    return $this->requireDisplay()->updateSettings(
      $chatEnabled,
      $chatFormat,
      $nametagEnabled,
      $nametagFormat,
      $persist
    );
  }

  /** @return array<string, mixed> */
  public function getWebPluginSettings(): array {
    return $this->requireConfiguration()->webSettings();
  }

  /** @return array<string, mixed> */
  public function updateWebPluginSettings(
    string $defaultGroup,
    string $serverContext,
    bool $includeDeviceOsContext,
    bool $includeLocaleContext,
    int $expiryCheckSeconds,
    int $catalogRefreshSeconds,
    bool $debug,
    string $actor
  ): array {
    return $this->requireConfiguration()->updateSettings(
      $defaultGroup,
      $serverContext,
      $includeDeviceOsContext,
      $includeLocaleContext,
      $expiryCheckSeconds,
      $catalogRefreshSeconds,
      $debug,
      $actor
    );
  }

  public function notifyUserChange(string $uniqueId): void {
    if ($this->onUserChange !== null) {
      ($this->onUserChange)($uniqueId);
    }
  }

  public function notifyEditorChange(): void {
    if ($this->onEditorChange !== null) {
      ($this->onEditorChange)();
    }
  }

  /**
  * @return array{0: SubjectRef, 1: ?ContextSet}
  */
  private function resolveSubject(Player|string $subject, ?ContextSet $contexts): array {
    if ($subject instanceof Player) {
      return [
        PlayerIdentity::subject($subject),
        $contexts ?? $this->contexts->calculate($subject)
      ];
    }
    return [$this->manager->userSubject($subject), $contexts];
  }

  private function requireDisplay(): DisplayService {
    if ($this->display === null) {
      throw new InvalidArgumentException('StonePerms display service is not available');
    }
    return $this->display;
  }

  private function requireConfiguration(): ConfigurationService {
    if ($this->configuration === null) {
      throw new InvalidArgumentException('StonePerms configuration service is not available');
    }
    return $this->configuration;
  }

  /** @return array<string, mixed> */
  private static function serializeGroup(GroupRecord $group): array {
    return ['name' => $group->name, 'displayName' => $group->displayName, 'weight' => $group->weight];
  }

  /** @return array<string, mixed> */
  private static function serializeTrack(TrackRecord $track): array {
    return ['name' => $track->name, 'groups' => $track->groups];
  }
}
