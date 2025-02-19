<div class="px-4">

    <p class="pb-4">
        {{__('To integrate PeachPayments with your application, you need to do the following steps:')}}
    </p>
    <ol class="list-decimal ">
        <li class="pb-4">
            <strong>
                {{ __('Login to ') }} <a href="https://dashboard.peachpayments.com/" target="_blank" class="text-blue-500 hover:underline">{{ __('PeachPayments Dashboard') }}</a>
            </strong>
        </li>
        <li class="pb-4">
            <p>
                {{ __('Ensure you are in the correct environment. Either Live or Sandbox.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>
                {{ __('Entity ID') }}
            </strong>
            <p>
                {{ __('On the left menu, click on "Checkout". Copy the "Entity ID" from the "Hosted Checkout" section and enter it into the field in the form.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>
                {{ __('Secret Token') }}
            </strong>
            <p>
                {{ __('On the same page, click copy the "Secret token" from the "Hosted Checkout" and enter it into the field in the form.') }}
            </p>
        </li>
        <li class="pb-4">
            <strong>
                {{ __('Webhook') }}
            </strong>
            <p>
                {{ __('On the same page, Click on "Add webhook URL" and enter the URL below.') }}
                <code class="block px-4 py-2 my-4 overflow-x-scroll bg-gray-100">
                    {{ route('payments-providers.peachpayments.webhook') }}
                </code>
            </p>
            <p class="mt-4">
                {{ __('Click on "Add endpoint" and copy the generated webhook signing secret and enter it into the field in the form.') }}
            </p>
        </li>
    </ol>
</div>
