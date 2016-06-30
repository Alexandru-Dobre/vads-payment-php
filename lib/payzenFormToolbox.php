<?php

/*
 * Utility class easing PayZen form payments
 *
 * @version 0.6
 *
 */


class payzenFormToolbox {
  /**************** CLASS CONSTANTS **************/
  // The toolbox handles 4 log levels
  const NO_LOG = 0; // Default log level - No logs
  const ERROR = 1; // Narrower log level - Only errors
  const WARNING = 2; // Intemediate log level - Warning and errors
  const NOTICE = 3; // Wider log level - All logs


  /**************** CLASS PROPERTIES **************/

  /**
   * @var array
   * url Value according to your country :
   *  https://secure.payzen.eu/vads-payment/
   *  https://de.payzen.eu/vads-payment/
   *  https://secure.payzen.com.br/vads-payment/
   *  https://secure.payzen.cl/vads-payment/
   *  https://secure.payzen.co.in/vads-payment/
   *
   *  FOR DEMO ONLY:
   *  https://demo.payzen.eu/vads-payment/
   */
  public $platForm = [
    'url' => 'https://demo.payzen.eu/vads-payment/'
    // The URL of the PayZen plat-form
  ];

  // Container for PayZen user's account informations
  public $account;

  // Container for shop user's account informations
  public $shopPlatForm = [];

  // Callback method used by logging mechanism
  public $logMethod;

  // Toolbox log level. The log entries with greater level will be ignored
  public $logLevel;


  /**************** CLASS METHODS - PUBLIC **************/

  /**
   * payzenFormToolbox constructor.
   * stores the PayZen user's account informations
   *
   * @param $siteId string, the account site id as provided by Payzen
   * @param $certTest string, certificate, test-version, as provided by PayZen
   * @param $certProd string, certificate, production-version, as provided by PayZen
   * @param $ctxMode string ("TEST" or "PRODUCTION"), the PayZen mode to operate
   */
  public function __construct($siteId, $certTest, $certProd, $ctxMode) {

    if (empty($siteId) || $siteId == '[***CHANGE-ME***]') {
      echo _('please fill your site ID in').' lib/payzenBootstrap.php <br />';
      echo _('find some help on payzen.io');
      exit;
    }
    elseif ($ctxMode == 'TEST' && (empty($certTest) || $certTest == '[***CHANGE-ME***]')) {
      echo _('please fill your certificate (TEST-version) in').' lib/payzenBootstrap.php <br />';
      echo _('find some help on payzen.io');
      exit;
    }
    elseif ($ctxMode == 'PRODUCTION' && (empty($certProd) || $certProd == '[***CHANGE-ME***]')) {
      echo _('please fill your certificate (PRODUCTION) in').'lib/payzenBootstrap.php <br />';
      echo _('find some help on payzen.io');
      exit;
    }

    $this->account = [
      'vadsSiteId' => $siteId,
      'cert' => [
        'TEST' => $certTest,
        'PRODUCTION' => $certProd
      ],
      'ctxMode' => $ctxMode
    ];

    $this->logLevel = self::NO_LOG; // No logging by default

    // self::defaultLog is the default logging method
    $this->logMethod = function ($level, $message, $data = NULL) {
      $this->defaultLog($level, $message, $data);
    };
  }

  /**
   * @param $ipnUrl string, the URL PayZen will notify the payments to
   */
  public function setIpnUrl($ipnUrl) {
    $this->shopPlatForm['ipnUrl'] = $ipnUrl;
  }

  /**
   * @param $returnUrl string, the URL PayZen will use to send the customer back after payment
   */
  public function setReturnUrl($returnUrl) {
    $this->shopPlatForm['returnUrl'] = $returnUrl;
  }


