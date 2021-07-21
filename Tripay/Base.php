<?php

/**
 * Plugin integrasi Tripay Payment Gateway dengan aMember.
 * Tested on aMember Pro v6.2.10
 *
 * Copyright (c) 2021 PT Trijaya Digital Group.
 *
 * @link https://tripay.co.id
 *
 */

class Am_Paysystem_TripayBase extends Am_Paysystem_Abstract
{
    const PLUGIN_REVISION = '0.0.1';

    const URL_DOCS = 'http://tripay.co.id/developer';

    const URL_API_SANDBOX = 'http://tripay.co.id/api-sandbox/transaction/create';
    const URL_API_PRODUCTION = 'http://tripay.co.id/api/transaction/create';

    protected $tripayPaymentMethod = null;
    protected $tripayPaymentMethodName = null;

    public function __construct(Am_Di $dependency, array $config)
    {
        $this->defaultTitle = ___('Tripay - '.$this->tripayPaymentMethodName);
        $this->defaultDescription = ___('Bayar Melalui '.$this->tripayPaymentMethodName.' - by Tripay');

        parent::__construct($dependency, $config);
    }

    /**
     * Ambil url API pembayaran tripay.
     * Jika opsi konfigurasi sandbox mode diaktifkan, gunakan url API sanbox,
     * atau gunakan url API production jika sebaliknya.
     *
     * @return string
     */
    public function getTripayApiUrl()
    {
        return $this->getConfig('sandbox_mode') ? self::URL_API_SANDBOX : self::URL_API_PRODUCTION;
    }

    /**
     * Nonaktifkan fitur cancel payment.
     *
     * @return bool
     */
    public function supportsCancelPage()
    {
        return false;
    }

    /**
     * Buat form untuk halaman setup/configuration.
     * Lihat: http://localhost/amember/admin-setup/tripay
     *
     * @param Am_Form_Setup $form
     *
     * @return void
     */
    public function _initSetupForm(Am_Form_Setup $form)
    {
        $form->addText('version', ['size' => 100, 'value' => self::PLUGIN_REVISION, 'readonly' => 'true'])
            ->setLabel(___('Versi Plugin'));

        $form->addHtml()
            ->setHtml(
                '<p>Dibawah ini anda dapat menyetel kredensial untuk koneksi ke server Tripay.<br>'.
                'Panduan integrasi dapat anda baca melalui '.
                '<a href="'.self::URL_DOCS.'" target="_blank" class="link"><b>Halaman Developer</b></a></p>'.
                '<p>Channel pembayaran pada plugin ini hanya mendukung <b>Closed Payment</b>.</p>'
            );


        $form->addText('merchant_code', ['size' => 100, 'placeholder' => ___('Kode merchant anda..')])
            ->setLabel(___('Kode Merchant'))
            ->addRule('required');

        $form->addText('api_key', ['size' => 100, 'placeholder' => ___('API key anda..')])
            ->setLabel(___('API Key'))
            ->addRule('required');

        $form->addText('private_key', ['size' => 100, 'placeholder' => ___('Private key anda..')])
            ->setLabel(___('Private Key'))
            ->addRule('required');

        $form->addCheckbox('sandbox_mode')
            ->setLabel(___('Gunakan API Sandbox (Testing)'));

        $form->addSelect('duration')
            ->setLabel(___('Durasi'))
            ->loadOptions($this->getDurations())
            ->addRule('required');
    }

    /**
     * Tampilkan panduan pada footer halaman setup amember.
     * Lihat: http://amember.local/admin-setup/tripay.
     *
     * @return string
     */
    public function getReadme()
    {
        return '<div class="am-element"><p>Plugin integrasi pembayaran menggunakan Tripay.'.
            '<br>Panduan intergrasi dapat dibaca di '.
            '<a href="'.self::URL_DOCS.'" target="_blank" class="link"><b>Halaman Developer</b></a></p></div>';
    }

    /**
     * Set dukungan mata uang (hanya rupiah).
     *
     * @return array
     */
    public function getSupportedCurrencies()
    {
        return ['IDR'];
    }

