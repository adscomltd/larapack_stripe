<?php

namespace Adscom\LarapackStripe;

use App\Helpers\ArrayUtils;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PaymentAccount;
use App\Models\PaymentAccountUser;
use App\Models\PaymentCard;
use App\Models\PaymentToken;
use Adscom\LarapackStripe\Webhook\StripeWebhookHandler;
use Adscom\LarapackPaymentManager\Drivers\PaymentDriver;
use Adscom\LarapackPaymentManager\Interfaces\ITokenable;
use Adscom\LarapackPaymentManager\Interfaces\ICreditCardPaymentDriver;
use Adscom\LarapackPaymentManager\Drivers\PaymentFinalizeHandler;
use Adscom\LarapackPaymentManager\Exceptions\PaymentDriverException;
use Adscom\LarapackPaymentManager\Exceptions\PaymentRedirectionException;
use Adscom\LarapackPaymentManager\PaymentResponse;
use Str;
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
   * @throws PaymentRedirectionException
   */
  public function addPaymentMethod(PaymentCard $paymentCard): array
  {
    /* @var PaymentMethod $paymentMethod */
    $paymentMethod = $this->client->paymentMethods->create([
      'type' => 'card',
      'card' => [
        'number' => $paymentCard->number,
        'exp_month' => $paymentCard->exp_month,
        'exp_year' => $paymentCard->exp_year,
        'cvc' => $paymentCard->cvc,
      ],
      'billing_details' => $paymentCard->billing_address,
    ]);

    return [
      'token' => $paymentMethod->id,
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

    $this->order->lineItems->each(
      fn(OrderItem $item) => $this->client->invoiceItems->create([
        'customer' => $customer->id,
        'currency' => Str::of($this->order->processor_currency)->lower(),
        'description' => $item->product->name,
        'unit_amount' => $this->formatAmount($item->price, $this->order->processor_currency),
        'quantity' => $item->qty,
      ])
    );

    $this->order->lineItems->each(
      fn(OrderItem $item) => $this->client->invoiceItems->create([
        'customer' => $customer->id,
        'currency' => Str::of($this->order->processor_currency)->lower(),
        'description' => $item->product->name,
        'unit_amount' => $this->formatAmount($item->price, $this->order->processor_currency),
        'quantity' => $item->qty,
      ])
    );
  }

  /**
   * @throws ApiErrorException
   * @throws PaymentRedirectionException
   */
  protected function getPaymentIntent(): PaymentIntent
  {
    $customer = $this->getOrCreateCustomer();
    $token = $this->getOrCreateToken($this->paymentCard)->token;

    $this->createInvoiceItems();

    $invoice = $this->client->invoices->create([
      'customer' => $customer->id,
      'auto_advance' => false, /* Auto-finalize this draft after ~1 hour */
      'collection_method' => 'send_invoice',
      'days_until_due' => 0,
      'default_payment_method' => $token,
      'description' => 'Shipping: '.$this->order->shipping_data['method_name'],
      'payment_settings' => [
        'payment_method_types' => ['card'],
      ],
      'statement_descriptor' => $this->paymentAccount->descriptor,
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
    $paymentIntent->updateAttributes([
      'shipping' => [
        'address' => [
          'city' => $this->order->shippingAddress->city,
          'country' => $this->order->shippingAddress->country->iso,
          'line1' => $this->order->shippingAddress->address_line_1,
          'line2' => $this->order->shippingAddress->address_line_2,
          'postal_code' => $this->order->shippingAddress->zip_code,
          'state' => $this->order->shippingAddress->state,
        ],
        'carrier' => $this->order->shipping_data['method_name'],
        'name' => $this->order->shippingAddress->name,
        'phone' => $this->order->shippingAddress->phone,
      ],
      'metadata' => $this->getCurrentMetaData(),
      'receipt_email' => $this->user->email,
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
          ['redirect_to' => $url, 'payment_card_id' => $this->paymentCard->id],
        );
      }

      $status = $this->getPaymentStatus($paymentIntent->status);
      $this->paymentResponse->setPaidAmount(
        $this->getOriginalAmount($paymentIntent->amount_received, $this->order->processor_currency)
      );
      $this->paymentResponse->setStatus($status);
    } catch (ApiErrorException $e) {
      throw PaymentDriverException::fromException($e);
    }
  }

  public function handleResponse($paypalResponse): PaymentResponse
  {
    $this->paymentResponse->setResponse($paypalResponse->toArray());
    $this->paymentResponse->setProcessorCurrency($this->order->processor_currency);
    $this->paymentResponse->setProcessorStatus($paypalResponse->status);
    $this->paymentResponse->setProcessorTransactionId($paypalResponse->id);
    $this->paymentResponse->setPaymentTokenId(
      PaymentToken::where('token', $paypalResponse->payment_method)->first()?->id
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

    $this->paymentToken = $this->user
      ->paymentTokens()
      ->where('payment_account_id', $this->paymentAccount->id)
      ->where('payment_card_id', $this->paymentCard->id)
      ->first();

    if (!$this->paymentToken) {
      $data = $this->addPaymentMethod($paymentCard);

      $this->paymentToken = $this->user
        ->paymentTokens()
        ->create(array_merge($data,
          [
            'payment_account_id' => $this->paymentAccount->id,
            'payment_card_id' => $this->paymentCard->id,
          ]));
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

      PaymentAccountUser::create([
        'user_id' => $this->user->id,
        'payment_account_id' => $this->paymentAccount->id,
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
    return match ($status) {
      self::STATUS_REQUIRES_PAYMENT_METHOD => Payment::STATUS_CREATED,
      self::STATUS_SUCCEEDED => Payment::STATUS_PAID,
      self::STATUS_CANCELLED => Payment::STATUS_REFUND,
      self::STATUS_PROCESSING => Payment::STATUS_INITIATED,
      self::STATUS_REQUIRES_ACTION => Payment::STATUS_DECLINED,
      default => Payment::STATUS_ERROR,
    };
  }
}
