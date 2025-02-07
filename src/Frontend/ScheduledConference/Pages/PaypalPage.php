<?php

namespace PaypalPayment\Frontend\ScheduledConference\Pages;

use App\Facades\Plugin;
use App\Frontend\Website\Pages\Page;
use App\Managers\PaymentManager;
use App\Models\Payment;
use Filament\Notifications\Notification;
use Illuminate\Support\Str;
use Omnipay\Omnipay;

class PaypalPage extends Page
{
    protected static string $view = 'PaypalPayment::frontend.scheduledConference.pages.paypal';

    public function __invoke()
    {
        $request = app('request');
        $id = $request->input('id');

        abort_if(! $id, 404);

        $paymentQueue = Payment::query()
            ->where('id', $id)
            ->first();

        abort_if(! $paymentQueue, 404);
        abort_if($paymentQueue->isExpired(), 403, 'Payment Queue expired');

        if ($request->input('paymentId') && $request->input('PayerID') && $request->input('token')) {
            return $this->completePayment($paymentQueue);
        }

        return $this->handlePayment($paymentQueue);
    }

    public function handlePayment(Payment $paymentQueue)
    {
        $paypalPlugin = Plugin::getPlugin('PaypalPayment');

        $gateway = Omnipay::create('PayPal_Rest');
        $gateway->initialize([
            'clientId' => $paypalPlugin->getClientId(),
            'secret' => $paypalPlugin->getClientSecret(),
            'testMode' => $paypalPlugin->isTestMode(),
        ]);

        $transaction = $gateway->purchase([
            'amount' => number_format($paymentQueue->amount, 2, '.', ''),
            'currency' => $paymentQueue->currency,
            'description' => $paymentQueue->getMeta('title'),
            'returnUrl' => route(static::getRouteName(), ['id' => $paymentQueue->id]),
            'cancelUrl' => route(static::getRouteName(), ['id' => $paymentQueue->id]),
        ]);

        $response = $transaction->send();

        if ($response->isRedirect()) {
            return redirect($response->getRedirectUrl());
        }
        if (! $response->isSuccessful()) {
            return abort(403, $response->getMessage());
        }

        abort(403, 'PayPal response was not redirect!');
    }

    public function completePayment(Payment $paymentQueue)
    {
        try {
            $request = app('request');
            $paypalPlugin = Plugin::getPlugin('PaypalPayment');

            $gateway = Omnipay::create('PayPal_Rest');
            $gateway->initialize([
                'clientId' => $paypalPlugin->getClientId(),
                'secret' => $paypalPlugin->getClientSecret(),
                'testMode' => $paypalPlugin->isTestMode(),
            ]);

            $transaction = $gateway->completePurchase([
                'payer_id' => $request->input('PayerID'),
                'transactionReference' => $request->input('paymentId'),
            ]);

            $response = $transaction->send();
            if (! $response->isSuccessful()) {
                abort(403, $response->getMessage());
            }

            $data = $response->getData();

            if ($data['state'] != 'approved') {
                abort(403, 'State '.$data['state'].' is not approved!');
            }

            if (count($data['transactions']) != 1) {
                abort(403, 'Unexpected transaction count!');
            }
            $transaction = $data['transactions'][0];

            if (
                (float) $transaction['amount']['total'] != (float) $paymentQueue->amount
                || $transaction['amount']['currency'] != Str::upper($paymentQueue->currency)
            ) {
                $message = 'Amounts ('.$transaction['amount']['total'].' '.$transaction['amount']['currency'].' vs '.$paymentQueue->amount.' '.$paymentQueue->currency.') don\'t match!';

                abort(403, $message);
            }

            $paymentManager = PaymentManager::get();

            $requestUrl = $paymentQueue->getMeta('request_url');
            $paymentManager->fulfillQueued($paymentQueue, 'paypal', auth()->id());

            Notification::make()
                ->title('Payment Success')
                ->success()
                ->send();

            return redirect()->to($requestUrl);
        } catch (\Exception $e) {
            abort(403, $e->getMessage());
        }
    }
}
