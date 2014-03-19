<?php

require_once "thirdparty/Mandrill.php";

/*
 * MandrillMailer for Silverstripe
 * 
 * Features
 * - Global tag support
 * - Multiple recipient support
 * - File attachment support
 * 
 * @link https://mandrillapp.com/api/docs/messages.php.html#method-send
 * @package Mandrill
 * @author LeKoala <thomas@lekoala.be>
 */

class MandrillMailer extends Mailer {

	/**
	 * @var Mandrill
	 */
	protected $mandrill;
	protected static $instance;

	function __construct($apiKey) {
		$this->mandrill = new Mandrill($apiKey);
		//fix ca cert permissions
		if (strlen(ini_get('curl.cainfo')) === 0) {
			curl_setopt($this->mandrill->ch, CURLOPT_CAINFO, __DIR__ . "/cacert.pem");
		}
		self::$instance = $this;
	}

	/**
	 * Get mandrill api
	 * @return \Mandrill
	 */
	public function getMandrill() {
		return $this->mandrill;
	}

	/**
	 * Set mandrill api
	 * @param Mandrill $mandrill
	 */
	public function setMandrill(Mandrill $mandrill) {
		$this->mandrill = $mandrill;
	}

	/**
	 * @return \MandrillMailer
	 */
	public static function getInstance() {
		return self::$instance;
	}

	/**
	 * Get default params used by all outgoing emails
	 * @return string
	 */
	public static function getDefaultParams() {
		return Config::inst()->get(__CLASS__,'default_params');
	}
	
	/**
	 * Set default params
	 * @param string $v
	 * @return \MandrillMailer
	 */
	public static function setDefaultParams(array $v) {
		return Config::inst()->update(__CLASS__,'default_params',$v);
	}
	/**
	 * Get subaccount used by all outgoing emails
	 * @return string
	 */
	public static function getSubaccount() {
		return Config::inst()->get(__CLASS__,'subaccount');
	}
	
	/**
	 * Set subaccount
	 * @param string $v
	 * @return \MandrillMailer
	 */
	public static function setSubaccount($v) {
		return Config::inst()->update(__CLASS__,'subaccount',$v);
	}
	
	/**
	 * Get global tags applied to all outgoing emails
	 * @return array
	 */
	public static function getGlobalTags() {
		$tags = Config::inst()->get(__CLASS__,'global_tags');
		if (!is_array($tags)) {
			$tags = array($tags);
		}
		return $tags;
	}

	/**
	 * Set global tags applied to all outgoing emails
	 * @param array $arr
	 * @return \MandrillMailer
	 */
	public static function setGlobalTags($arr) {
		if (is_string($arr)) {
			$arr = array($arr);
		}
		return Config::inst()->update(__CLASS__,'global_tags',$arr);
	}

	/**
	 * Add file upload support to mandrill
	 * 
	 * @param type $file
	 * @param type $destFileName
	 * @param type $disposition
	 * @param type $extraHeaders
	 * @return type
	 */
	function encodeFileForEmail($file, $destFileName = false, $disposition = NULL, $extraHeaders = "") {
		if (!$file) {
			user_error("encodeFileForEmail: not passed a filename and/or data", E_USER_WARNING);
			return;
		}

		if (is_string($file)) {
			$file = array('filename' => $file);
			$fh = fopen($file['filename'], "rb");
			if ($fh) {
				$file['contents'] = "";
				while (!feof($fh))
					$file['contents'] .= fread($fh, 10000);
				fclose($fh);
			}
		}

		if (!$destFileName)
			$base = basename($file['filename']);
		else
			$base = $destFileName;

		$mimeType = !empty($file['mimetype']) ? $file['mimetype'] : HTTP::get_mime_type($file['filename']);
		if (!$mimeType)
			$mimeType = "application/unknown";

		// Return completed packet
		return array(
			'type' => $mimeType,
			'name' => $destFileName,
			'content' => $file['contents']
		);
	}

	/**
	 * Mandrill takes care for us to send plain and/or html emails. See send method
	 * 
	 * @param string|array $to
	 * @param string $from
	 * @param string $subject
	 * @param string $plainContent
	 * @param array $attachedFiles
	 * @param array $customheaders
	 * @return array|bool
	 */
	function sendPlain($to, $from, $subject, $plainContent, $attachedFiles = false, $customheaders = false) {
		return $this->send($to, $from, $subject, false, $attachedFiles, $customheaders, $plainContent, false);
	}

	/**
	 * Mandrill takes care for us to send plain and/or html emails. See send method
	 * 
	 * @param string|array $to
	 * @param string $from
	 * @param string $subject
	 * @param string $plainContent
	 * @param array $attachedFiles
	 * @param array $customheaders
	 * @return array|bool
	 */
	function sendHTML($to, $from, $subject, $htmlContent, $attachedFiles = false, $customheaders = false, $plainContent = false, $inlineImages = false) {
		return $this->send($to, $from, $subject, $htmlContent, $attachedFiles, $customheaders, $plainContent, $inlineImages);
	}

	/**
	 * Send the email through mandrill
	 * 
	 * @param string|array $to
	 * @param string $from
	 * @param string $subject
	 * @param string $plainContent
	 * @param array $attachedFiles
	 * @param array $customheaders
	 * @return array|bool
	 */
	protected function send($to, $from, $subject, $htmlContent, $attachedFiles = false, $customheaders = false, $plainContent = false, $inlineImages = false) {
		$orginal_to = $to;
		$tos = explode(',', $to);

		if (count($tos) > 1) {
			$to = array();
			foreach ($tos as $t) {
				$to[] = array('email' => $t);
			}
		}

		if (!is_array($to)) {
			$to = array(array('email' => $to));
		}

		$default_params = array();
		if(self::getDefaultParams()) {
			$default_params = self::getDefaultParams();
		}
		$params = array_merge($default_params,array(
			"subject" => $subject,
			"from_email" => $from,
			"to" => $to
		));

		if (is_array($from)) {
			$params['from_email'] = $from['email'];
			$params['from_name'] = $from['name'];
		}

		if ($plainContent) {
			$params['text'] = $plainContent;
		}
		if ($htmlContent) {
			$params['html'] = $htmlContent;
		}

		if (self::getGlobalTags()) {
			$params['tags'] = self::getGlobalTags();
		}
		
		if(self::getSubaccount()) {
			$params['subaccount'] = self::getSubaccount();
		}

		$bcc_email = Config::inst()->get('Email', 'bcc_all_emails_to');
		if ($bcc_email) {
			if (is_string($bcc_email)) {
				$params['bcc_address'] = $bcc_email;
			}
		}

		if ($attachedFiles) {
			$attachments = array();

			// Include any specified attachments as additional parts
			foreach ($attachedFiles as $file) {
				if (isset($file['tmp_name']) && isset($file['name'])) {
					$messageParts[] = $this->encodeFileForEmail($file['tmp_name'], $file['name']);
				} else {
					$messageParts[] = $this->encodeFileForEmail($file);
				}
			}

			$params['attachments'] = $attachments;
		}

		if ($customheaders) {
			$params['headers'] = $customheaders;
		}

		$ret = $this->getMandrill()->messages->send($params);

		if ($ret) {
			return array($orginal_to, $subject, $htmlContent, $customheaders);
		} else {
			return false;
		}
	}

}
