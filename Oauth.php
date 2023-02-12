<?php

class OAuthYQ
{

  private $consumerKey, $consumerSecret, $baseUrl, $signature;

  public $nonce, $signatureMethod, $timestamp, $version, $callbackUrl;

  public function __construct($consumerKey, $consumerSecret, $baseUrl)
  {
    $this->consumerKey = $consumerKey;
    $this->consumerSecret = $consumerSecret;
    $this->baseUrl = rtrim($baseUrl, '/');

    $this->nonce = crc32(time());
    $this->signatureMethod = 'HMAC-SHA1';
    $this->timestamp = time();
    $this->version = '1.0';
  }
  
  	/**
	* Method for creating a base string from an array and base URI.
	* @param string $baseURI the URI of the request to OSCAR EMR
	* @param array $params the OAuth associative array
	* @return string the encoded base string
	**/
	function buildBaseString($baseUrl, $params, $method){
		
		$r = array(); //temporary array
		ksort($params); //sort params alphabetically by keys
		
		foreach($params as $key=>$value){
        $r[] = "$key=" . rawurlencode($value); //create key=value strings
		}//end foreach                

    return $method . '&' . rawurlencode( $baseUrl) . '&' . rawurlencode(implode('&', $r)); //return complete base string
	
	}//end buildBaseString()
	
	/**
	Method for creating the composite key.
	* @param string $consumerSecret the consumer secret authorized by OSCAR EMR
	* @param string $requestToken the request token from OSCAR EMR
	* @return string the composite key.
	**/	
	public function getCompositeKey($consumerSecret, $requestToken){
    
	return rawurlencode($consumerSecret) . '&' . rawurlencode($requestToken);
	
	}//end

    /**
	* Method for building the OAuth header.
	* @param array $oauth the oauth array.
	* @return string the authorization header.	
	**/
    public function buildAuthorizationHeader($oauth){
		
	$authHeaderPairs =  array(); //temporary key=value array
	foreach ($oauth as $key => $value) {
		if (strpos($key, 'oauth_') !== 0) continue;
		$authHeaderPairs[] = rawurlencode($key) . '="' . rawurlencode($value) . '"';//encode key=value string
 		}
 	$r = 'Authorization: OAuth ' . implode(", ", $authHeaderPairs);	//header prefix and reassemble

    return $r; //return full authorization header
	}//end buildAuthorizationHeader()
	
	/**
	* OAuth workflow Method to get token
	* @param authorize OSCAR EMR
	**/
  public function initiate($callbackUrl, $path)
  {
    $this->callbackUrl = $callbackUrl;

    $url = $this->baseUrl . $path;

    $params = [
      'oauth_callback' => $this->callbackUrl,
      'oauth_consumer_key' => $this->consumerKey,
      'oauth_nonce' => $this->nonce,
      'oauth_signature_method' => $this->signatureMethod,
      'oauth_timestamp' => $this->timestamp,
      'oauth_version' => $this->version,
    ];
  	
	$baseString = $this->buildBaseString($url, $params, 'POST');		
	$compositeKey = $this->getCompositeKey($this->consumerSecret , null); 
		
	$oauth_signature = base64_encode(hash_hmac('sha1', $baseString, $compositeKey, true)); //sign the base string
	$params['oauth_signature'] = $oauth_signature; //add the signature to our oauth array		
     
    try {
      $response = $this->request($url, $params, 'Expect:' , 'POST');//make the call     
	  parse_str($response, $data);	  
      return $data;
    } catch (\Exception $e) {
      print_r($e->getMessage());
    }
  }  
  
