<?php

namespace Adscom\LarapackStripe\Webhook\Handlers;

use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
use Adscom\LarapackPaymentManager\PaymentResponse;

class PaymentIntentPaymentFailedHandler extends AbstractStripeWebhookEventHandler
{
  protected function getPaymentResponse(): ?PaymentResponse
  {
    $this->paymentResponse->setStatus(PaymentDriver::getPaymentContractClass()::getErrorStatus());

    return $this->paymentResponse;
  }
}
