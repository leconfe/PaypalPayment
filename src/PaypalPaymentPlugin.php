<?php

namespace PaypalPayment;

use App\Classes\Plugin;
use App\Facades\Hook;
use App\Infolists\Components\LivewireEntry;
use App\Infolists\Components\VerticalTabs as InfolistsVerticalTabs;
use App\Models\Payment;
use Filament\Panel;
use PaypalPayment\Frontend\ScheduledConference\Pages\PaypalPage;
use PaypalPayment\Panel\ScheduledConference\Livewire\PaypalSetting;
use Rahmanramsi\LivewirePageGroup\PageGroup;

class PaypalPaymentPlugin extends Plugin
{
    public function boot()
    {
        if (! app()->getCurrentScheduledConference()) {
            return;
        }

        if ($this->isProperlySetup()) {
            Hook::add('PaymentManager::getPaymentMethodOptions', function ($hookName, &$options) {
                $options['paypal'] = 'Paypal';

                return false;
            });

            Hook::add('Frontend::Payment::handleRequestUrl', function ($hookName, Payment $payment, array &$data, string &$requestUrl) {

                if ($data['payment_method'] == 'paypal') {
                    $requestUrl = route(PaypalPage::getRouteName('scheduledConference'), ['id' => $payment->getKey()]);
                }

                return true;
            });

        }
    }

    public function onFrontend(PageGroup $frontend): void
    {
        if ($frontend->getId() !== 'scheduledConference') {
            return;
        }

        $frontend->discoverPages(in: $this->pluginPath.'/src/Frontend/ScheduledConference/Pages', for: 'PaypalPayment\\Frontend\\ScheduledConference\\Pages');

    }

    public function onPanel(Panel $panel): void
    {
        if ($panel->getId() !== 'scheduledConference') {
            return;
        }

        $panel->discoverLivewireComponents(in: $this->pluginPath.'/src/Panel/ScheduledConference/Livewire', for: 'PaypalPayment\\Panel\\ScheduledConference\\Livewire');

        Hook::add('Payments::PaymentMethodTabs', function ($hookName, &$tabs) {
            $tabs[] = InfolistsVerticalTabs\Tab::make('paypal')
                ->label('Paypal')
                ->icon('heroicon-o-credit-card')
                ->schema([
                    LivewireEntry::make('settings')
                        ->livewire(PaypalSetting::class),
                ]);
        });
    }

    public function isProperlySetup(): bool
    {
        return $this->getClientId() && $this->getClientSecret();
    }

    public function isTestMode(): bool
    {
        return $this->getSetting('test_mode', false);
    }

    public function getClientId(): ?string
    {
        return $this->isTestMode() ? $this->getSetting('client_id_test') : $this->getSetting('client_id');
    }

    public function getClientSecret(): ?string
    {
        return $this->isTestMode() ? $this->getSetting('client_secret_test') : $this->getSetting('client_secret');
    }
}
