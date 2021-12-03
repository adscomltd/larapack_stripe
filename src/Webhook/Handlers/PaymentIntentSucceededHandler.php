<?php

namespace Adscom\LarapackStripe\Webhook\Handlers;

use App\Models\Payment;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Stripe\PaymentIntent;

class PaymentIntentSucceededHandler extends AbstractStripeWebhookEventHandler
{
  protected function getPaymentResponse(): ?PaymentResponse
  {
    /** @var PaymentIntent $paymentIntent */
    $paymentIntent = $this->stripeObject;

    // avoid duplicate payments creation
    if ($this->driver->payment->status === Payment::STATUS_PAID) {
      return null;
    }

    $paidAmount = $this->driver->getOriginalAmount($paymentIntent->amount_received, $paymentIntent->currency);
    $this->paymentResponse->setPaidAmount($paidAmount);

    $this->paymentResponse->setStatus(Payment::STATUS_PAID);

    return $this->paymentResponse;
  }
}
