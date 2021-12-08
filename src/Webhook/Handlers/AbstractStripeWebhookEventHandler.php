<?php

namespace Adscom\LarapackStripe\Webhook\Handlers;

use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
use App\Models\PaymentToken;
use Adscom\LarapackStripe\StripeDriver;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Str;
use Stripe\Event;
use Stripe\StripeObject;

abstract class AbstractStripeWebhookEventHandler
{
  protected StripeObject $stripeObject;
  protected PaymentResponse $paymentResponse;

  protected function __construct(protected StripeDriver $driver, protected Event $event)
  {
    $this->stripeObject = $event->data->object;
    $this->paymentResponse = $driver->paymentResponse->clone();
  }

  public static function handle(StripeDriver $driver, Event $event): void
  {
    $className = __NAMESPACE__.'\\'.Str::of($event->type)->lower()->replace('.', '_')->studly().'Handler';

    if (!class_exists($className)) {
      return;
    }

    $instance = new $className($driver, $event);
    $instance->handleResponse();
    $paymentResponse = $instance->getPaymentResponse();
    ray($paymentResponse);

    if ($paymentResponse) {
      $instance->driver->createPaymentFromResponse($paymentResponse);
    }
  }

  abstract protected function getPaymentResponse(): ?PaymentResponse;

  public function handleResponse(): void
  {
    $this->paymentResponse->setIsWebHookPayment(true);
    $this->paymentResponse->setResponse($this->event->toArray());
    $this->paymentResponse->setProcessorCurrency($this->driver->order->getProcessorCurrency());
    $this->paymentResponse->setProcessorStatus($this->stripeObject->status);
    $this->paymentResponse->setProcessorTransactionId($this->stripeObject->id);
    $this->paymentResponse->setPaymentTokenId(
      PaymentDriver::getPaymentTokenContractClass()::find(['token' => $this->stripeObject->payment_method])
        ?->getId()
    );
  }
}
