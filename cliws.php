<?php

// Include the NuSOAP class file:
require_once('class.nusoap.php');
require_once('class.xml.php');
require_once('class.blowfish.php');

class libws extends blowfish {
	private $ws;
	private $url;
	private $origin;
	private $client;
	private $destiny;
	private $server;
	private $bridge;
	private $params;
	private $unique;
	private $namespace;
	private $object;
	private $type;
	
	public function __construct($url = '') {
		if (is_array($url)) {
			$this->params = $this->bridge = $url;
			$url = $url[0];
		}
		
		if (strpos($url, '://') === false) {
			$url = (!empty($url)) ? $url : 'libws';
			
			if (strpos($url, ':') !== false) {
				$ini_bridge_part = explode(':', $url);
				
				$url = $ini_bridge_part[0];
				$ini_bridge = $ini_bridge_part[1];
			} else {
				$ini_bridge = $url;
			}

			if (strpos($ini_bridge, '/') !== false) {
				$ini_bridge = array_pop(explode('/', $ini_bridge));
			}

			$ini_bridge = strtoupper($ini_bridge);
			$ini_file_path = dirname(__FILE__) . '/';

			foreach (w(' ./ ../', false, 'rtrim') as $path) {
				$url_part = false;

				if (strpos($url, '/') !== false) {
					$url_part = explode('/', $url);
					$url = array_pop($url_part);
					$path .= implode('/', $url_part) . '/';
				}

				$ini_file = $path . 'ini.' . $url . '.php';

				if (!empty($path) && $url_part === false) {
					$ini_file = $ini_file_path . $ini_file;
				}

				if (@file_exists($ini_file)) {
					$this->params = parse_ini_file($ini_file);
					break;
				}
			}

			if (!isset($this->params[$ini_bridge])) {
				return false;
			}

			$this->bridge = $this->params[$ini_bridge];
			unset($this->params[$ini_bridge]);
			
			$url = $this->bridge[0];
			$this->destiny = end($this->bridge);
			reset($this->bridge);
		}

		foreach (w('?wsdl mysql:// oracle:// php:// facebook:// email://') as $row) {
			if (!is_array($url) && strpos($url, $row) !== false) {
				$this->type = preg_replace('/[^a-z]/', '', $row);
				break;
			}
		}

		$this->url = $url;
		$this->origin = true;
		$this->unique = true;

		return true;
	}

	function recursive_htmlentities($data) {
		if (is_string($data)) {
			$data = htmlentities(utf8_encode($data), ENT_QUOTES, "UTF-8");
			return preg_replace('#&amp;((.*?);)#', '&\1', $data);
		}

		if (is_array($data) || is_object($data)) {
			foreach ($data as $key => &$value) {
				$value = $this->recursive_htmlentities($value);
			}

			return $data;
		}

		return $data;
	}

	// Database filter layer
	function __prepare() {
		if (!$args = func_get_args()) {
			return false;
		}
		
		$sql = array_shift($args);
		
		if (is_array($sql)) {
			$sql_ary = w();
			foreach ($sql as $row) {
				$sql_ary[] = $this->__filter($row, $args);
			}
			
			return $sql_ary;
		}
		
		$count_args = count($args);
		$sql = str_replace('%', '[!]', $sql);
		
		if (!$count_args || $count_args < 1) {
			return str_replace('[!]', '%', $sql);
		}
		
		if ($count_args == 1 && is_array($args[0])) {
			$args = $args[0];
		}
		
		foreach ($args as $i => $arg) {
			$args[$i] = (strpos($arg, '/***/') !== false) ? $arg : db_escape_mimic($arg);
		}
		
		foreach ($args as $i => $row) {
			if (strpos($row, 'addquotes') !== false) {
				$e_row = explode(',', $row);
				array_shift($e_row);
				
				foreach ($e_row as $j => $jr) {
					$e_row[$j] = "'" . $jr . "'";
				}
				
				$args[$i] = implode(',', $e_row);
			}
		}
		
		array_unshift($args, str_replace(w('?? ?'), w("%s '%s'"), $sql));
		
		// Conditional deletion of lines if input is zero
		if (strpos($args[0], '-- ') !== false) {
			$e_sql = explode("\n\r", $args[0]);
			
			$matches = 0;
			foreach ($e_sql as $i => $row) {
				$e_sql[$i] = str_replace('-- ', '', $row);
				if (strpos($row, '%s')) {
					$matches++;
				}
				
				if (strpos($row, '-- ') !== false && !$args[$matches]) {
					unset($e_sql[$i], $args[$matches]);
				}
			}
			
			$args[0] = implode($e_sql);
		}
		
		return str_replace('[!]', '%', _hook('sprintf', $args));
	}

