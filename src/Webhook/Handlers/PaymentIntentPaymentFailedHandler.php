<?php

namespace Adscom\LarapackStripe\Webhook\Handlers;

use App\Models\Payment;
use Adscom\LarapackPaymentManager\PaymentResponse;

class PaymentIntentPaymentFailedHandler extends AbstractStripeWebhookEventHandler
{
  protected function getPaymentResponse(): ?PaymentResponse
  {
    $this->paymentResponse->setStatus(Payment::STATUS_ERROR);

    return $this->paymentResponse;
  }
}
