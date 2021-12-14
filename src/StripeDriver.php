<?php

namespace Adscom\LarapackStripe;

use Adscom\LarapackPaymentManager\Contracts\OrderItem;
use Adscom\LarapackPaymentManager\Contracts\PaymentAccount;
use Adscom\LarapackPaymentManager\Contracts\PaymentCard;
use Adscom\LarapackPaymentManager\Contracts\PaymentToken;
use Adscom\LarapackStripe\Helpers\ArrayUtils;
use Adscom\LarapackStripe\Webhook\StripeWebhookHandler;
use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
use Adscom\LarapackPaymentManager\Interfaces\ITokenable;
use Adscom\LarapackPaymentManager\Interfaces\ICreditCardPaymentDriver;
use Adscom\LarapackPaymentManager\Drivers\PaymentFinalizeHandler;
use Adscom\LarapackPaymentManager\Exceptions\PaymentDriverException;
use Adscom\LarapackPaymentManager\Exceptions\PaymentRedirectionException;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Str;
use Stripe\Card;
use Stripe\Customer;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Stripe\PaymentMethod;
use Stripe\StripeClient;

class StripeDriver extends PaymentDriver implements
  ICreditCardPaymentDriver,
  ITokenable
{
  /**
   * API statuses on create payment intent
   */
  public const STATUS_REQUIRES_PAYMENT_METHOD = 'requires_payment_method';
  public const STATUS_REQUIRES_CONFIRMATION = 'requires_confirmation';
  public const STATUS_REQUIRES_ACTION = 'requires_action';
  public const STATUS_PROCESSING = 'processing';
  public const STATUS_REQUIRES_CAPTURE = 'requires_capture';
  public const STATUS_CANCELLED = 'canceled';
  public const STATUS_SUCCEEDED = 'succeeded';

  public const ZERO_DECIMAL_CURRENCIES = [
    'BIF',
    'CLP',
    'DJF',
    'GNF',
    'JPY',
    'KMF',
    'KRW',
    'MGA',
    'PYG',
    'RWF',
    'UGX',
    'VND',
    'VUV',
    'XAF',
    'XOF',
    'XPF',
  ];

  public StripeClient $client;
  protected ?Customer $customer = null;
  protected ?PaymentToken $paymentToken = null;
  protected ?StripeWebhookHandler $webhookHandler = null;
  protected PaymentFinalizeHandler $finalizeHandler;

  public function __construct()
  {
    parent::__construct();
    $this->webhookHandler = new StripeWebhookHandler($this);
    $this->finalizeHandler = new PaymentFinalizeHandler($this);
  }

  public function setup(PaymentAccount $paymentAccount): void
  {
    parent::setup($paymentAccount);

    $this->client = new StripeClient($this->config['secret_key']);
  }

  /**
   * Attach card to stripe customer and get token
   * @param  PaymentCard  $paymentCard
   * @return array
   * @throws ApiErrorException
   */
  public function addCard(PaymentCard $paymentCard): array
  {
    $customer = $this->getOrCreateCustomer();

    $token = $this->client->tokens->create([
      'card' => [
        'number' => $paymentCard->getNumber(),
        'exp_month' => $paymentCard->getExpirationMonth(),
        'exp_year' => $paymentCard->getExpirationYear(),
        'cvc' => $paymentCard->getCVC(),
        'name' => $paymentCard->getName(),
        'address_line1' => $paymentCard->getAddressLine1(),
        'address_line2' => $paymentCard->getAddressLine2(),
        'address_city' => $paymentCard->getCity(),
        'address_state' => $paymentCard->getState(),
        'address_country' => $paymentCard->getCountryISO(),
      ],
    ]);

    /* @var Card $card */
    $card = $this->client->customers->createSource(
      $customer->id,
      [
        'source' => $token->id,
        'metadata' => $this->getCurrentMetaData(),
      ]
    );

    return [
      'token' => $card->id,
    ];
  }

  public function getCurrentMetaData(array $additional = [], array $options = []): array
  {
    $data = array_merge(
      parent::getCurrentMetaData($additional, $options),
    );

    return ArrayUtils::flatten($data);
  }

  /**
   * @throws ApiErrorException
   */
  public function deletePaymentMethod(string $id): array
  {
    return $this->client->paymentMethods->detach(
      $id,
      []
    )->toArray();
  }

  /**
   * @throws ApiErrorException
   */
  protected function createInvoiceItems(): void
  {
    $customer = $this->getOrCreateCustomer();

    $this->order->getLineItems()->each(
      fn(OrderItem $item) => $this->client->invoiceItems->create([
        'customer' => $customer->id,
        'currency' => Str::of($this->order->getProcessorCurrency())->lower(),
        'description' => $item->getName(),
        'unit_amount' => $this->formatAmount($item->getPrice(), $this->order->getProcessorCurrency()),
        'quantity' => $item->getQuantity(),
      ])
    );

    $this->client->invoiceItems->create([
      'customer' => $customer->id,
      'currency' => Str::of($this->order->getProcessorCurrency())->lower(),
      'description' => $this->order->getShippingName(),
      'amount' => $this->formatAmount($this->order->getShippingCost(), $this->order->getProcessorCurrency()),
    ]);
  }

  /**
   * @throws ApiErrorException
   * @throws PaymentRedirectionException
   */
  protected function getPaymentIntent(): PaymentIntent
  {
    $customer = $this->getOrCreateCustomer();

    $this->createInvoiceItems();

    $invoice = $this->client->invoices->create([
      'customer' => $customer->id,
      'auto_advance' => false, /* Auto-finalize this draft after ~1 hour */
      'collection_method' => 'send_invoice',
      'days_until_due' => 0,
      'description' => 'Shipping: '.$this->order->getShippingName(),
      'statement_descriptor' => $this->paymentAccount->getDescriptor(),
      'metadata' => $this->getCurrentMetaData(),
    ]);

    $invoice->finalizeInvoice();

    // we need paymentIntent on each payment
    return $this->client->paymentIntents->retrieve($invoice->payment_intent);
  }

  /**
   * @throws ApiErrorException
   */
  protected function setupPaymentIntent(PaymentIntent $paymentIntent): void
  {
    $address = $this->order->getAddress();
    $customer = $this->getOrCreateCustomer();
    $token = $this->getOrCreateToken($this->paymentCard)->getToken();

    $paymentIntent->updateAttributes([
      'shipping' => [
        'address' => [
          'city' => $address->getCity(),
          'country' => $address->getCountryISO(),
          'line1' => $address->getAddressLine1(),
          'line2' => $address->getAddressLine2(),
          'postal_code' => $address->getZipCode(),
          'state' => $address->getState(),
        ],
        'carrier' => $this->order->getShippingName(),
        'name' => $address->getName(),
        'phone' => $address->getPhone(),
      ],
      'metadata' => $this->getCurrentMetaData(),
      'receipt_email' => $this->user->email,
      'payment_method' => $token,
      'payment_settings' => [
        'payment_method_types' => ['card'],
      ],
    ]);
    $paymentIntent->save();

    $paymentIntent->confirm([
      'return_url' => $this->getFinalizeUrl(),
    ]);
  }

  /**
   * @throws PaymentDriverException
   * @throws PaymentRedirectionException
   */
  public function processPayment(array $postData = []): void
  {
    try {
      $paymentIntent = $this->getPaymentIntent();
      $this->setupPaymentIntent($paymentIntent);

      $this->handleResponse($paymentIntent);

      // todo: 3ds flow
      if ($paymentIntent->status === self::STATUS_REQUIRES_ACTION) {
        $url = $paymentIntent->next_action->redirect_to_url->url;

        throw new PaymentRedirectionException($url,
          $paymentIntent->toArray(),
          'payment_redirection_exception:payment_intent.requires_action',
          ['redirect_to' => $url, 'payment_card_id' => $this->paymentCard->getId()],
        );
      }

      $status = $this->getPaymentStatus($paymentIntent->status);
      $this->paymentResponse->setPaidAmount(
        $this->getOriginalAmount($paymentIntent->amount_received, $this->order->getProcessorCurrency())
      );
      $this->paymentResponse->setStatus($status);
    } catch (ApiErrorException $e) {
      throw PaymentDriverException::fromException($e);
    }
  }

  public function handleResponse($paypalResponse): PaymentResponse
  {
    $this->paymentResponse->setResponse($paypalResponse->toArray());
    $this->paymentResponse->setProcessorCurrency($this->order->getProcessorCurrency());
    $this->paymentResponse->setProcessorStatus($paypalResponse->status);
    $this->paymentResponse->setProcessorTransactionId($paypalResponse->id);
    $this->paymentResponse->setPaymentTokenId(
      PaymentDriver::getPaymentTokenContractClass()::find(['token' => $paypalResponse->payment_method])
        ->getId()
    );

    return $this->paymentResponse;
  }

  /**
   * @throws ApiErrorException|PaymentRedirectionException
   */
  public function getOrCreateToken(PaymentCard $paymentCard): PaymentToken
  {
    if ($this->paymentToken) {
      return $this->paymentToken;
    }

    $this->paymentToken = self::getPaymentTokenContractClass()::find([
      'payment_account_id' => $this->paymentAccount->getId(),
      'payment_card_id' => $this->paymentCard->getId(),
      'user_id' => $this->user->id,
    ]);

    if (!$this->paymentToken) {
      $data = $this->addCard($paymentCard);

      $this->paymentToken = self::getPaymentTokenContractClass()::create(array_merge($data,
          [
            'user_id' => $this->user->id,
            'payment_account_id' => $this->paymentAccount->getId(),
            'payment_card_id' => $this->paymentCard->getId(),
          ])
      );
    }

    return $this->paymentToken;
  }

  protected function isZeroDecimalCurrency(string $currency): bool
  {
    return in_array($currency, self::ZERO_DECIMAL_CURRENCIES, true);
  }

  public function formatAmount(float $amount, string $currency): int
  {
    return $this->isZeroDecimalCurrency($currency) ? $amount : $amount * 100;
  }

  public function getOriginalAmount(float $amount, string $currency): float
  {
    return $this->isZeroDecimalCurrency($currency) ? $amount : rounded($amount / 100);
  }

  /**
   * create or get customer on stripe
   * @return Customer
   * @throws ApiErrorException
   */
  protected function getOrCreateCustomer(): Customer
  {
    if ($this->customer) {
      return $this->customer;
    }

    if ($this->paymentAccountData) {
      $this->customer = $this->client->customers->retrieve($this->paymentAccountData['id']);
    }

    if (!$this->customer) {
      $this->customer = $this->client->customers->create([
        'name' => $this->user->profile->name,
        'phone' => $this->user->phone,
        'email' => config('app.prefix')."_".$this->user->email,
      ]);

      $this->paymentAccount->createData([
        'user_id' => $this->user->id,
        'data' => $data = [
          'id' => $this->customer->id,
        ],
      ]);

      $this->paymentAccountData = $data;
    }

    return $this->customer;
  }

  protected function getPaymentStatus(string $status): int
  {
    $paymentClass = self::getPaymentContractClass();
    return match ($status) {
      self::STATUS_REQUIRES_PAYMENT_METHOD => $paymentClass::getCreatedStatus(),
      self::STATUS_SUCCEEDED => $paymentClass::getPaidStatus(),
      self::STATUS_CANCELLED => $paymentClass::getRefundStatus(),
      self::STATUS_PROCESSING => $paymentClass::getInitiatedStatus(),
      self::STATUS_REQUIRES_ACTION => $paymentClass::getDeclinedStatus(),
      default => $paymentClass::getErrorStatus(),
    };
  }
}