	public function __ws_construct($app, $object, $namespace = '') {
		$this->server = new nusoap_server();
		$this->namespace = (!empty($namespace)) ? $namespace : $this->url;
		$this->object = $object;

		$this->server->configureWSDL($app, $namespace);
		$this->server->wsdl->schemaTargetNamespace = $namespace;

		return;
	}

	public function __ws_method($method, $input, $output) {
		if (!function_exists($method)) {
			$format = 'function %s(%s) { return %s::%s(%s); }';
			$assign = "%s::__combine('%s', '%s', true);";

			$arg = '';
			if (count($input)) {
				$arg = array_keys($input);

				eval(sprintf($assign, $this->object, $method, implode(' ', $arg)));

				$arg = '$' . implode(', $', $arg);
			}

			eval(sprintf($format, $method, $arg, $this->object, $method, $arg));
		}

		$this->server->register($method, $input, $output, $this->namespace . $this->namespace . '/' . $method);
	}

	public function __ws_service() {
		$HTTP_RAW_POST_DATA = isset($HTTP_RAW_POST_DATA) ? $HTTP_RAW_POST_DATA : implode("\r\n", file('php://input'));

        $this->server->service($HTTP_RAW_POST_DATA);
	}

	public function __ws_object() {
		return $this->server;
	}

	public function _v($v) {
		return $this->_param_replace('#' . $v);
	}

	private function _filter($response) {
		$a = w();

		if (!is_array($response)) {
			$response = array($response);
		}

		foreach ($response as $i => $row) {
			$a[$i] = is_array($row) ? $this->_filter($row) : str_replace(w('&lt; &gt;'), w('< >'), utf8_encode($row));
		}
		
		return $a;
	}
	
	private function _param_replace($arg) {
		$arg = (is_object($arg)) ? (array) $arg : $arg;

		if (is_array($arg)) {
			foreach ($arg as $i => $row) {
				$arg[$i] = $this->_param_replace($row);
			}

			return $arg;
		}

		return (strpos($arg, '#') !== false) ? preg_replace('/\#([A-Z\_]+)/e', '(isset($this->params["$1"])) ? @$this->params["$1"] : "$1"', $arg) : $arg;
	}
	
	private function _build($ary, $s = true) {
		$query = '';
		foreach ($ary as $i => $row) {
			if (is_array($row)) {
				$i = $row[0];
				$row = $row[1];
			}

			$query .= ((!empty($query)) ? '&' : '') . $i . '=' . urlencode($row);
		}

		return ($s ? '?' : '') . $query;
	}
	
	private function _format($data) {
		if (is_array($data) && isset($data['response'])) {
			$data = $data['response'];
		}
		
		preg_match_all('#([a-z0-9\.]+)\=(.*?)\n#i', $data, $parts);
		
		$details = 'identitydetails.attribute.name';
		$details2 = 'userdetails.attribute.name';
		$values = 'identitydetails.attribute.value';
		$values2 = 'userdetails.attribute.value';
		$attr = 'identitydetails.attribute';

		$open = false;
		$response = w();
		foreach ($parts[1] as $i => $name) {
			$value = $parts[2][$i];

			switch ($name) {
				case $attr:
					break;
				case $details:
				case $details2:
					if ($open) {
						$response[$open] = '';
						$open = false;
					}

					if (!$open) {
						$open = str_replace(w('. -'), '_', strtolower($value));
						continue;
					}
					break;
				case $values:
				case $values2:
					if ($open) {
						$response[$open] = $value;
						$open = false;
					}
					break;
				default:
					$name = str_replace(w('. -'), '_', strtolower($name));
					$response[$name] = $value;
					break;
			}
		}

		return $response;
	}

	private function _format_users($data) {
		if (isset($data['response'])) {
			$data = $data['response'];
		}

		preg_match_all('#([a-z0-9\.]+)\=(.*?)\n#i', $data, $parts);

		return $parts[2];
	}

