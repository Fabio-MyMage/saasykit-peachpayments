<div class="px-4">

    <p class="pb-4">
        {{ __('To integrate Peach Payments with your application, follow these steps:') }}
    </p>
    <ol class="list-decimal">
        <li class="pb-4">
            <strong>
                {{ __('Log in to the ') }} <a href="https://dashboard.peachpayments.com/" target="_blank" class="text-blue-500 hover:underline">{{ __('Peach Payments Dashboard') }}</a>
            </strong>
            <p>
                {{ __('Use the sandbox dashboard (sandbox-dashboard.peachpayments.com) while testing, and the live dashboard for production. Toggle the "Test Mode" switch on this page to match.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>{{ __('Entity ID & Secret Token') }}</strong>
            <p>
                {{ __('On the left menu, open "Checkout". Copy the "Entity ID" and "Secret Token" from the Hosted Checkout section into the fields on this page.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>{{ __('Client ID, Client Secret & Merchant ID') }}</strong>
            <p>
                {{ __('Open "API Keys" (or "Developers") and copy the Client ID and Client Secret used for OAuth, along with your Merchant ID, into the fields on this page.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>{{ __('Recurring Payments') }}</strong>
            <p>
                {{ __('Ask Peach Payments support to enable the "Recurring Payments" product for your account. Once enabled, a separate Recurring Entity ID and Recurring Access Token will be issued — enter both into the fields on this page. These are required for automatic subscription renewal charges.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>{{ __('Allowlist your domain') }}</strong>
            <p>
                {{ __('Under "Checkout" settings, add your application\'s domain to the allowlist. Peach Payments rejects checkout requests from domains that are not allowlisted.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>{{ __('Webhook URL') }}</strong>
            <p>
                {{ __('Under "Checkout" settings, set the notification/webhook URL to:') }}
                <code class="block px-4 py-2 my-4 overflow-x-scroll bg-gray-100">
                    {{ route('payments-providers.peachpayments.webhook') }}
                </code>
            </p>
            <p class="mt-4">
                {{ __('Peach Payments sends an initial configuration ping to this URL to validate it — this is handled automatically by the integration, no further action is needed.') }}
            </p>
        </li>
    </ol>
</div>
