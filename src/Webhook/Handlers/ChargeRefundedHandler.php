<?php

namespace Adscom\LarapackStripe\Webhook\Handlers;

use App\Models\Payment;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Stripe\Charge;

class ChargeRefundedHandler extends AbstractStripeWebhookEventHandler
{
  protected function getPaymentResponse(): ?PaymentResponse
  {
    /** @var Charge $charge */
    $charge = $this->stripeObject;

    $refundedAmount = $this->driver->getOriginalAmount($charge->amount_refunded, $charge->currency);
    $this->paymentResponse->setPaidAmount($refundedAmount);

    $status = ($refundedAmount === $this->driver->payment->order->due_amount)
      ? Payment::STATUS_REFUND
      : Payment::STATUS_PARTIAL_REFUND;

    $this->paymentResponse->setStatus($status);

    return $this->paymentResponse;
  }
}