	public function __enrichment($override = false) {
		static $number;

		$f = 'HTTP_X_NOKIA_MSISDN';
		if ($override !== false) {
			$_SERVER[$f] = $override;
		}

		if (!isset($number)) {
			$number = (isset($_SERVER[$f]) && !empty($_SERVER[$f])) ? $_SERVER[$f] : '';
		}

		preg_match('/(\d{3})(\d+)/i', $number, $part);
		unset($part[0]);

		foreach (w('1 2') as $i) {
			if (!isset($part[$i])) $part[$i] = '';
		}

		return (object) array_combine(w('area number'), $part);
	}
	
	public function __claro_is($country, $phone, $by_name = false) {
		$is = $this->IsClaro_Phone(array(
			'user' => '#SMS_USER',
			'pass' => '#SMS_PASS',
			'area' => $country,
			'phone' => $phone)
		);
		
		if (isset($is->IsClaro_PhoneResult)) {
			$response = (int) $is->IsClaro_PhoneResult;
			
			if ($by_name !== false) {
				switch ($response) {
					case -1: $response = 'ESPE'; break;
					case 1: $response = 'PREP'; break;
					case 2: $response = 'HIBR'; break;
					case 3: $response = 'POST'; break;
				}
			}
			return $response;
		}
		
		return false;
	}

	public function __claro_is_pc($country, $phone, $by_name = false) {
		$is = $this->validaTelefono(array(
			'codigoPais' => $country,
			'telefono' => $phone) 
		);

		if (isset($is->validaTelefonoResult->tipoCliente)) {
			$response = $is->validaTelefonoResult->tipoCliente;
			
			if ($by_name !== false) {
				switch ($response) {
					case "O":
					case "M": $response = 'PREP'; break;
					default: $response 	= 'POST'; break;
				}
			}
			return $response;
		}
		
		return false;
	}
	
	public function __claro_sms($country, $phone, $message) {
		if ($this->__claro_is($country, $phone)) {
			$sms = $this->Send_SMS(array(
				'user' => '#SMS_USER',
				'pass' => '#SMS_PASS',
				'to_phone' => $country.$phone,
				'text' => $message)
			);

			return true;
		}
		
		return false;
	}

	public function __sso_create($email, $password, $fn, $sn) {
		$token = $this->authenticate(array(
			'username' => '#SSO_USER',
			'password' => '#SSO_PASS')
		);

		if (!count($token)) {
			return array('timeout' => true);
		}

		$is_created = false;

		if (isset($token->token_id)) {
			$user = $this->read(array(
				'name' => $email,
				'admin' => $token->token_id)
			);

			if (!isset($user->user_id)) {
				$cn = $fn . ((!empty($fn) && !empty($sn)) ? ' ' : '') . $sn;
		
				$query = array(
					array('identity_name', $email),
					array('identity_attribute_names', 'userpassword'),
					array('identity_attribute_values_userpassword', $password),
					array('identity_attribute_names', 'givenname'),
					array('identity_attribute_values_givenname', $fn),
					array('identity_attribute_names', 'sn'),
					array('identity_attribute_values_sn', $sn),
					array('identity_attribute_names', 'cn'),
					array('identity_attribute_values_cn', $cn),
					array('identity_attribute_names', 'mail'),
					array('identity_attributes_values_mail', $email),
					array('identity_attribute_names', 'inetuserstatus'),
					array('identity_attributes_values_inetuserstatus', 'Active'),
					
					array('identity_realm', '/'),
					array('identity_type', 'user'),
					array('inetuserstatus', 'Active'),
					array('admin', $token->token_id)
				);
				$create = $this->create($query);
				
				$user = $this->read(array(
					'name' => $email,
					'admin' => $token->token_id)
				);

				if (isset($user->user_id)) {
					$this->update(array(
						'identity_name' => $email,
						'identity_attribute_names' => 'mail',
						'identity_attribute_values_mail' => $email,
						'admin' => $token->token_id)
					);
					$user->mail = $email;
					
					$is_created = $user;
				}
			}
			
			$out = $this->logout(array(
				'subjectid' => $token->token_id)
			);
		}
		
		return $is_created;
	}
	
	public function __sso_read($username) {
		$token = $this->authenticate(array(
			'username' => '#SSO_USER',
			'password' => '#SSO_PASS')
		);

		if (!count($token)) {
			return array('timeout' => true);
		}
		
		if (isset($token->token_id)) {
			$user = $this->read(array(
				'name' => $username,
				'admin' => $token->token_id)
			);
			
			$out = $this->logout(array(
				'subjectid' => $token->token_id)
			);
			
			if (isset($user->user_id)) {
				return $user;
			}
		}
		
		return false;
	}
	
