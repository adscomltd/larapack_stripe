<?php

namespace Adscom\LarapackStripe;

use Illuminate\Support\ServiceProvider;

class LarapackStripeServiceProvider extends ServiceProvider
{
  public function boot(): void
  {
    resolve('PaymentManager')->extend('stripe', function ($app) {
      return new StripeDriver;
    });
  }
}
