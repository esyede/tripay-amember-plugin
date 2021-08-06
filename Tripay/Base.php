<?php

/**
 * Plugin integrasi TriPay Payment Gateway dengan aMember.
 * Tested on aMember Pro v6.2.10
 *
 * Copyright (c) 2021 PT Trijaya Digital Group.
 *
 * @link https://tripay.co.id
 *
 */

class Am_Paysystem_TripayBase extends Am_Paysystem_Abstract
{
    const PLUGIN_REVISION = '1.0.2';

    const URL_DOCS = 'https://tripay.co.id/developer';
    const URL_BASE = 'https://tripay.co.id';

    const URL_API_SANDBOX = 'https://tripay.co.id/api-sandbox/transaction/create';
    const URL_API_PRODUCTION = 'https://tripay.co.id/api/transaction/create';

    protected $tripayPaymentMethod = null;
    protected $tripayPaymentMethodName = null;

    /**
     * Konstruktor
     *
     * @param Am_Di $dependency
     * @param array $config
     */
    public function __construct(Am_Di $dependency, array $config)
    {
        $this->defaultTitle = ___('TriPay - '.$this->tripayPaymentMethodName);
        $this->defaultDescription = ___('Bayar Melalui '.$this->tripayPaymentMethodName.' - by TriPay');

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
        $help = 'Untuk mode <b>Sandbox</b> lihat <a href="'.self::URL_BASE.'/simulator/merchant" target="_blank">di sini</a><br>
            Untuk mode <b>Production</b> lihat <a href="'.self::URL_BASE.'/member/merchant" target="_blank">di sini</a>';

        $form->addText('version', ['size' => 100, 'value' => self::PLUGIN_REVISION, 'readonly' => 'true'])
            ->setLabel('Versi Plugin');

        $form->addHtml()
            ->setHtml(
                '<p>Dibawah ini anda dapat menyetel kredensial untuk koneksi ke server TriPay.<br>'.
                'Panduan integrasi dapat anda baca melalui '.
                '<a href="'.self::URL_DOCS.'" target="_blank" class="link"><b>Halaman Developer</b></a></p>'.
                '<p>Channel pembayaran pada plugin ini hanya mendukung <b>Closed Payment</b>.</p>'
            );

        $form->addCheckbox('sandbox_mode')
            ->setLabel('Gunakan API Sandbox');
        $form->addHtml()->setHtml('<b>Sandbox</b> digunakan untuk masa pengembangan');

        $form->addText('merchant_code', ['size' => 100, 'placeholder' => ___('Kode merchant anda..')])
            ->setLabel('Kode Merchant')
            ->addRule('required');
        $form->addHtml()->setHtml($help);

        $form->addText('callback_url', [
            'size' => 100,
            'value' => 'Kosongkan',
            'readonly' => 'true',
            'style' =>'background-color:#eeeeee;color:black;'
        ])->setLabel('URL Callback');
        $form->addHtml()->setHtml(
            'Kosongkan URL Callback di dashboard TriPay anda.<br>'.
            'Untuk panduan ketika testing. Silahkan merujuk ke halaman '.'
            <a href="'.self::URL_BASE.'/docs/3/cara-install-setting-plugin-untuk-amember" target="_blank">Dokumentasi</a>'
        );

        $form->addText('api_key', ['size' => 100, 'placeholder' => ___('API key anda..')])
            ->setLabel('API Key')
            ->addRule('required');
        $form->addHtml()->setHtml($help);

        $form->addText('private_key', ['size' => 100, 'placeholder' => ___('Private key anda..')])
            ->setLabel('Private Key')
            ->addRule('required');
        $form->addHtml()->setHtml($help);

        $form->addSelect('duration')
            ->setLabel('Durasi')
            ->loadOptions(TripayHelper::getDurations())
            ->addRule('required');
        $form->addHtml() ->setHtml('Masa aktif/berlaku kode bayar');
    }

