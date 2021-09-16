<?php

class nc_payment_system_roskassa extends nc_payment_system
{
    protected $automatic = TRUE;

    const ERROR_MERCHANT_URL_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ROSKASSA_ERROR_MERCHANT_URL_IS_NOT_VALID;
    const ERROR_MERCHANT_ID_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ROSKASSA_ERROR_MERCHANT_ID_IS_NOT_VALID;
    const ERROR_SECRET_KEY_IS_NOT_VALID = NETCAT_MODULE_PAYMENT_ROSKASSA_ERROR_SECRET_KEY_IS_NOT_VALID;
    const MSG_NOT_VALID_IP = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_NOT_VALID_IP;
    const MSG_VALID_IP = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_VALID_IP;
    const MSG_THIS_IP = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_THIS_IP;
    const MSG_HASHES_NOT_EQUAL = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_HASHES_NOT_EQUAL;
    const MSG_WRONG_AMOUNT = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_WRONG_AMOUNT;
    const MSG_WRONG_CURRENCY = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_WRONG_CURRENCY;
    const MSG_WRONG_ORDER_PAYEED = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_WRONG_ORDER_PAYEED;
    const MSG_STATUS_FAIL = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_STATUS_FAIL;
    const MSG_ERR_REASONS = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_ERR_REASONS;
    const MSG_SUBJECT = NETCAT_MODULE_PAYMENT_ROSKASSA_MSG_SUBJECT;

    protected $accepted_currencies = array('RUB', 'RUR', 'USD', 'EUR');

    protected $settings = array(
        'SHOP_ID' => null,
        'SECRET_KEY' => null,
        'LOG_FILE' => '/roskassa.log',
        'ADMIN_EMAIL' => null,
        'TEST'=>0,
    );

    protected $request_parameters = array();

    protected $callback_response = array(
        'id' => null,
        'shop_id' => null,
        'date_created' => null,
        'date_payed' => null,
        'status' => null,
        'payment_system' => null,
        'currency_id' => null,
        'currency' => null,
        'amount' => null,
        'fee' => null,
        'order_id' => null,
        'public_key' => null,
        'test' => null,
        'sign' => null,
    );
    public function execute_payment_request(nc_payment_invoice $invoice)
    {
        $currency=$invoice->get_currency();
        if($currency=='RUR'){
            $currency='RUB';
        }
        $params=array(
            'amount'=>number_format($invoice->get_amount("%0.2F"), 2, '.', ''),
            'order_id'=>$invoice->get_id(),
            'shop_id'=>$this->get_setting('SHOP_ID'),
            'currency'=>$currency,);
        if($this->get_setting('TEST')!==null){
            $params['test']=1;
        }
        ksort($params);
        $str = http_build_query($params);
        $secret_key=$this->get_setting("SECRET_KEY");
        $sign=md5($str . $secret_key);
        $params['sign']=$sign;
        $link = "https://pay.roskassa.net/?".http_build_query($params);

        $form="<html>
    <head>
        
        <meta http-equiv='refresh' content='0; url=".$link."'>
        <script type='text/javascript'>
            window.location.href = '".$link."'
        </script>
        <title>Страница переадресации</title>
    </head>
    <body>
        
        Если перенаправление не произошло, нажмите <a href='".$link."'>Оплатить</a>.
    </body>
</html>";

        ob_end_clean();

        echo $form;
        exit;
    }

    public function on_response(nc_payment_invoice $invoice = null)
    {
    }

    public function validate_payment_request_parameters()
    {

        $shop_id = $this->get_setting('SHOP_ID');
        $secret_key = $this->get_setting('SECRET_KEY');


        if (empty($shop_id))
        {
            $this->add_error(nc_payment_system_roskassa::ERROR_MERCHANT_ID_IS_NOT_VALID);
        }

        if (empty($secret_key))
        {
            $this->add_error(nc_payment_system_roskassa::ERROR_SECRET_KEY_IS_NOT_VALID);
        }
    }

    public function validate_payment_callback_response(nc_payment_invoice $invoice = null)
    {
        $m_operation_id = $this->get_response_value('id');
        $m_sign = $this->get_response_value('sign');

        if (isset($m_operation_id) && isset($m_sign))
        {
            $err = false;
            $message = '';

            // запись логов

            $log_text =
                "--------------------------------------------------------\n" .
                "id    	" . $this->get_response_value("shop_id") . "\n" .
                "amount				" . $this->get_response_value("amount") . "\n" .
                "roskassa operation id " . $this->get_response_value("id") . "\n" .
                "order id	" . $this->get_response_value("order_id") . "\n" .
                "sign				" . $this->get_response_value("sign") . "\n\n";

            $log_file = $this->get_setting('LOG_FILE');

            if (!empty($log_file))
            {
                file_put_contents($_SERVER['DOCUMENT_ROOT'] . $log_file, $log_text, FILE_APPEND);
            }

            // проверка цифровой подписи и ip
            $params=array(
                'amount'=>$this->get_response_value('amount'),
                'order_id'=>$this->get_response_value('order_id'),
                'shop_id'=>$this->get_setting('SHOP_ID'),
                'currency'=>'RUB',);
            if($this->get_setting('TEST')!==0){
                $params['test']=1;
            }
            ksort($params);
            $str = http_build_query($params);
            $secret_key=$this->get_setting("SECRET_KEY");
            $sign=md5($str . $secret_key);


            if (!$err)
            {
                $order_amount = number_format($invoice->get_amount(), 2, '.', '');

                // проверка суммы

                if ($this->get_response_value('amount') != $order_amount)
                {
                    $message .= nc_payment_system_roskassa::MSG_WRONG_AMOUNT . "\n";
                    $err = true;
                }

                // проверка статуса

                if (!$err)
                {
                    if ($this->get_response_value('sign') != $sign) {

                        if ($invoice->get('status') != 6) {
                            $invoice->set('status', nc_payment_invoice::STATUS_SUCCESS);
                            $invoice->save();
                            $this->on_payment_success($invoice);
                        }

                    }else {

                        $invoice->set('status', nc_payment_invoice::STATUS_CALLBACK_ERROR);
                        $invoice->save();
                        $message .= nc_payment_system_roskassa::MSG_HASHES_NOT_EQUAL . "\n";
                        $err = true;
                    }
                }
            }

            if ($err)
            {
                $to = $this->get_setting('ADMIN_EMAIL');

                if (!empty($to))
                {
                    $message = nc_payment_system_roskassa::MSG_ERR_REASONS . "\n\n" . $message . "\n" . $log_text;
                    $headers = "From: no-reply@" . $_SERVER['HTTP_HOST'] . "\r\n" .
                        "Content-type: text/plain; charset=utf-8 \r\n";
                    mail($to, nc_payment_system_roskassa::MSG_SUBJECT, $message, $headers);
                }

                echo ($this->get_response_value('order_id') . ' |error| ' . $message);
            }
            else
            {
                echo 'YES';
            }
        }
    }

    public function load_invoice_on_callback()
    {
        return $this->load_invoice($this->get_response_value('order_id'));
    }
}