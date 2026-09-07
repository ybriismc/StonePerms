<?php

declare(strict_types = 1);

namespace stoneperms\web;

/**
* One completed HTTP exchange with the API, carried back from the worker
* thread as plain scalars.
*/
final class HttpResult {

  private function __construct(
    public readonly bool $ok,
    public readonly int $status,
    public readonly string $body,
    public readonly string $error
  ) {}

  public static function response(int $status, string $body): self {
    return new self($status >= 200 && $status < 300, $status, $body, '');
  }

  public static function failure(string $error): self {
    return new self(false, 0, '', $error);
  }

  /**
  * Decodes the JSON body, turning an API error envelope into an exception
  * message the same way the Endstone build does.
  *
  * @return array<string, mixed>
  */
  public function json(): array {
    if ($this->error !== '') {
      throw new WebRequestException($this->error);
    }
    if ($this->body === '') {
      if ($this->ok) {
        return [];
      }
      throw new WebRequestException('API returned HTTP ' . $this->status);
    }
    $decoded = json_decode($this->body, true);
    if (!$this->ok) {
      $message = is_array($decoded) && isset($decoded['error']['message'])
        ? (string) $decoded['error']['message']
        : 'API returned HTTP ' . $this->status;
      throw new WebRequestException(substr($message, 0, 500));
    }
    if (!is_array($decoded)) {
      throw new WebRequestException('API returned invalid JSON');
    }
    return $decoded;
  }
}