    /**
     * Ambil callback dari server tripay.
     *
     * @param Am_Mvc_Request  $request
     * @param Am_Mvc_Response $response
     * @param array           $invokeArgs
     *
     * @return Am_Mvc_Response|string
     */
    public function directAction($request, $response, $invokeArgs)
    {
        $actionName = $request->getActionName();
        $invoiceLog = $this->_logDirectAction($request, $response, $invokeArgs);

        switch ($actionName) {
            case 'ipn':
                $callbackSignature = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';

                $json = file_get_contents('php://input');
                $generatedSignature = hash_hmac('sha256', $json, $this->getConfig('private_key'));

                if (! Am_Paysystem_Transaction_TripayBase_Ipn::signatureIsMatching($generatedSignature, $callbackSignature)) {
                    $this->sendJsonResponseThenExit(['success' => false, 'message' => 'Signature mismatch.']);
                }

                $data = [];

                try {
                    $transaction = $this->createTransaction($request, $response, $invokeArgs);

                    if (! $transaction) {
                        throw new Am_Exception_InputError('Request not handled - createTransaction() returned null');
                    }

                    $invoiceId = $transaction->findInvoiceId();

                    if (is_null($invoiceId)) {
                        $this->sendJsonResponseThenExit(['success' => false, 'message' => 'Invoice not found.']);
                    }

                    $transaction->setInvoiceLog($invoiceLog);

                    try {
                        $transaction->process();
                    } catch (Exception $e) {
                        if ($invoiceLog) {
                            $invoiceLog->add($e);
                        }

                        $this->sendJsonResponseThenExit(['success' => false, 'message' => $e->getMessage()]);
                    }

                    if ($invoiceLog) {
                        $invoiceLog->setProcessed();
                    }


                    $json = json_decode(file_get_contents('php://input'));
                    $amount = $json->total_amount;
                    $reference = $json->reference;

                    $data = [
                        'success' => true,
                        'invoice_id' => $invoiceId,
                        'status' => 'completed',
                        'amount' => $amount,
                        'reference' => $reference,
                    ];
                } catch (Exception $e) {
                    $data = ['success' => false, 'message' => $e->getMessage()];
                }

                $transaction->setInvoiceLog($invoiceLog);

                $this->sendJsonResponseThenExit($data);
                break;

            default: return parent::directAction($request, $response, $invokeArgs);
        }
    }

    /**
     * Proses pembayaran.
     *
     * @param Invoice             $invoice
     * @param Am_Mvc_Request      $request
     * @param Am_Paysystem_Result $result
     *
     * @return void
     */
    public function _process(Invoice $invoice, Am_Mvc_Request $request, Am_Paysystem_Result $result)
    {
        $url = $this->getTripayApiUrl();
        $response = $this->sendCurlRequest($url, $invoice);
        $response = json_decode($response);

        $action = new Am_Paysystem_Action_Redirect($response->data->checkout_url);

        $result->setAction($action);
    }

    /**
     * Set plugin agar memberitahu bahwa pembayaran telah sukses dilakukan, jangan beri tahu
     * tentang pembayaran berikutnya, tetapi beri tahu jika proses rebill telah selesai
     * dan akses harus dihentikan.
     *
     * @return int
     */
    public function getRecurringType()
    {
        return self::REPORTS_EOT;
    }


    public function createTransaction(Am_Mvc_Request $request, Am_Mvc_Response $response, array $invokeArgs)
    {
        return new Am_Paysystem_Transaction_TripayBase_Ipn($this, $request, $response, $invokeArgs);
    }

    /**
     * Ambil list waktu kadaluwarsa invoice.
     *
     * @return array
     */
    protected function getDurations()
    {
        return [
            '1' => '1 '.___('Hari'),
            '2' => '2 '.___('Hari'),
            '3' => '3 '.___('Hari'),
            '4' => '4 '.___('Hari'),
            '5' => '5 '.___('Hari'),
            '6' => '6 '.___('Hari'),
            '7' => '7 '.___('Hari'),
        ];
    }

    /**
     * Kirim POST request ke server tripay.
     *
     * @param string  $url
     * @param Invoice $invoice
     *
     * @return string
     */
    protected function sendCurlRequest($url, Invoice $invoice)
    {
        $this->ensureRequiredDataExists();

        $url = $this->getTripayApiUrl();

        $apiKey = $this->getConfig('api_key');
        $privateKey = $this->getConfig('private_key');
        $merchantCode = $this->getConfig('merchant_code');
        $merchantRef = $invoice->invoice_id;
        $paymentMethod = $this->tripayPaymentMethod;
        $name = $invoice->getLineDescription();

        if ((float) $invoice->first_total <= 0) {
            $invoice->addAccessPeriod(new Am_Paysystem_Transaction_Free($this));
            $result->setAction(new Am_Paysystem_Action_Redirect($this->getReturnUrl()));
            return;
        }

        $price = (int) $invoice->first_total;
        $quantity = 1; // TODO: Perlukah diganti dinamis? atau biarkan tetap 1 saja?

        $period = (int) $this->getConfig('duration');
        $callbackUrl = $this->getPluginUrl('notifications');
        $returnUrl = $this->getReturnUrl();


        $data = [
            'method' => $paymentMethod,
            'merchant_ref' => $merchantRef,
            'amount' => $price,
            'customer_name' => $invoice->getFirstName().' '. $invoice->getLastName(),
            'customer_email' => $invoice->getEmail(),
            'customer_phone' => $invoice->getPhone(),
            'order_items' => [compact('name', 'price', 'quantity')],
            'callback_url' => $callbackUrl,
            'return_url' => $returnUrl,
            'expired_time' => (time() + ($period * 24 * 60 * 60)),
            'signature' => hash_hmac('sha256', $merchantCode.$merchantRef.$price, $privateKey)
        ];

        $curl = curl_init();

        curl_setopt_array($curl, [
            CURLOPT_FRESH_CONNECT => true,
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_HTTPHEADER => ['Authorization: Bearer '.$apiKey],
            CURLOPT_FAILONERROR => false,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($data)
        ]);

        $response = curl_exec($curl);
        $error = curl_error($curl);

        curl_close($curl);

        return empty($error) ? $response : $error;
    }