    /**
     * Tampilkan panduan pada footer halaman setup amember.
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
                $tripaySignatue = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';

                $json = TripayHelper::getResponse(true);
                $localSignature = hash_hmac('sha256', $json, $this->getConfig('private_key'));

                if (! TripayHelper::signatureIsMatching($localSignature, $tripaySignatue)) {
                    throw new Am_Exception_InputError('[TriPay] ERROR: Signature mismatch. - DATA: '.$json);
                }

                $data = [];

                try {
                    $transaction = $this->createTransaction($request, $response, $invokeArgs);

                    if (! $transaction) {
                        throw new Am_Exception_InputError('Request not handled - createTransaction() returned null');
                    }

                    $invoiceId = $transaction->findInvoiceId();

                    if (is_null($invoiceId)) {
                        throw new Am_Exception_InputError('[TriPay] ERROR: Invoice not found. - DATA: Invoice ID #'.$invoiceId);
                    }

                    $transaction->setInvoiceLog($invoiceLog);

                    try {
                        $transaction->process();
                    } catch (Exception $e) {
                        if ($invoiceLog) {
                            $invoiceLog->add($e);
                        }

                        throw new Am_Exception_InputError('[TriPay] ERROR: '.$e->getMessage().' - DATA: '.json_encode($e));
                    }

                    if ($invoiceLog) {
                        $invoiceLog->setProcessed();
                    }


                    $data = TripayHelper::getResponse();

                    $amount = $data->total_amount;
                    $reference = $data->reference;

                    $data = [
                        'success' => true,
                        'invoice_id' => $invoiceId,
                        'status' => 'completed',
                        'amount' => $amount,
                        'reference' => $reference,
                    ];
                } catch (Exception $e) {
                    $data['success'] = false;
                    $data['status'] = 'error';
                    throw new Am_Exception_InputError('[TriPay] ERROR: '.$e->getMessage().' - DATA: '.json_encode($data));
                }

                $transaction->setInvoiceLog($invoiceLog);
                break;

            default:
                return parent::directAction($request, $response, $invokeArgs);
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

        return empty($error) ? json_decode($response) : $error;
    }

    /**
     * Pastikan data payment method sudah diisi.
     *
     * @return void
     */
    private function ensureRequiredDataExists()
    {
        if (is_null($this->tripayPaymentMethod) || is_null($this->tripayPaymentMethodName)) {
            throw new Am_Exception_InputError('[TriPay] ERROR: Please fill all required configuration data.');
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
        $tripaySignatue = isset($_SERVER['HTTP_X_CALLBACK_SIGNATURE']) ? $_SERVER['HTTP_X_CALLBACK_SIGNATURE'] : '';

        $json = TripayHelper::getResponse(true);
        $localSignature = hash_hmac('sha256', $json, $this->plugin->getConfig('private_key'));

        return TripayHelper::signatureIsMatching($localSignature, $tripaySignatue);
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

        $data = TripayHelper::getResponse();
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
        $data = TripayHelper::getResponse();

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
        $data = TripayHelper::getResponse();

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
        $data = TripayHelper::getResponse();
        $amount = $data->total_amount - $data->fee_customer;

        return ((int) $amount === (int) $this->invoice->first_total);
    }
}


class TripayHelper
{
    /**
     * Ambil response dari server tripay.
     *
     * @param bool $asRawString
     *
     * @return \stdClass|string
     */
    public static function getResponse($asRawString = false)
    {
        $data = file_get_contents('php://input');

        if (! $asRawString) {
            $data = json_decode($data);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new Am_Exception_InputError('[TriPay] ERROR: Invalid JSON response recieved. - DATA: '.$data);
            }
        }

        return $data;
    }

    /**
     * Polyfill untuk fungsi hash_equals agar plugin tetap bacward-compatible sampai PHP 5.4.0.
     * Lihat: https://github.com/symfony/polyfill-php56/blob/ea19621731cbd973a6702cfedef3419768bf3372/Php56.php#L24-L52
     *
     * @param string $tripaySignatue
     * @param string $localSignature
     *
     * @return bool
     */
    public static function signatureIsMatching($tripaySignatue, $localSignature)
    {
        // Polyfill untuk fungsi hash_equals yang baru tersedia di PHP 5.6.0+
        // Kita butuh ini agar plugin tetap kompatibel dengan PHP 5.4.0
        if (! function_exists('hash_equals')) {
            if (! is_string($localSignature) || ! is_string($tripaySignatue)) {
                return false;
            }

            $length1 = mb_strlen($tripaySignatue, '8bit');
            $length2 = mb_strlen($localSignature, '8bit');

            if ($length1 !== $length2) {
                return false;
            }

            $result = 0;

            for ($i = 0; $i < $length1; ++$i) {
                $result |= ord($tripaySignatue[$i]) ^ ord($localSignature[$i]);
            }

            return (0 === $result);
        }

        return hash_equals($tripaySignatue, $localSignature);
    }



    /**
     * Ambil list waktu kadaluwarsa invoice.
     *
     * @return array
     */
    public static function getDurations()
    {
        return [
            '1' => '1 Hari',
            '2' => '2 Hari',
            '3' => '3 Hari',
            '4' => '4 Hari',
            '5' => '5 Hari',
            '6' => '6 Hari',
            '7' => '7 Hari',
        ];
    }
}
