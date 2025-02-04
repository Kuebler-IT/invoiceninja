<?php

/**
 * Invoice Ninja (https://invoiceninja.com).
 *
 * @link https://github.com/invoiceninja/invoiceninja source repository
 *
 * @copyright Copyright (c) 2021. Invoice Ninja LLC (https://invoiceninja.com)
 *
 * @license https://opensource.org/licenses/AAL
 */

namespace App\Http\Controllers;

use App\Http\Requests\Payments\PaymentWebhookRequest;
use App\Libraries\MultiDB;
use Auth;

class PaymentWebhookController extends Controller
{
    public function __invoke(PaymentWebhookRequest $request, string $company_key, string $company_gateway_id)
    {

    	MultiDB::findAndSetDbByCompanyKey($company_key);

        $payment = $request->getPayment();
        $client = is_null($payment) ? $request->getClient() : $payment->client;

        // $contact= $client->primary_contact()->first();
        // Auth::guard('contact')->login($contact, true);

        return $request->getCompanyGateway()
            ->driver($client)
            ->processWebhookRequest($request, $payment);
    }
}