    /**
     * Kirim response JSON ke browser dan hentikan eksekusi.
     *
     * @param array $data
     *
     * @return void
     */
    protected function sendJsonResponseThenExit(array $data)
    {
        header('Content-Type: application/json');

        echo json_encode($data);

        exit;
    }

    /**
     * Pastikan data payment method sudah diisi.
     *
     * @return void
     */
    private function ensureRequiredDataExists()
    {
        if (is_null($this->tripayPaymentMethod) | is_null($this->tripayPaymentMethodName)) {
            $this->sendJsonResponseThenExit(['success' => false, 'message' => 'Please fill all required configuration data.']);
        }
    }
}

class Am_Paysystem_Transaction_TripayBase_Ipn extends Am_Paysystem_Transaction_Incoming
{
    /**
     * Validasi bahwa IPN benar - benar datang dari server tripay, bukan dari hacker.
     *
     * @return bool
     */
    public function validateSource()
    {
        $callbackSignature = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';

        $json = file_get_contents('php://input');
        $generatedSignature = hash_hmac('sha256', $json, $this->plugin->getConfig('private_key'));

        return static::signatureIsMatching($generatedSignature, $callbackSignature);
    }

    /**
     * Pastikan bahwa status pembayaran dari server tripay sudah 'PAID'.
     *
     * @return bool
     */
    public function validateStatus()
    {
        $event = $_SERVER['HTTP_X_CALLBACK_EVENT'];

        if ($event !== 'payment_status') {
            return false;
        }

        $data = json_decode(file_get_contents('php://input'));
        $status = ($data->status === 'PAID');

        return $status;
    }

    /**
     * Temukan public ID milik invoice saat ini.
     *
     * @return string|null
     */
    public function findInvoiceId()
    {
        $data = file_get_contents('php://input');
        $data = json_decode($data);

        $merchantRef = (int) $data->merchant_ref;
        $pluginId = $this->plugin->getId();

        $invoice = Am_Di::getInstance()->db->select(
            "SELECT * FROM ?_invoice WHERE status = '0' AND invoice_id = '{$merchantRef}' AND paysys_id = '{$pluginId}' LIMIT 1;"
        );

        if (! is_array($invoice) || empty($invoice) || count($invoice) > 1) {
            return null;
        }

        return $invoice[0]['public_id'];
    }

    /**
     * Ambil reference ID dari server tripay dan gunakan sebagai unique id di amember.
     *
     * @return string
     */
    public function getUniqId()
    {
        $data = json_decode(file_get_contents('php://input'));

        return $data->reference;
    }

    /**
     * Bandingkan setelan pembayaran invoice dengan data yang dikirim oleh server tripay,
     * Jika ada perbedaan, berarti url redirect telah diubah sebelum proses pembayaran dilakukan.
     *
     * @return bool
     */
    public function validateTerms()
    {
        $data = file_get_contents('php://input');
        $data = json_decode($data);

        // Total amount dari invoice tripay dikurangi fee customer
        $amount = $data->total_amount - $data->fee_customer;

        return ($amount == $this->invoice->first_total);
    }

    /**
     * Polyfill untuk fungsi hash_equals agar plugin tetap bacward-compatible sampai PHP 5.4.0.
     * Lihat: https://github.com/symfony/polyfill-php56/blob/ea19621731cbd973a6702cfedef3419768bf3372/Php56.php#L24-L52
     *
     * @param string $callbackSignature
     * @param string $generatedSignature
     *
     * @return bool
     */
    public static function signatureIsMatching($callbackSignature, $generatedSignature)
    {
        // Polyfill untuk fungsi hash_equals yang baru tersedia di PHP 5.6.0+
        // Kita butuh ini agar plugin tetap kompatibel dengan PHP 5.4.0
        if (! function_exists('hash_equals')) {
            if (! is_string($generatedSignature) || ! is_string($callbackSignature)) {
                return false;
            }

            $length1 = mb_strlen($callbackSignature, '8bit');
            $length2 = mb_strlen($generatedSignature, '8bit');

            if ($length1 !== $length2) {
                return false;
            }

            $result = 0;

            for ($i = 0; $i < $length1; ++$i) {
                $result |= ord($callbackSignature[$i]) ^ ord($generatedSignature[$i]);
            }

            return (0 === $result);
        }

        return hash_equals($callbackSignature, $generatedSignature);
    }
}