    /**
	* OAuth workflow Method to get access token
	* @param callback fro, OSCAR EMR
	**/
  public function getToken($path, $token, $verifier, $secret)
  {

    $url = $this->baseUrl . $path;

    $params = [
      'oauth_consumer_key' => $this->consumerKey,
      'oauth_nonce' => $this->nonce,
      'oauth_signature_method' => $this->signatureMethod,
      'oauth_timestamp' => $this->timestamp,
      'oauth_version' => $this->version,
      'oauth_token' => $token,
      'oauth_verifier' => $verifier,
    ];
	
	$baseString = $this->buildBaseString($url, $params, 'POST');		
	$compositeKey = $this->getCompositeKey($this->consumerSecret , $secret); 
		
	$oauth_signature = base64_encode(hash_hmac('sha1', $baseString, $compositeKey, true)); //sign the base string
	$params['oauth_signature'] = $oauth_signature; //add the signature to our oauth array
	
    try {
      $response = $this->request($url, $params, 'Expect:' , 'POST');//make the call
      parse_str($response, $data);
      return $data;
    } catch (\Exception $e) {
      print_r($e->getMessage());
    }
  }
  
  /**
  * Method for REST Calls to OSCAR EMR.
  * 
  **/
  public function call($path, $query = [], $headerline, $method = 'GET', $postData = null)
  {

    $url = $this->baseUrl . $path;
	$oauth_token = get_option('oscar_emr_plugin_oauth_token');
    $oauth_token_secret = get_option('oscar_emr_plugin_oauth_token_secret');	
		
    foreach ($query as $key => $value) {
      $query[$key] = rawurlencode($value);
    }
	
    $params = [
      'oauth_consumer_key' => $this->consumerKey,
      'oauth_nonce' => $this->nonce,
      'oauth_signature_method' => $this->signatureMethod,
      'oauth_timestamp' => $this->timestamp,
      'oauth_version' => $this->version,
      'oauth_token' => $oauth_token
    ];
	
	$params = array_merge($params, $query);
	ksort($params);	
	
	// $baseString = $this->buildBaseString($url, $params, $method);	
	$baseString = $method."&".rawurlencode($url).'&'.rawurlencode(http_build_query($params));	
	$compositeKey = $this->getCompositeKey($this->consumerSecret , $oauth_token_secret); 
	
	$oauth_signature = base64_encode(hash_hmac('sha1', $baseString, $compositeKey, true)); //sign the base string
	$params['oauth_signature'] = $oauth_signature; //add the signature to our oauth array
	ksort($params);	
	
    try {
	  $response = $this->request($url . '?' . http_build_query($query) , $params  , $headerline, $method, isset($postData) ? json_encode($postData) : null);//make the call
      return json_decode($response);
    } catch (\Exception $e) {
      print_r($e->getMessage());
    }
	
  }
    /**
	* Method for sending a request to OSCAR EMR.
	* @param array $oauth the oauth array
	* @param string $baseURI the request URI
	* @return string the response from OSCAR EMR
	**/
  private function request($url, $params, $headerline, $method = 'GET', $post = NULL)
  {
	$headers = array( $this->buildAuthorizationHeader($params), $headerline); //create header array and add type	

    write_log($url);
    write_log($post);
	
    $ch = curl_init();
	curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
	curl_setopt($ch, CURLOPT_HEADER, false);
    curl_setopt($ch, CURLOPT_URL, $url); 
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    
    if (isset($post)) {
      curl_setopt($ch, CURLOPT_POST, true);
      curl_setopt($ch, CURLOPT_POSTFIELDS, $post);
    }

    if ($method) {
      curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
    }
        
	curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2TLS);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, 1);
    curl_setopt($ch, CURLOPT_SSLVERSION, CURL_SSLVERSION_TLSv1_3);
	curl_setopt($ch, CURLOPT_MAXREDIRS, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 0);
	curl_setopt($ch, CURLOPT_COOKIESESSION, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cookies' . DIRECTORY_SEPARATOR . 'cookies.txt');
    curl_setopt($ch, CURLOPT_COOKIEFILE, dirname(__FILE__) . DIRECTORY_SEPARATOR . 'cookies' . DIRECTORY_SEPARATOR . 'cookies.txt');

    $result = curl_exec($ch);//make the call
    $info = curl_getinfo($ch); //Gets info from handler
	
	write_log($info);
	
    if ($info['http_code'] !== 200) {
      curl_close($ch);
      throw new Exception($info['http_code']);
    }
	
    if($result === false) {
      $error = curl_error($ch);
      curl_close($ch);
      throw new Exception($error);
    }

    curl_close($ch); //hang up
    return $result;
  }//end request()	
}
