<div class="px-4">
    <h1>TODO</h1>
    <p class="pb-4">
        {{__('To integrate peachpayments with your application, you need to do the following steps:')}}
    </p>
    <ol class="list-decimal ">
        <li class="pb-4">
            <strong>
                {{ __('Login to ') }} <a href="https://dashboard.peachpayments.com/" target="_blank" class="text-blue-500 hover:underline">{{ __('Peachpayments Dashboard') }}</a>
            </strong>
        </li>
        <li class="pb-4">
            <p>
                {{ __('Depending whether you want to use the test or live environment, you might need to tick the "Test mode" switch in the top right corner.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>
                {{ __('Publishable key') }}
            </strong>
            <p>
                {{ __('On the top menu, click on "Developers" > "API keys". Copy the "Publishable key" and enter it into the field in the form.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>
                {{ __('Secret key') }}
            </strong>
            <p>
                {{ __('On the same page, click "Reveal live key token" and copy the "Secret key" and enter it into the field in the form.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>
                {{ __('Webhook Signing Secret') }}
            </strong>
            <p>
                {{ __('On the same page, click on "Webhooks" tab. Click on "Add endpoint" and enter the URL below.') }}
                <code class="block px-4 py-2 my-4 overflow-x-scroll bg-gray-100">
                    <!--{{ route('payments-providers.stripe.webhook') }}TODO -->
                </code>
                {{ __('Click on "Select events" then select all the following events:') }}
            </p>
            <ul class="list-disc ps-4">
                <li>
                    {{ __('Check all the "payment_intent.xyz" events.') }}
                </li>
                <li>
                    {{ __('Check all the "customer.xyz" events.') }}
                </li>
                <li>
                    {{ __('Check all the "invoice.xyz" events.') }}
                </li>
                <li>
                    {{ __('Check the "charge.refunded" event.') }}
                </li>
                <li>
                    {{ __('Check the "charge.failed" event.') }}
                </li>
            </ul>

            <p class="mt-4">
                {{ __('Click on "Add endpoint" and copy the generated webhook signing secret and enter it into the field in the form.') }}
            </p>
        </li>
    </ol>
</div>