	public function __sso_update($username, $name, $value) {
		$token = $this->authenticate(array(
			'username' => '#SSO_USER',
			'password' => '#SSO_PASS')
		);

		if (!count($token)) {
			return array('timeout' => true);
		}
		
		if (isset($token->token_id)) {
			$user = $this->update(array(
				'identity_name' => $username,
				'identity_attribute_names' => $name,
				'identity_attribute_values_' . $name => $value,
				'admin' => $token->token_id)
			);
			
			$out = $this->logout(array(
				'subjectid' => $token->token_id)
			);
			
			return true;
		}
		
		return false;
	}
	
	public function __sso_delete($username) {
		$token = $this->authenticate(array(
			'username' => '#SSO_USER',
			'password' => '#SSO_PASS')
		);

		if (!count($token)) {
			return array('timeout' => true);
		}
		
		if (isset($token->token_id)) {
			$user = $this->delete(array(
				'identity_name' => $username,
				'admin' => $token->token_id)
			);
			
			$out = $this->logout(array(
				'subjectid' => $token->token_id)
			);
			
			return true;
		}
		
		return false;
	}

	public function __sso_search($criteria) {
		$token = $this->authenticate(array(
			'username' => '#SSO_USER',
			'password' => '#SSO_PASS')
		);

		if (!count($token)) {
			return array('timeout' => true);
		}
		
		if (isset($token->token_id)) {
			$user = $this->search(array(
				'name' => $criteria,
				'admin' => $token->token_id)
			);
			
			$out = $this->logout(array(
				'subjectid' => $token->token_id)
			);

			return $user;
		}
		
		return false;
	}
	
	public function _() {
		$this->origin = false;
		$this->unique = false;
		$method = $_REQUEST['_method'];

		unset($_REQUEST['_method']);
		unset($_REQUEST['_chain']);
		unset($_REQUEST['_unique']);

		echo @$this->$method($_REQUEST);
		exit;
	}
	
	public function auth($username, $password, $type = 'basic') {
		return $this->client->setCredentials($username, $password, $type);
	}
	
