<?php

declare(strict_types = 1);

namespace stoneperms\command;

use pocketmine\command\CommandSender;
use stoneperms\platform\Settings;
use stoneperms\StonePermsPlugin;
use Throwable;

/**
* `/stoneperms web ...` — pairing this server with the dashboard.
*
* Every network call here is asynchronous, so the console reply arrives a
* moment after the command rather than blocking the server on the API.
*/
final class WebCommandHandler {

  public function __construct(private readonly StonePermsPlugin $plugin) {}

  /** @param list<string> $tokens tokens after `web` */
  public function handle(CommandSender $sender, array $tokens): void {
    $action = strtolower($tokens[0] ?? 'status');

    match ($action) {
      'status' => $this->status($sender),
      'dashboard' => $this->dashboard($sender),
      'pair' => $this->pair($sender, CommandSupport::slice($tokens, 1)),
      'login' => $this->login($sender),
      'unpair' => $this->unpair($sender),
      default => CommandSupport::error($sender, 'Usage: /stoneperms web <status|dashboard|pair|login|unpair>')
    };
  }

  private function status(CommandSender $sender): void {
    $status = $this->plugin->web()->status();
    CommandSupport::send($sender, '§7Dashboard bridge:');
    $sender->sendMessage('  §7enabled: §f' . self::flag($status->enabled));
    $sender->sendMessage('  §7paired: §f' . self::flag($status->configured));
    $sender->sendMessage('  §7connected: §f' . self::flag($status->connected));
    $sender->sendMessage('  §7api: §f' . ($status->apiUrl !== '' ? $status->apiUrl : '§8unset'));
    if ($status->serverId !== '') {
      $sender->sendMessage('  §7server id: §f' . $status->serverId);
    }
    if ($status->lastError !== '') {
      $sender->sendMessage('  §clast error: §f' . $status->lastError);
    }
  }

  private function dashboard(CommandSender $sender): void {
    $status = $this->plugin->web()->status();
    $url = $status->apiUrl !== '' ? $status->apiUrl : Settings::PUBLIC_DASHBOARD_URL;
    CommandSupport::send($sender, '§7Dashboard: §f' . $url);
  }

  /**
  * Accepts both forms the API supports: a bare pairing code, which targets the
  * configured API, or an explicit API address followed by the code.
  *
  * @param list<string> $tokens
  */
  private function pair(CommandSender $sender, array $tokens): void {
    if ($tokens === []) {
      CommandSupport::error($sender, 'Usage: /stoneperms web pair [api-url] <pairing-code> [server-name]');
      return;
    }

    $first = $tokens[0];
    $looksLikeUrl = str_starts_with(strtolower($first), 'http://')
      || str_starts_with(strtolower($first), 'https://');

    if ($looksLikeUrl) {
      $apiUrl = $first;
      $code = $tokens[1] ?? '';
      $name = implode(' ', CommandSupport::slice($tokens, 2));
    } else {
      $apiUrl = $this->plugin->web()->status()->apiUrl ?: Settings::PUBLIC_API_URL;
      $code = $first;
      $name = implode(' ', CommandSupport::slice($tokens, 1));
    }

    if (trim($code) === '') {
      CommandSupport::error($sender, 'A pairing code is required.');
      return;
    }

    CommandSupport::send($sender, '§7Pairing with §f' . $apiUrl . '§7...');
    $this->plugin->web()->pair(
      $apiUrl,
      $code,
      trim($name) === '' ? null : trim($name),
      static function (?string $serverId, ?Throwable $error) use ($sender): void {
        if ($error !== null) {
          CommandSupport::error($sender, 'Pairing failed: ' . $error->getMessage());
          return;
        }
        CommandSupport::send($sender, '§7Paired. This server is now §f' . $serverId . '§7.');
      }
    );
  }

  private function login(CommandSender $sender): void {
    CommandSupport::send($sender, '§7Requesting a dashboard sign-in code...');
    $this->plugin->web()->createLoginCode(
      static function (?object $code, ?Throwable $error) use ($sender): void {
        if ($error !== null || $code === null) {
          CommandSupport::error(
            $sender,
            'Could not create a sign-in code: ' . ($error?->getMessage() ?? 'no code returned')
          );
          return;
        }
        CommandSupport::send($sender, '§7Sign-in code: §f' . $code->code);
        $sender->sendMessage('  §7open: §f' . $code->dashboardUrl);
        $sender->sendMessage('  §7expires: §f' . date('Y-m-d H:i:s', $code->expiresAt));
        if ($code->claimedServer) {
          $sender->sendMessage('  §7This code also claims ownership of this server.');
        } elseif ($code->username !== null) {
          $sender->sendMessage('  §7signs in as: §f' . $code->username);
        }
      }
    );
  }

  private function unpair(CommandSender $sender): void {
    $this->plugin->web()->unpair(
      static function (?bool $revoked, ?Throwable $error) use ($sender): void {
        if ($error !== null) {
          CommandSupport::error($sender, 'Unpair failed: ' . $error->getMessage());
          return;
        }
        CommandSupport::send(
          $sender,
          $revoked === true
            ? '§7Unpaired. The credential was revoked at the API.'
            : '§7Unpaired locally. The API could not be reached to revoke the credential.'
        );
      }
    );
  }

  private static function flag(bool $value): string {
    return $value ? '§ayes' : '§cno';
  }
}
