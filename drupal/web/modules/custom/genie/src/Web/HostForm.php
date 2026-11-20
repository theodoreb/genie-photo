<?php

declare(strict_types=1);

namespace Drupal\genie\Web;

use Drupal\Core\DependencyInjection\AutowireTrait;
use Drupal\Core\Flood\FloodInterface;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Routing\TrustedRedirectResponse;
use Drupal\genie\Game\Games;
use Drupal\key\KeyRepositoryInterface;

/**
 * Starts a game for a visitor who has the host code.
 */
final class HostForm extends FormBase {

  use AutowireTrait;

  private const FLOOD = 'genie.host';

  /**
   * Wrong codes one address may try per hour.
   */
  private const TRIES = 5;

  public function __construct(
    protected readonly Games $games,
    protected readonly KeyRepositoryInterface $keys,
    protected readonly FloodInterface $flood,
    protected readonly WebViewAddress $webView,
  ) {}

  /**
   * {@inheritdoc}
   */
  public function getFormId(): string {
    return 'genie_host';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state): array {
    $form['#title'] = $this->t('Start a genie game');
    $form['code'] = [
      '#type' => 'password',
      '#title' => $this->t('Host code'),
      '#required' => TRUE,
    ];
    $form['name'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Your name in the game'),
      '#maxlength' => 30,
      '#required' => TRUE,
    ];
    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = ['#type' => 'submit', '#value' => $this->t('Start the game')];
    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function validateForm(array &$form, FormStateInterface $form_state): void {
    if (!$this->flood->isAllowed(self::FLOOD, self::TRIES)) {
      $form_state->setErrorByName('code', $this->t('Too many wrong codes. Try again in an hour.'));
      return;
    }
    // An empty or missing key file refuses every code.
    $code = (string) $this->keys->getKey('genie_host_code')?->getKeyValue();
    if ($code === '' || !hash_equals($code, (string) $form_state->getValue('code'))) {
      $this->flood->register(self::FLOOD);
      $form_state->setErrorByName('code', $this->t('This host code is wrong.'));
      return;
    }
    try {
      $form_state->setValue('name', Games::checkName((string) $form_state->getValue('name')));
    }
    catch (\InvalidArgumentException $e) {
      $form_state->setErrorByName('name', $e->getMessage());
    }
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state): void {
    user_login_finalize($this->games->start($form_state->getValue('name')));
    $form_state->setResponse(new TrustedRedirectResponse($this->webView->signIn()));
  }

}
