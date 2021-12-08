<?php

namespace Adscom\LarapackStripe\Webhook\Handlers;

use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
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
    if ($this->driver->payment->getStatus() === PaymentDriver::getPaymentContractClass()::getPaidStatus()) {
      return null;
    }

    $paidAmount = $this->driver->getOriginalAmount($paymentIntent->amount_received, $paymentIntent->currency);
    $this->paymentResponse->setPaidAmount($paidAmount);

    $this->paymentResponse->setStatus(PaymentDriver::getPaymentContractClass()::getPaidStatus());

    return $this->paymentResponse;
  }
}
