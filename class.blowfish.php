<?php

class blowfish {
	private $key;
	private $iv;
	private $mode;
	
	public function __construct($mode = 'ecb', $key = 'm1d@t0?c*M@X2Q_@', $iv = 'a9c_b8$4') {
		$this->key = $key;
		$this->iv = $iv;
		$this->mode = $mode;
		
		return;
	}
	
	private function pad($data) {
		$padlen = 8 - (strlen($data) % 8);
		
		for ($i = 0; $i < $padlen; $i++)
		$data .= chr($padlen);
		return $data;
	}
	
	function encrypt($data) {
		$td = mcrypt_module_open('blowfish', '', $this->mode, $this->iv);
		mcrypt_generic_init($td, $this->key, $this->iv);
		$encrypted_data = mcrypt_generic($td, $this->pad($data));
		$encrypted_data = base64_encode($encrypted_data);
		mcrypt_generic_deinit($td);
		mcrypt_module_close($td);
		
		return $encrypted_data;
	}
	
	function decrypt($data) {
		$decrypted = base64_decode($data, true);
		
		if ($decrypted === false) {
			return $data;
		}
		
	  return mcrypt_decrypt(MCRYPT_BLOWFISH, $this->key, $decrypted, constant('MCRYPT_MODE_' . strtoupper($this->mode)), $this->iv);
	}
}

?>