	public function __call($method, $arg) {
		if (empty($this->url)) {
			error_log('libws: No url is configured.');
			return;
		}

		if (!is_array($arg)) {
			$arg = array($arg);
		}

		if (count($arg) == 1 && isset($arg[0]) && is_array($arg[0])) {
			$arg = $arg[0];
		}

		if (strpos($this->destiny, 'facebook') !== false) {
			$add = array(
				'APPID' => '#APPID',
				'APPSECRET' => '#APPSECRET'
			);
			$arg = array_merge($add, $arg);
		}

		if (isset($arg) && is_array($arg)) {
			$arg = $this->_param_replace($arg);
		} else {
			$arg_cp = $arg;
			$_arg = isset($arg[0]) ? w($arg[0]) : w();

			$arg = w();
			foreach ($_arg as $v) {
				if (isset($_REQUEST[$v])) $arg[$v] = $_REQUEST[$v];
			}

			$arg = (!$arg) ? $arg_cp : $arg;
		}

		$_bridge = $this->bridge;
		$count_bridge = count($_bridge);
		$_url = $this->url;
		$response = null;

		switch ($this->type) {
			case 'wsdl':
				$this->client = new nusoap_client($this->url, true);

				if ($error = $this->client->getError()) {
					$response = $error;
				} else {
					$response = $this->client->call($method, $arg);
					
					// Check if there were any call errors, and if so, return error messages.
					if ($error = $this->client->getError()) {
						$response = $this->client->response;
						$response = xml2array(substr($response, strpos($response, '<?xml')));
						
						if (isset($response['soap:Envelope']['soap:Body']['soap:Fault']['faultstring'])) {
							$fault_string = $response['soap:Envelope']['soap:Body']['soap:Fault']['faultstring'];
							
							$response = explode("\n", $fault_string);
							$response = $response[0];
						} else {
							$response = $error;
						}
						
						$response = array(
							'error' => true,
							'message' => $response
						);
					}
				}

				$response = json_decode(json_encode($this->_filter($response)));
				break;
			case 'mysql':
				if (isset($arg['_mysql'])) {
					$this->params['_MYSQL'] = $arg['_mysql'];
					unset($arg['_mysql']);
				}

				$connect = (isset($this->params['_MYSQL']) && $this->params['_MYSQL']) ? $this->params['_MYSQL'] : '';

				if (empty($arg)) {
					return false;
				}

				global $db;

				require_once('class.mysql.php');
				$db = new database($connect);

				if (empty($db->message)) {
					switch ($method) {
						case 'sql_field':
						case 'sql_build':
							break;
						default:
							if (count($arg) > 1) {
								$sql = array_shift($arg);
								$arg = sql_filter($sql, $arg);
							}
							break;
					}

					$response = (@function_exists($method)) ? false : array('error' => true, 'message' => $method . ' is undefined');

					if ($response === false) {
						switch ($method) {
							case 'sql_field':
							case 'sql_build':
								extract($arg, EXTR_PREFIX_ALL, 'sf');

								$arg_v = '';
								foreach ($arg as $i => $row) {
									$arg_v .= (($arg_v) ? ', ' : '') . '$sf_' . $i;
								}

								eval('$response = $method(' . $arg_v . ');');
								break;
							default:
								$response = $method($arg);
								break;
						}

						if ($method !== 'sql_filter') {
							$response = $this->recursive_htmlentities($response);
						}
					}
				}

				if (!empty($db->message)) {
					$response = $db->message;
				}
				break;
			case 'oracle':
				if (isset($arg['_oracle'])) {
					$this->params['_ORACLE'] = $arg['_oracle'];
					unset($arg['_oracle']);
				}

				$connect = (isset($this->params['_ORACLE']) && $this->params['_ORACLE']) ? $this->params['_ORACLE'] : '';

				if (empty($arg)) {
					return false;
				}

				global $db;

				require_once('class.oracle.php');
				$db = new database($connect);

				if (empty($db->message)) {
					switch ($method) {
						case 'sql_field':
						case 'sql_build':
							break;
						default:
							if (count($arg) > 1) {
								$sql = array_shift($arg);
								$arg = sql_filter($sql, $arg);
							}
							break;
					}

					//$response = (@function_exists($method)) ? $method($arg) : array('error' => true, 'message' => $method . ' is undefined');
					$response = (@function_exists($method)) ? false : array('error' => true, 'message' => $method . ' is undefined');

					if ($response === false) {
						switch ($method) {
							case 'sql_field':
							case 'sql_build':
								extract($arg, EXTR_PREFIX_ALL, 'sf');

								$arg_v = '';
								foreach ($arg as $i => $row) {
									$arg_v .= (($arg_v) ? ', ' : '') . '$sf_' . $i;
								}

								eval('$response = $method(' . $arg_v . ');');
								break;
							default:
								$response = $method($arg);
								break;
						}
					}
				}

				if (!isset($response['error']) && is_array($response)) {
					if (isset($response[0]) && is_array($response[0])) {
						foreach ($response as $i => $row) {
							if (is_array($row)) {
								$response[$i] = array_change_key_case($row, CASE_LOWER);
							}
						}
					} else {
						$response = array_change_key_case($response, CASE_LOWER);
					}
				}

				if (!empty($db->message)) {
					$response = $db->message;
				}
				break;
			case 'php':
				if (isset($arg['_php'])) {
					unset($arg['_php']);
				}

				$print = w();
				switch ($method) {
					case 'tail':
					case 'cat':
						if (!@is_readable($arg[0])) {
							$response = 'Can not read file: ' . $arg[0];
						}
						break;
					case 'ping':
						$arg[1] = '-c' . ((isset($arg[1])) ? $arg[1] : 3);
						break;
				}

				switch ($method) {
					case 'write':
						$response = false;

						if ($fp = @fopen($arg[0], $arg[1])) {
							if (@fwrite($fp, $arg[2]) !== false) {
								@fclose($fp);
								$response = true;
							}
						}
						break;
					case 'tail':
					case 'cat':
					case 'ping':
						if ($response === null) {
							exec($method . ' ' . implode(' ', $arg), $print);
							$response = implode("\r\n", $print);
						}
						break;
					case 'exec':
						if ($response === null) {
							$method(implode(' ', $arg), $print);
							$response = implode("\r\n", $print);
						}
						break;
					default:
						ob_start();

						if (@function_exists($method) || $method == 'eval') {
							eval(($method == 'eval') ? $arg[0] : 'echo @$method(' . (count($arg) ? "'" . implode("', '", $arg) . "'" : '') . ');');

							$_arg = error_get_last();
						} else {
							$_arg = array('message' => 'PHP Fatal error: Call to undefined function ' . $method . '()');
						}

						$response = (null === $_arg) ? ob_get_contents() : array('url' => $_url . $method, 'error' => 500, 'message' => $_arg['message']);

						ob_end_clean();
						break;
				}
				break;
			case 'facebook':
				if (isset($arg['_facebook'])) {
					unset($arg['_facebook']);
				}

				//header('Content-type: text/html; charset=utf-8');
				require_once('class.facebook.php');

				$facebook = new Facebook(array(
					'appId'  => $arg['APPID'],
					'secret' => $arg['APPSECRET'])
				);
				unset($arg['APPID'], $arg['APPSECRET']);

				try {
					$page = array_shift($arg);
					$page = (is_string($page)) ? '/' . $page : $page;
					
					$req = (isset($arg[0]) && is_string($arg[0])) ? array_shift($arg) : '';
					$req = (empty($req)) ? 'get' : $req;

					$arg = (isset($arg[0])) ? $arg[0] : $arg;

					$response = (!empty($page)) ? (count($arg) ? $facebook->$method($page, $req, $arg) : $facebook->$method($page, $req)) : $facebook->$method();
				} catch (FacebookApiException $e) {
					$response = array(
						'url' => $_url,
						'error' => 500,
						'message' => trim(str_replace('OAuthException: ', '', $e))
					);

					error_log($e);
				}

				unset($facebook);
				break;
			case 'email':
				if (isset($arg['_email'])) {
					$this->params['_EMAIL'] = $arg['_email'];
					unset($arg['_email']);
				}

				$response = false;

				if (!isset($arg['to'])) {
					$response = 'NO_TO_ADDRESS';
				}

				if ($response === false && !isset($arg['from'])) {
					$response = 'NO_FROM_ADDRESS';
				}

				if ($response === false) {
					if (!is_array($arg['to'])) {
						$arg['to'] = array($arg['to']);
					}

					preg_match_all('!("(.*?)"\s+<\s*)?(.*?)(\s*>)?!', $arg['from'], $matches);
					/*$response = array();
					for ($i=0; $i<count($matches[0]); $i++) {
						$response[] = array(
							'name' => $matches[1][$i],
							'email' => $matches[2][$i],
						);
					}*/

					$response = $matches;


					// Create Mail object
					/*$mail = new phpmailer();

					$mail->PluginDir = '';
					$mail->Mailer = 'smtp';
					$mail->Host = $this->params['_EMAIL'];
					$mail->SMTPAuth = false;
					$mail->From = $from;
					$mail->FromName = "Claro";
					$mail->Timeout = 30;*/

					foreach ($arg['to'] as $row) {
						//$mail->AddAddress($row);
					}
				}

				//require_once('class.email.php');

				//$emailer = new emailer();

				//$response = print_r($arg, true);
				break;
			default:
				$send_var = w('sso mysql oracle php facebook email');
				$send = new stdClass;

				if ($count_bridge == 1 && $_bridge[0] === $_url) {
					$count_bridge--;
					array_shift($_bridge);
				}

				foreach ($send_var as $row) {
					$val = '_' . strtoupper($row);
					$send->$row = (isset($this->params[$val]) && $this->params[$val]) ? $this->params[$val] : false;

					if (!$count_bridge && ($send->$row || isset($arg['_' . $row]))) {
						$this->type = $row;
					}
				}

				switch ($this->type) {
					case 'sso':
						$this->origin = false;

						$_url .= $method;
						unset($arg['_sso']);
						break;
					default:
						foreach ($send_var as $row) {
							if (isset($send->$row) && !empty($send->$row)) {
								$arg['_' . $row] = $send->$row;
							}
						}

						$arg['_method'] = $method;
						$arg['_unique'] = (!$this->unique) ? $this->unique : 1;
						
						if (isset($_bridge) && count($_bridge)) {
							array_shift($_bridge);
							$arg['_chain'] = implode('|', $_bridge);
						}
						break;
				}

				$_arg = $arg;
				$arg = ($this->type == 'sso') ? $this->_build($arg, false) : __encode($arg);

				$socket = @curl_init();
				@curl_setopt($socket, CURLOPT_URL, $_url);
				@curl_setopt($socket, CURLOPT_VERBOSE, 0);
				@curl_setopt($socket, CURLOPT_HEADER, 0);
				@curl_setopt($socket, CURLOPT_RETURNTRANSFER, 1);
				@curl_setopt($socket, CURLOPT_POST, 1);
				@curl_setopt($socket, CURLOPT_POSTFIELDS, $arg);
				@curl_setopt($socket, CURLOPT_SSL_VERIFYPEER, 0);
				@curl_setopt($socket, CURLOPT_SSL_VERIFYHOST, 1);

				$response = @curl_exec($socket);

				$_curl = new stdClass;
				$_curl->err = @curl_errno($socket);
				$_curl->msg = @curl_error($socket);
				$_curl->inf = (object) @curl_getinfo($socket);
				@curl_close($socket);

				switch ($_curl->err) {
					/**
					If the request has no errors.
					*/
					case 0:
						switch ($this->type) {
							/**
							SSO type
							*/
							case 'sso':
								if (preg_match('#<body>(.*?)</body>#i', $response, $part)) {
									preg_match('#<p><b>description</b>(.*?)</p>#i', $part[1], $status);
									
									$response = array(
										'url' => $_url,
										'error' => $_curl->inf->http_code,
										'message' => trim($status[1])
									);
								} else {
									switch($method) {
										case 'search':
											preg_match_all('/string\=(.*?)\n/i', $response, $response_all);
											$response = $response_all[1];
											break;
										default:
											$response = $this->_format($response);
											break;
									}
								}
								break;
							/**
							Any other type
							*/
							default:
								$_json = json_decode($response);

								if ($_json === null) {
									$response = trim($response);
									$response = (!empty($response)) ? $response : $_curl->inf;

									$_json = $response;
								}
								
								$response = $_json;
								break;
						}
						break;
					/**
					Some error was generated after the request.
					*/
					default:
						$response = array(
							'url' => $_url,
							'error' => 500,
							'message' => $_curl->msg
						);
						break;
				}

				break;
		}

		if (!$this->origin || $this->unique) {
			$response = json_encode($response);
		}

		if (($this->type == 'sso' && $this->unique) || ($this->type != 'sso' && $this->unique)) {
			$response = json_decode($response);
		}

		if (is_array($response) && isset($response[0]) && is_string($response[0]) && strpos($response[0], '<?xml') !== false) {
			$response = array_change_key_case_recursive(xml2array($response[0]));

			$response = json_decode(json_encode($response));
		}

		return $response;
	}
}

