<?php

declare(strict_types=1);

namespace Drupal\genie\Web;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\genie\Game\Games;
use Drupal\genie\Game\Ledger;

/**
 * Seats a visitor in the game an invite link opens.
 */
final class JoinForm extends FormBase {

  use AutowireTrait;

  private const FLOOD = 'genie.join';

  /**
   * Unknown codes one address may try per hour.
   */
  private const TRIES = 20;

  public function __construct(
    protected readonly Games $games,
    protected readonly FloodInterface $flood,
    protected readonly WebViewAddress $webView,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'genie_join';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, string $code = ''): array {
    $form['#title'] = $this->t('Join a genie game');
    if (!$this->flood->isAllowed(self::FLOOD, self::TRIES)) {
      $form['closed'] = ['#markup' => $this->t('Too many unknown links. Try again in an hour.')];
      return $form;
    }
    $game = $this->games->byInvite($code);
    if ($game === NULL) {
      $this->flood->register(self::FLOOD);
      $form['closed'] = ['#markup' => $this->t('This invite link opens no game.')];
      return $form;
    }
    if (in_array($game->get('field_genie_state')->value, Ledger::OVER, TRUE)) {
      $form['closed'] = ['#markup' => $this->t('This game is over.')];
      return $form;
    }
    $form_state->set('game', (int) $game->id());
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name in the game'),
      '#maxlength' => 30,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Join')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    try {
      $form_state->set('player', $this->games->join($form_state->get('game'), (string) $form_state->getValue('name')));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('name', $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    user_login_finalize($form_state->get('player'));
    $form_state->setResponse(new TrustedRedirectResponse($this->webView->signIn()));
  }

}
