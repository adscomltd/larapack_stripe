<?php

namespace Adscom\LarapackStripe\Webhook\Handlers;

use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Stripe\Dispute;

class ChargeDisputeClosedHandler extends AbstractStripeWebhookEventHandler
{
  protected function getPaymentResponse(): ?PaymentResponse
  {
    /** @var Dispute $dispute */
    $dispute = $this->stripeObject;

    if ($dispute->status !== Dispute::STATUS_LOST) {
      return null;
    }

    $disputeAmount = $this->driver->getOriginalAmount($dispute->amount, $dispute->currency);
    $this->paymentResponse->setPaidAmount($disputeAmount);

    $this->paymentResponse->setStatus(PaymentDriver::getPaymentContractClass()::getChargebackStatus());

    return $this->paymentResponse;
  }
}