//
// General functions
//
function w($a = '', $d = false, $del = 'trim') {
	if (empty($a) || !is_string($a)) return array();
	
	$e = explode(' ', $del($a));
	if ($d !== false) {
		foreach ($e as $i => $v) {
			$e[$v] = $d;
			unset($e[$i]);
		}
	}
	
	return $e;
}

function array_change_key_case_recursive($input, $case = null) {
	if (!is_array($input)) {
		trigger_error("Invalid input array '{$input}'",E_USER_NOTICE); exit;
	}

	// CASE_UPPER|CASE_LOWER
	if (null === $case) {
		$case = CASE_LOWER;
	}

	if (!in_array($case, array(CASE_UPPER, CASE_LOWER))) {
		trigger_error("Case parameter '{$case}' is invalid.", E_USER_NOTICE); exit;
	}

	$input = array_change_key_case($input, $case);
	foreach ($input as $key => $array) {
		if (is_array($array)) {
			$input[$key] = array_change_key_case_recursive($array, $case);
		}
	}

	return $input;
}

function hex2asc($str) {
	$str2 = '';
	for ($n = 0, $end = strlen($str); $n < $end; $n += 2) {
		$str2 .=  pack('C', hexdec(substr($str, $n, 2)));
	}
	
	return $str2;
}

