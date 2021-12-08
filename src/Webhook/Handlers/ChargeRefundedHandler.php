<?php

namespace Adscom\LarapackStripe\Webhook\Handlers;

use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
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

    $status = ($refundedAmount === $this->driver->payment->getOrder()->getDueAmount())
      ? PaymentDriver::getPaymentContractClass()::getRefundStatus()
      : PaymentDriver::getPaymentContractClass()::getPartialRefundStatus();

    $this->paymentResponse->setStatus($status);

    return $this->paymentResponse;
  }
}
