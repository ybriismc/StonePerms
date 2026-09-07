<?php

declare(strict_types = 1);

namespace stoneperms\web;

use pocketmine\scheduler\AsyncTask;
use pocketmine\utils\Internet;
use pocketmine\utils\InternetException;
use Throwable;

/**
* One HTTP call to the StonePerms API, run on a worker thread.
*
* PocketMine ticks the whole server on one thread, so a blocking request here
* would freeze the world. Only scalars cross the thread boundary; the callback
* stays on the main thread through `storeLocal`.
*/
final class HttpRequestTask extends AsyncTask {

  private const CALLBACK = 'stoneperms.callback';
  private const TIMEOUT_SECONDS = 30.0;

  private string $method;
  private string $url;
  private string $body;
  private string $bearer;
  private string $userAgent;

  /**
  * @param (callable(HttpResult): void) $onComplete runs on the main thread
  */
  public function __construct(
    string $method,
    string $url,
    string $body,
    string $bearer,
    string $userAgent,
    callable $onComplete
  ) {
    $this->method = $method;
    $this->url = $url;
    $this->body = $body;
    $this->bearer = $bearer;
    $this->userAgent = $userAgent;
    $this->storeLocal(self::CALLBACK, $onComplete);
  }

  public function onRun(): void {
    $headers = [
      'Accept: application/json',
      'User-Agent: ' . $this->userAgent
    ];
    if ($this->body !== '') {
      $headers[] = 'Content-Type: application/json';
    }
    if ($this->bearer !== '') {
      $headers[] = 'Authorization: Bearer ' . $this->bearer;
    }

    try {
      $options = [CURLOPT_CUSTOMREQUEST => $this->method];
      if ($this->body !== '') {
        $options[CURLOPT_POSTFIELDS] = $this->body;
      }
      $result = Internet::simpleCurl($this->url, self::TIMEOUT_SECONDS, $headers, $options);
      $this->setResult([$result->getCode(), $result->getBody()]);
    } catch (InternetException $exception) {
      $this->setResult([0, '', $exception->getMessage()]);
    } catch (Throwable $throwable) {
      $this->setResult([0, '', $throwable->getMessage()]);
    }
  }

  public function onCompletion(): void {
    $callback = $this->fetchLocal(self::CALLBACK);
    $raw = $this->getResult();
    if (!is_array($raw)) {
      $callback(HttpResult::failure('The request did not produce a result'));
      return;
    }
    if (($raw[2] ?? '') !== '') {
      $callback(HttpResult::failure('Could not reach the StonePerms API: ' . $raw[2]));
      return;
    }
    $callback(HttpResult::response((int) $raw[0], (string) $raw[1]));
  }
}
