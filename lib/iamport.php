<?php

class IamportAuthException extends Exception {

	protected $response;

	public function __construct($response) {
		$this->response = $response;

		parent::__construct($response->message, $response->code);
	}
}

class IamportRequestException extends Exception {

	protected $response;

	public function __construct($response) {
		$this->response = $response;

		parent::__construct($response->message, $response->code);
	}

}

class IamportPayment {

	protected $response;
	protected $custom_data;

	public function __construct($response) {
		$this->response = $response;

        $this->custom_data = json_decode($response->custom_data);
	}

	public function __get($name) {
		if (isset($this->response->{$name})) {
			return $this->response->{$name};
		}
	}

	public function getCustomData($name) {
		return $this->custom_data->{$name};
	}

}

class Iamport {

	const GET_TOKEN_URL = 'https://api.iamport.kr/users/getToken';
	const GET_PAYMENT_URL = 'https://api.iamport.kr/payments/';
	const FIND_PAYMENT_URL = 'https://api.iamport.kr/payments/find/';
	const TOKEN_HEADER = 'X-ImpTokenHeader';

	private $imp_key = null;
	private $imp_secret = null;

	public function __construct($imp_key, $imp_secret) {
		$this->imp_key = $imp_key;
		$this->imp_secret = $imp_secret;
	}

	public function findByImpUID($imp_uid) {
		try {
			$response = $this->getResponse(self::GET_PAYMENT_URL.$imp_uid);
            
            return new IamportPayment($response);
		} catch (IamportAuthException $e) {
			
		} catch (Exception $e) {
			
		}
	}

	public function findByMerchantUID($merchant_uid) {
        try {
        	$response = $this->getResponse(self::FIND_PAYMENT_URL.$merchant_uid);
            
            return new IamportPayment($response);
        } catch(IamportAuthException $e) {
        	return false;
        } catch(IamportRequestException $e) {
        	return false;
        } catch(Exception $e) {
        	return false;
        }
	}

	private function getResponse($request_url, $request_data=null) {
		$access_token = $this->getAccessCode();

		$ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $request_url);
        curl_setopt($ch, CURLOPT_POST, false);
        curl_setopt($ch, CURLOPT_HTTPHEADER, array(self::TOKEN_HEADER.': '.$access_token));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute get
        $body = curl_exec($ch);
        $error_code = curl_errno($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $r = json_decode(trim($body));
        curl_close($ch);

        if ( $error_code > 0 )	throw new Exception("Request Error(HTTP STATUS : ".$status_code.")", $error_code);
        if ( $r->code !== 0 )	throw new IamportRequestException($r);

        return $r->response;
	}

	private function getAccessCode() {
		$ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, self::GET_TOKEN_URL);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array(
            'imp_key' => $this->imp_key,
            'imp_secret' => $this->imp_secret
        )));
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

        //execute post
        $body = curl_exec($ch);
        $error_code = curl_errno($ch);
        $status_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $r = json_decode(trim($body));
        curl_close($ch);

        if ( $error_code > 0 )	throw new Exception("AccessCode Error(HTTP STATUS : ".$status_code.")", $error_code);
        if ( $r->code !== 0 )	throw new IamportAuthException($r);

        return $r->response->access_token;
	}
}