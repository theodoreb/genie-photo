<?php

declare(strict_types=1);

namespace Drupal\genie_ledger\Plugin\mcp_server\Tool;

use Drupal\Core\StringTranslation\TranslatableMarkup;
use Drupal\genie_ledger\Mcp\ElicitationCompleteNotification;
use Drupal\genie_ledger\Mcp\UrlElicitationRequest;
use Drupal\genie_ledger\WishDesk;
use Drupal\mcp_server\Attribute\Tool;
use Mcp\Exception\ClientException;
use Mcp\Exception\ToolCallException;
use Mcp\Schema\Enum\ElicitationMode;
use Mcp\Schema\Enum\ProtocolVersion;
use Mcp\Schema\JsonRpc\Response;
use Mcp\Schema\Result\ElicitResult;
use Mcp\Server\ClientGateway;
use Mcp\Server\Transport\StreamableHttpTransport;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\RequestStack;

/**
 * Sends the human behind an assistant to the page that shows the picture.
 *
 * It records nothing: only the person's click on that page approves.
 */
#[Tool(
  id: 'approve_export',
  label: new TranslatableMarkup('Approve the photo'),
  description: new TranslatableMarkup('Asks you, the person, to approve the shared picture on a page that shows it. Your client may open the page itself. When status is "waiting_on_web", give your person review_url, then call wait_for_update and call this tool again. You cannot approve for your person.'),
  inputSchema: ['type' => 'object', 'properties' => new \stdClass()],
  outputSchema: WishDesk::APPROVAL_REQUEST_SCHEMA,
  readOnly: TRUE,
  destructive: FALSE,
  idempotent: TRUE,
  openWorld: FALSE,
)]
final class ApproveExportTool extends LedgerToolBase {

  private const ELICITATION_TIMEOUT = 120;

  private const ASK = 'Open this page to see the picture and approve it. Any later change cancels the approval.';

  /**
   * The requests being served, for the protocol revision of this one.
   */
  private RequestStack $requests;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): static {
    $tool = parent::create($container, $configuration, $plugin_id, $plugin_definition);
    $tool->requests = $container->get('request_stack');
    return $tool;
  }

  /**
   * {@inheritdoc}
   */
  public function execute(array $arguments, ClientGateway $gateway): mixed {
    $uid = (int) $this->currentUser->id();
    try {
      $request = $this->desk->approvalRequest($uid, $this->currentUser->getAccountName());
    }
    catch (\InvalidArgumentException $e) {
      throw new ToolCallException($e->getMessage(), previous: $e);
    }
    if ($request['approved_by_you'] || !$gateway->supportsElicitationUrl()) {
      return $request;
    }
    return $this->hasServerRequests()
      ? $this->askDuringCall($gateway, $uid, $request)
      : $this->askAcrossRounds($gateway, $uid, $request);
  }

  /**
   * Tells whether this call's protocol revision lets the server send requests.
   *
   * Revisions from 2026-07-28 have none. Sending one there ends the call with
   * a server error.
   */
  private function hasServerRequests(): bool {
    $header = $this->requests->getCurrentRequest()->headers->get(StreamableHttpTransport::PROTOCOL_VERSION_HEADER);
    return !ProtocolVersion::tryFrom((string) $header)?->isModern();
  }

  /**
   * Asks on a revision where the question ends the call.
   *
   * The client sends the call again with the answer, and this code runs anew.
   *
   * @param \Mcp\Server\ClientGateway $gateway
   *   The connection to the client.
   * @param int $uid
   *   The player.
   * @param array<string, mixed> $request
   *   What the desk returned for this player.
   *
   * @return array<string, mixed>
   *   The tool result.
   */
  private function askAcrossRounds(ClientGateway $gateway, int $uid, array $request): array {
    try {
      $answer = $gateway->elicitUrl(self::ASK, $request['review_url'], self::ELICITATION_TIMEOUT, 'approve-' . $request['hash']);
    }
    catch (ClientException) {
      return $request;
    }
    return $answer->isAccepted() ? $this->desk->awaitApproval($uid, $request) : $request;
  }

  /**
   * Asks with a server request, and closes the client's prompt afterwards.
   *
   * @param \Mcp\Server\ClientGateway $gateway
   *   The connection to the client.
   * @param int $uid
   *   The player.
   * @param array<string, mixed> $request
   *   What the desk returned for this player.
   *
   * @return array<string, mixed>
   *   The tool result.
   */
  private function askDuringCall(ClientGateway $gateway, int $uid, array $request): array {
    $id = bin2hex(random_bytes(16));
    if (!$this->pageOpened($gateway, $request['review_url'], $id)) {
      return $request;
    }
    try {
      return $this->desk->awaitApproval($uid, $request);
    }
    finally {
      $this->closePrompt($gateway, $id);
    }
  }

  /**
   * Asks the client to open the page.
   *
   * Codex answers "decline" without showing anything, so a refusal only sends
   * the assistant back to giving its person the link.
   */
  private function pageOpened(ClientGateway $gateway, string $url, string $id): bool {
    $response = $gateway->request(new UrlElicitationRequest(self::ASK, $url, $id), self::ELICITATION_TIMEOUT);
    return $response instanceof Response && ElicitResult::fromArray($response->result, ElicitationMode::Url)->isAccepted();
  }

  /**
   * Tells the client to stop waiting for the page.
   */
  private function closePrompt(ClientGateway $gateway, string $id): void {
    try {
      $gateway->notify(new ElicitationCompleteNotification($id));
    }
    catch (ClientException) {
      // A client that has gone away has no prompt left to close.
    }
  }

}
