<?php

namespace Samyoosephi\Gateway\Asanpardakht;

use DateTime;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Request;
use SoapClient;
use Samyoosephi\Gateway\PortAbstract;
use Samyoosephi\Gateway\PortInterface;

class Asanpardakht extends PortAbstract implements PortInterface
{
    /**
     * Address of main SOAP server
     *
     * @var string
     */

    Const TokenURL = 'v1/Token';
    Const TimeURL = 'v1/Time';
    Const TranResultURL = 'v1/TranResult';
    Const CardHashURL = 'v1/CardHash';
    Const SettlementURL = 'v1/Settlement';
    Const VerifyURL = 'v1/Verify';
    Const CancelURL = 'v1/Cancel';
    Const ReverseURL = 'v1/Reverse';
	Const URL = 'https://ipgrest.asanpardakht.ir';

    /**
     * {@inheritdoc}
     */
    public function set($amount)
    {
        $this->amount = $amount;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function ready()
    {
        $this->sendPayRequest();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function redirect()
    {
        return view('gateway::asan-pardakht-redirector')->with([
            'refId' => $this->refId
        ]);
    }

    /**
     * {@inheritdoc}
     */
    public function verify($transaction)
    {
        parent::verify($transaction);

        $result = $this->tranResult();
        $data = $result['content'];

        $this->refId = $data['refID'];
        $this->trackingCode = $data['rrn'];
        $this->cardNumber = $data['cardNumber'];
        $this->verifyAndSettlePayment($data['payGateTranID']);
        return $this;
    }

    /**
     * Sets callback url
     * @param $url
     */
    function setCallback($url)
    {
        $this->callbackUrl = $url;
        return $this;
    }

    /**
     * Gets callback url
     * @return string
     */
    function getCallback()
    {
        if (!$this->callbackUrl)
            $this->callbackUrl = $this->config->get('gateway.asanpardakht.callback-url');

        $url = $this->makeCallback($this->callbackUrl, ['invoice' => $this->transactionId()]);

        return $url;
    }

    public function time()
    {
        return $this->callAPI('GET', self::TimeURL);
    }

    public function token()
    {
        return $this->callAPI('POST', self::TokenURL, [
                'serviceTypeId' => 1,
                'merchantConfigurationId' => $this->config->get('gateway.asanpardakht.merchantConfigId'),
                'localInvoiceId' => $this->transactionId(),
                'amountInRials' => $this->amount,
                'localDate' => (string)(new DateTime('Asia/Tehran'))->format('Ymd His'),
                'callbackURL' => $this->getCallback(),
                'paymentId' => 0,
                'additionalData' => '',
        ]);
    }

    protected function sendPayRequest()
    {
        $this->newTransaction();
        $token = $this->token();
        $code = $token['code'];
        $ref = str_replace('"', '', $token['content']);

        if ($code == 200) {
            $this->refId = $ref;
            $this->transactionSetRefId();
        } else {
            $this->transactionFailed();
			$this->newLog($code, $ref);
            throw new AsanpardakhtException($code);
        }
    }


    public function tranResult()
    {
        $res = $this->callAPI('GET', self::TranResultURL.'?'.http_build_query([
                'merchantConfigurationId' => $this->config->get('gateway.asanpardakht.merchantConfigId'),
                'localInvoiceId' => $this->transactionId()
            ]));

        $code = $res['code'];
        if ($code != 200) {
            $this->transactionFailed();
            $this->newLog($code, AsanpardakhtException::getMessageByCode($code));
            throw new AsanpardakhtException($code);
        }

        return [
            'code' => $code,
            'content' => json_decode($res['content'], true),
        ];
    }

    public function verifyAndSettlePayment($payGateTranId)
    {
        // Verify
        $verify = $this->callAPI('POST', self::VerifyURL, [
            'merchantConfigurationId' => $this->config->get('gateway.asanpardakht.merchantConfigId'),
            'payGateTranId' => $payGateTranId
        ]);

        $code = $verify['code'];
        if ($code != 200) {
            $this->transactionFailed();
            $this->newLog($code, AsanpardakhtException::getMessageByCode($code));
            throw new AsanpardakhtException($code);
        }


        // Settlement
        $settle = $this->callAPI('POST',self::SettlementURL,[
            'merchantConfigurationId' => $this->config->get('gateway.asanpardakht.merchantConfigId'),
            'payGateTranId' => $payGateTranId
        ]);

        $code = $settle['code'];
        if ($code != 200) {
            $this->transactionFailed();
            $this->newLog($code, AsanpardakhtException::getMessageByCode($code));
            throw new AsanpardakhtException($code);
        }

        // Succeed
        $this->transactionSucceed();
        return true;
    }


    protected function callAPI($method, $url, $data = false)
    {
        $username = $this->config->get('gateway.asanpardakht.username');
        $password = $this->config->get('gateway.asanpardakht.password');
        $curl = curl_init();
        $url = self::URL.'/'.$url;

        switch ($method)
        {
            case 'POST':
                curl_setopt($curl, CURLOPT_POST, 1);
                if ($data)
                    curl_setopt($curl, CURLOPT_POSTFIELDS, json_encode($data));
                break;

            default:
                if ($data)
                    $url = sprintf("%s?%s", $url, http_build_query($data));
        }

        curl_setopt($curl, CURLOPT_HTTPHEADER, [
            'Accept: application/json',
            'Usr: '.$username,
            'Pwd: '.$password,
            'Content-Type: application/json',
        ]);

        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, true);

        $result = curl_exec($curl);
		if (curl_errno($curl)) {
            //Log::alert('Call Error'. curl_error($curl));
			return [
                'content' => curl_error($curl),
                'code' => curl_errno($curl)
            ];
        }

		$httpcode = curl_getinfo($curl);
        curl_close($curl);

        return [
            'content' => $result,
            'code' => $httpcode['http_code']
        ];
    }
}