  /**
   * Utility function, returns all the mandatory data needed by a PayZen form payment
   * as an array
   *
   * @param $siteId string, the user site id
   * @param $transId string, the transaction id from user site
   * @param $amount string, the amount of the payment
   * @param $currency string, the code of the currency to use
   *
   * @return array, the data to use in the fields of HTML payment form
   */
  public function getFormFields($siteId, $transId, $amount, $currency) {
    $form_data = [
      "vads_site_id" => $siteId,
      "vads_ctx_mode" => $this->account['ctxMode'],
      "vads_trans_id" => $transId,
      "vads_trans_date" => gmdate('YmdHis'),
      "vads_amount" => $amount,
      "vads_currency" => $currency,
      "vads_action_mode" => "INTERACTIVE",
      "vads_page_action" => "PAYMENT",
      "vads_version" => "V2",
      "vads_payment_config" => "SINGLE",
      "vads_capture_delay" => "0",
      "vads_validation_mode" => "0",
      "vads_return_mode"    => 'POST'
    ];

    if (isset($this->shopPlatForm['ipnUrl'])) {
      $form_data['vads_url_check'] = $this->shopPlatForm['ipnUrl'];
    }

    if (isset($this->shopPlatForm['returnUrl'])) {
      $form_data['vads_url_return'] = $this->shopPlatForm['returnUrl'];
    }

    $form_data['signature'] = $this->sign($form_data);

    return $form_data;
  }


  /**
   * Main fonction, checks the authenticity of the data received
   * during IPN request from PayZen plat-form
   *
   * @param $data array, the data received from PayZen, usually the _POST PHP super-global
   * @throws Exception if the authenticity data can't be established
   */
  public function checkIpnRequest($data) {
    $this->logNotice('IPN request received with data: ' . json_encode($data));
    $vads_data = $this->filterVadsData($data);
    $signature_check = $this->sign($vads_data);
    if (@$data['signature'] != $signature_check) {
      throw new Exception('Signature mismatch');
    }
    $this->logNotice('IPN request authenticated: signature is correct');
  }

  /**
   * Utility function, filters out the useless fields
   *  for PayZen signature
   *
   * @param $data array data received from PayZen
   *         (typically $_POST)
   *
   * @return array, the data filtered
   */
  public function filterVadsData($data) {
    $res = [];
    if ($data) {
      foreach ($data as $field => $value) {
        substr($field, 0, 5) == 'vads_' && $res[$field] = $value;
      }
    }
    return $res;
  }

  /**
   * Utility function, builds and returns the signature string of the data
   *  being transmitted to the PayZen plat-form
   *
   * @param $vads_form array, array of data being signed
   *
   * @return String, the signature
   */
  public function sign(Array $vads_form) {
    // Choice between TEST and PRODUCTION certificates
    $cert = $this->account['cert'][$this->account['ctxMode']];
    ksort($vads_form);           // VADS values sorted by name, ascending order
    return sha1(                 // SHA1 encryption of ...
      implode('+', $vads_form)    // ... VADS array values joined with '+' ...
      . "+$cert"                  // ... concatenated with '+' and the certificate.
    );
  }


  /**
   * Main function, returns an array containing all mandatory
   * information needed to build an HTML form for an createPayment
   * request
   *
   * @param $transId string, an external transaction id
   * @param $amount string, the amount of the payment
   * @param $currency string, the code of the currency to use
   *
   * @return array, the form data, as follow:
   *
   *  [form] => Array
   *      (
   *          [action] => https://secure.payzen.eu/vads-payment/
   *          [method] => POST
   *          [accept-charset] => UTF-8
   *          [enctype] => multipart/form-data
   *      )
   *  [fields] => Array
   *      (
   *          [vads_site_id] => 12345678
   *          [vads_ctx_mode] => TEST || PRODUCTION
   *          [vads_trans_id] => 612435
   *          [vads_trans_date] => 20151116183355
   *          [vads_amount] => 4300
   *          [vads_currency] => 978
   *          [vads_action_mode] => INTERACTIVE
   *          [vads_page_action] => PAYMENT
   *          [vads_version] => V2
   *          [vads_payment_config] => SINGLE
   *          [vads_capture_delay] => 0
   *          [vads_validation_mode] => 0
   *          [signature] => 89d95486ac27addea254cf478fabf1d4a968266a
   *      )
   *
   */
  public function getFormData($transId, $amount, $currency) {
    $this->logNotice("Building form data for: transId $transId, amount $amount and currency $currency.");
    return [
      "form" => [
        "action" => $this->platForm['url'],
        "method" => "POST",
        "accept-charset" => "UTF-8",
        "enctype" => "multipart/form-data"
      ],
      "fields" => $this->getFormFields($this->account['vadsSiteId'], $transId, $amount, $currency)
    ];
  }


