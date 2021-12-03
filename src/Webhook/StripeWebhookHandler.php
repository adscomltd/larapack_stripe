<?php

namespace Adscom\LarapackStripe\Webhook;

use App\Models\Payment;
use Adscom\LarapackStripe\StripeDriver;
use Adscom\LarapackStripe\Webhook\Handlers\AbstractStripeWebhookEventHandler;
use Adscom\LarapackPaymentManager\Interfaces\IWebhookHandler;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Arr;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Exception\UnexpectedValueException;
use Stripe\Webhook;

class StripeWebhookHandler implements IWebhookHandler
{
  protected Event $event;

  public function __construct(protected StripeDriver $driver)
  {
  }

  /**
   * @param  array  $data
   * @return Payment
   * @throws ModelNotFoundException
   */
  public function getPaymentForWebhook(array $data = []): Payment
  {
    if ($uuid = Arr::get($data, 'data.object.metadata.payment_uuid')) {
      return Payment::where('uuid', $uuid)
        ->firstOrFail();
    }

    if ($paymentTransactionId = Arr::get($data, 'data.object.payment_intent',
      Arr::get($data, 'data.object.id')
    )) {
      return Payment::where('processor_transaction_id', $paymentTransactionId)
        ->latest()
        ->firstOrFail();
    }

    abort(404, "Can't fetch payment from webhook");
  }

  public function isWebhookValid(array $data): bool
  {
    $endpointSecret = $this->driver->getConfig()['webhook_secret_key'];
    $request = request();
    $sigHeader = $request->header('stripe-signature');
    $payload = $request->getContent();

    try {
      $this->event = Webhook::constructEvent(
        $payload, $sigHeader, $endpointSecret
      );
    } catch (UnexpectedValueException $e) {
      return false;
      // Invalid payload
    } catch (SignatureVerificationException $e) {
      // Invalid signature
      return false;
    }

    return true;
  }

  public function process(array $data = []): void
  {
    AbstractStripeWebhookEventHandler::handle($this->driver, $this->event);
  }
}
