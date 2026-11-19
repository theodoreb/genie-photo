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
    if ($request['approved_by_you'] || !$this->canOpenPage($gateway)) {
      return $request;
    }

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
   * Tells whether this client can be asked to open a page during the call.
   *
   * Revisions from 2026-07-28 have no server requests. Sending one there ends
   * the call with a server error.
   */
  private function canOpenPage(ClientGateway $gateway): bool {
    $header = $this->requests->getCurrentRequest()->headers->get(StreamableHttpTransport::PROTOCOL_VERSION_HEADER);
    return $gateway->supportsElicitationUrl() && !ProtocolVersion::tryFrom((string) $header)?->isModern();
  }

  /**
   * Asks the client to open the page.
   *
   * Codex answers "decline" without showing anything, so a refusal only sends
   * the assistant back to giving its person the link.
   */
  private function pageOpened(ClientGateway $gateway, string $url, string $id): bool {
    $response = $gateway->request(
      new UrlElicitationRequest('Open this page to see the picture and approve it. Any later change cancels the approval.', $url, $id),
      self::ELICITATION_TIMEOUT,
    );
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