  /*
   * Setter to allow custom logging
   *
   * @param $f callable, callback method that must accept
   * 3 arguments, just like self::defaultLog()
   */
  public function setLogFunction(Callable $f) {
    $this->logMethod = $f;
  }


  /*
   * Customisation method. Sets the toolbox log level to NOTICE one
   * This is the wider level, every log entry will be processed
   */
  public function setNoticeLogLevel() {
    $this->logLevel = self::NOTICE;
  }


  /*
   * Customisation method. Sets the toolbox log level to WARNING one
   * Only the ERROR and WARNING messages will be processed
   */
  public function setWarningLogLevel() {
    $this->logLevel = self::WARNING;
  }

  /*
   * Customisation method. Sets the toolbox log level to WARNING one
   * Only the ERROR messages will be processed
   */
  public function setErrorLogLevel() {
    $this->logLevel = self::ERROR;
  }

  /*
   * Utility method. Sends a NOTICE log entry to the logging mechanism
   *  if the toolbox log level permits it.
   *
   * @param $message string, main log information, as a sentence
   * @param $data mixed, additionnal informations
   */
  public function logNotice($message, $data = NULL) {
    if ($this->logLevel >= self::NOTICE) {
      $this->_log('NOTICE', $message, $data);
    }
  }

  /*
   * Utility method. Sends a WARNING log entry to the logging mechanism
   *  if the toolbox log level permits it.
   *
   * @param $message string, main log information, as a sentence
   * @param $data mixed, additionnal informations
   */
  public function logWarning($message, $data = NULL) {
    if ($this->logLevel >= self::WARNING) {
      $this->_log('WARNING', $message, $data);
    }
  }

  /*
   * Utility method. Sends an ERROR log entry to the logging mechanism
   *  if the toolbox log level permits it.
   *
   * @param $message string, main log information, as a sentence
   * @param $data mixed, additionnal informations
   */
  public function logError($message, $data = NULL) {
    if ($this->logLevel >= self::ERROR) {
      $this->_log('ERROR', $message, $data);
    }
  }

  /**
   * get_Signature
   * Calcul de la signature
   *
   * @param $field
   * @param $key
   * @return string
   */
  public function get_Signature($field,$key) {

    ksort($field); // tri des paramétres par ordre alphabétique
    $contenu_signature = "";
    foreach ($field as $nom => $valeur)
    {
      if(substr($nom,0,5) == 'vads_') {
        $contenu_signature .= $valeur."+";
      }
    }
    $contenu_signature .= $key;	// On ajoute le certificat à la fin de la chaîne.
    $signature = sha1($contenu_signature);
    return($signature);

  }

  /**
   * Check_Signature
   * Contrôle de la signature reçue
   *
   * @param $field
   * @param $key
   * @return string
   */
  public function Check_Signature($field,$key) {
    $result='false';

    $signature= self::get_Signature($field,$key);

    if( isset($field['signature']) && ($signature == $field['signature'])) {
      $result='true';
    }

    return ($result);
  }

  /*************** CLASS METHODS - PROTECTED *************/

  /**
   * Utility method, formats and prints log informations
   * This is the default logging method, if no custom one
   * has been previously provided.
   *
   * @param $level string, severity level of the log informations
   * @param $message string, main log information, as a sentence
   * @param null $data mixed, additionnal informations
   */
  protected function defaultLog($level, $message, $data = NULL) {
    error_log(sprintf("[%s][%s] %s %s\n",
        date('Y-m-d H:s:i')
        , $level
        , $message
        , $data ? "\n" . print_r($data, TRUE) : ''
      )
    );
  }

  /*
   * Utility method, main passing point for log messages
   * Relays the log entry to the configured log method stored
   * in self::logMethod
   *
   * @param $level string, one of NOTICE, WARNING, ERROR
   * @param $message string, main log information, as a sentence
   * @param $data mixed, additionnal informations, as array or object
   */
  protected function _log($level, $message, $data = NULL) {
    $log = $this->logMethod;
    $log($level, $message, $data);
  }

}