function encode($str) {
	return bin2hex(base64_encode($str));
}

function decode($str) {
	return base64_decode(hex2asc($str));
}

if (!function_exists('_pre')) {
	function _pre($a, $d = false) {
		echo '<pre>';
		print_r($a);
		echo '</pre>';
		
		if ($d === true) {
			exit;
		}
	}
}

function __encode($arg) {
	foreach ($arg as $i => $row) {
		$_i = encode($i);
		$arg[$_i] = encode(json_encode($row));
		unset($arg[$i]);
	}

	return $arg;
}

function __decode($arg) {
	foreach ($arg as $i => $row) {
		$_i = decode($i);
		$arg[$_i] = json_decode(decode($row));
		unset($arg[$i]);
	}
	
	return $arg;
}

function _hook($name, $args = array(), $arr = false) {
	switch ($name) {
		case 'isset':
			eval('$a = ' . $name . '($args' . ((is_array($args)) ? '[0]' . $args[1] : '') . ');');
			return $a;
			break;
		case 'in_array':
			if (is_array($args[1])) {
				if (_hook('isset', array($args[1][0], $args[1][1]))) {
					eval('$a = ' . $name . '($args[0], $args[1][0]' . $args[1][1] . ');');
				}
			} else {
				eval('$a = ' . $name . '($args[0], $args[1]);');
			}
			
			return (isset($a)) ? $a : false;
			break;
	}
	
	$f = 'call_user_func' . ((!$arr) ? '_array' : '');
	return $f($name, $args);
}

