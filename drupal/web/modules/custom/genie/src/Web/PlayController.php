<?php

declare(strict_types=1);

namespace Drupal\genie\Web;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\genie\Game\Games;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * Seats a visitor from the name form on the web view's game page.
 */
final class PlayController extends ControllerBase {

  public function __construct(
    private readonly Games $games,
    private readonly FloodInterface $flood,
    private readonly WebViewAddress $webView,
  ) {}

  /**
   * Seats the visitor and sends them to their game, or back with the reason.
   */
  public function play(Request $request, string $code): TrustedRedirectResponse {
    $origin = $this->webView->origin();
    // Another site's form could seat a browser in a game it did not choose.
    if ($request->headers->get('Origin') !== $origin) {
      throw new AccessDeniedHttpException();
    }
    $game = $this->flood->isAllowed(JoinForm::FLOOD, JoinForm::TRIES) ? $this->games->byInvite($code) : NULL;
    if ($game === NULL) {
      $this->flood->register(JoinForm::FLOOD);
      return new TrustedRedirectResponse($origin . '/games', 303);
    }
    try {
      $player = $this->games->join((int) $game->id(), $request->request->getString('name'));
    }
    catch (\InvalidArgumentException $e) {
      $reason = array_search($e->getCode(), Games::REFUSED, TRUE);
      return new TrustedRedirectResponse($origin . '/games/' . $game->uuid() . '?' . http_build_query(['refused' => $reason]), 303);
    }
    user_login_finalize($player);
    return new TrustedRedirectResponse($this->webView->signIn(), 303);
  }

}