function _prefix($prefix, $arr) {
	$prefix = ($prefix != '') ? $prefix . '_' : '';
	
	$a = w();
	foreach ($arr as $k => $v) {
		$a[$prefix . $k] = $v;
	}
	return $a;
}

function db_escape_mimic($inp) {
	if (is_array($inp)) {
		return array_map(__METHOD__, $inp);
	}

	if (!empty($inp) && is_string($inp)) {
		return str_replace(array('\\', "\0", "\n", "\r", "'", '"', "\x1a"), array('\\\\', '\\0', '\\n', '\\r', "\\'", '\\"', '\\Z'), $inp);
	}

	return $inp;
}

function _htmlencode($str, $multibyte = false) {
	$result = trim(htmlentities(str_replace(array("\r\n", "\r", '\xFF'), array("\n", "\n", ' '), $str)));
	
	if ($multibyte) {
		$result = preg_replace('#&amp;(\#\d+;)#', '&\1', $result);
	}
	$result = preg_replace('#&amp;((.*?);)#', '&\1', $result);
	
	return $result;
}

function _set_var(&$result, $var, $type, $multibyte = false, $regex = '') {
	settype($var, $type);
	$result = $var;

	if ($type == 'string') {
		$result = _htmlencode($result, $multibyte);
	}
}

//
// Get value of request var
//
function v($var_name, $default, $multibyte = false, $regex = '') {
	if (preg_match('/^(files)(\:?(.*?))?$/i', $var_name, $files_data)) {
		switch ($files_data[1]) {
			case 'files':
				$var_name = (isset($files_data[3]) && !empty($files_data[3])) ? $files_data[3] : $files_data[1];
				
				$_REQUEST[$var_name] = isset($_FILES[$var_name]) ? $_FILES[$var_name] : $default;
				break;
		}
	}
	
	if (!isset($_REQUEST[$var_name]) || (is_array($_REQUEST[$var_name]) && !is_array($default)) || (is_array($default) && !is_array($_REQUEST[$var_name]))) {
		return (is_array($default)) ? array() : $default;
	}
	
	$var = $_REQUEST[$var_name];
	
	if (!is_array($default)) {
		$type = gettype($default);
		$var = ($var);
	} else {
		list($key_type, $type) = each($default);
		$type = gettype($type);
		$key_type = gettype($key_type);
	}
	
	if (is_array($var)) {
		$_var = $var;
		$var = array();

		foreach ($_var as $k => $v) {
			if (is_array($v)) {
				foreach ($v as $_k => $_v) {
					_set_var($k, $k, $key_type);
					_set_var($_k, $_k, $key_type);
					_set_var($var[$k][$_k], $_v, $type, $multibyte);
				}
			} else {
				_set_var($k, $k, $key_type);
				_set_var($var[$k], $v, $type, $multibyte);
			}
		}
	} else {
		_set_var($var, $var, $type, $multibyte);
	}
	
	return $var;
}

function __($url = '') {
	if (!isset($_REQUEST)) {
		exit;
	}

	$_REQUEST = __decode($_REQUEST);
	if (!isset($_REQUEST['_method']) || !isset($_REQUEST['_chain'])) {
		exit;
	}

	return npi(explode('|', $_REQUEST['_chain']))->_();
}

function npi($url = '') {
	return new libws($url);
}

?>