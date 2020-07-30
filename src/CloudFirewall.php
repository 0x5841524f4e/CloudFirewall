<?php

/**
 *  CloudFirewall - Protect your website against bots/spam/flood, sql/xss injection attacks as using Cloudflare service.
 *
 *  @author Uğur PEKESEN <me@xaron.us>
 *  @version 0.1
 */

namespace CF;

class CloudFirewall {

    private $email;
    private $key;
    private $zone;
    private $curl;
    private $debug;
    protected $version = '0.1.4.3';

    /**
     * CloudFirewall constructor.
     *
     * @param string $email The your Cloudflare email address.
     * @param string $key The your Cloudflare API key.
     * @param string $zone The your Cloudflare zone.
     */
    public function __construct(string $email, string $key, string $zone = null) {
        $this->email = $email;
        $this->key = $key;
        $this->zone = $zone;
        $this->benchmarkStart = $this->benchmarkStart();
        if(isset($_SESSION)) {
            $_SESSION['CloudFirewall-Client-IP'] = $this->getIP();
        } else {
            session_start();
            $_SESSION['CloudFirewall-Client-IP'] = $this->getIP();
        }
        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            $this->abort(500, 'Error: '.$errstr.' - Error Code '.$errno.'<br/><br/>File: '.$errfile.' - Line <strong>'.$errline.'</strong>');
        });
        set_exception_handler(function($exception) {
            $this->abort(500, $exception->getMessage());
        });
        header("x-powered-by: CloudFirewall WAF");
    }

    /**
     * Changes security level on your zone.
     *
     * @param string $value The security levels [essentially_off, low, medium, high, under_attack].
     * @param string $zone The zone id.
     * @return mixed Bool False if request is not responded. JSON if request success.
     */
    public function changeSecurityLevel(string $value = 'low', string $zone = null) {
        if($this->checkSecurityLevel($value)) {
            if($zone) {
                return $this->connect('https://api.cloudflare.com/client/v4/zones/'.$zone.'/settings/security_level', 'PATCH', array('value' => $value));
            } else {
                return $this->connect('https://api.cloudflare.com/client/v4/zones/'.$this->zone.'/settings/security_level', 'PATCH', array('value' => $value));
            }
        } else {
            return false;
        }
    }

    /**
     * Create rule to specified IP address with an action.
     *
     * @param string $value The IP(v4/v6) address.
     * @param string $action The action [block, challenge, whitelist, js_challenge].
     * @return mixed Bool False if request is not responded. JSON if request success.
     */
    public function createAccessRule(string $value, string $action) {
        if($this->checkIP($value) && $this->checkAccessRule($action)) {
            return $this->connect('https://api.cloudflare.com/client/v4/user/firewall/access_rules/rules', 'POST', array('mode' => $action, 'configuration' => array('target' => ($this->checkIPv4($value) ? 'ip' : ($this->checkIPv4($value) ? 'ip6' : null)), 'value' => $value), 'notes' => 'Created by CloudFirewall'));
        } else {
            return false;
        }
    }

    /**
     * Enabling block SQL injection attacks.
     *
     * @param bool $ban Default is true and it will ban from Cloudflare.
     * @return none.
     */
    public function sqlInjectionBlock(bool $ban = true) {
        foreach ($_GET as $key => $value) {
			if (is_array($value)) {
				$flattened = $this->arrayFlatten($value);
				foreach ($flattened as $sub_key => $sub_value) {
					$this->sqlCheck($sub_value, "_GET", $sub_key, $ban);
				}
			} else {
				$this->sqlCheck($value, "_GET", $key, $ban);
			}
        }
        foreach ($_POST as $key => $value) {
			if (is_array($value)) {
				$flattened = $this->arrayFlatten($value);
				foreach ($flattened as $sub_key => $sub_value) {
					$this->sqlCheck($sub_value, "_POST", $sub_key, $ban);
				}
			} else {
				$this->sqlCheck($value, "_POST", $key, $ban);
			}
        }
        foreach ($_COOKIE as $key => $value) {
			if (is_array($value)) {
				$flattened = $this->arrayFlatten($value);
				foreach ($flattened as $sub_key => $sub_value) {
					$this->sqlCheck($sub_value, "_COOKIE", $sub_key, $ban);
				}
			} else {
				$this->sqlCheck($value, "_COOKIE", $key, $ban);
			}
		}
    }

    /**
     * Enabling block XSS injection attacks.
     *
     * @param bool $ban Default is true and it will ban from Cloudflare.
     * @return none.
     */
    public function xssInjectionBlock(bool $ban = true) {
        foreach ($_GET as $key => $value) {
			if (is_array($value)) {
				$flattened = $this->arrayFlatten($value);
				foreach ($flattened as $sub_key => $sub_value) {
                    $this->xssCheck($sub_value, "GET", $sub_key, $ban);
                    $this->htmlCheck($sub_value, "GET", $sub_key, $ban);
				}
			} else {
                $this->xssCheck($value, "GET", $key, $ban);
                $this->htmlCheck($value, "GET", $key, $ban);
			}
        }
        foreach ($_POST as $key => $value) {
			if (is_array($value)) {
				$flattened = $this->arrayFlatten($value);
				foreach ($flattened as $sub_key => $sub_value) {
                    $this->xssCheck($sub_value, "POST", $sub_key, $ban);
                    $this->htmlCheck($sub_value, "POST", $sub_key, $ban);
				}
			} else {
                $this->xssCheck($value, "POST", $key, $ban);
                $this->htmlCheck($value, "POST", $key, $ban);
			}
        }
        foreach ($_COOKIE as $key => $value) {
			if (is_array($value)) {
				$flattened = $this->arrayFlatten($value);
				foreach ($flattened as $sub_key => $sub_value) {
                    $this->xssCheck($sub_value, "COOKIE", $sub_key, $ban);
                    $this->htmlCheck($sub_value, "COOKIE", $sub_key, $ban);
				}
			} else {
                $this->xssCheck($value, "COOKIE", $key, $ban);
                $this->htmlCheck($value, "COOKIE", $key, $ban);
			}
		}
    }

    /**
     * Enabling block steal cookie attacks.
     *
     * @param bool $ban Default is false and it will ban from Cloudflare.
     * @return none.
     */
    public function cookieStealBlock(bool $ban = false) {
		if (isset($_SESSION)) {
            if (!isset($_SESSION['CloudFirewall-Client-IP'])) {
                $_SESSION['CloudFirewall-Client-IP'] = $this->getIP();
            } else {
                if ($_SESSION['CloudFirewall-Client-IP'] != $this->getIP()) {
                    if($ban) {
                        $this->createAccessRule($this->getIP(), 'block');
                    }
                    session_destroy();
                    $this->abort(403, 'Cookie Stealing Detected');
                }
            }
        }
    }
    
    public function antiFlood(int $requestPerSecond = 2, int $badRequestChance = 3, int $badRequestReset = 5, bool $ban = false) {
        if(isset($_SESSION)) {
            if(!isset($_SESSION['CloudFirewall-Client-LastRequestTime']) && !isset($_SESSION['CloudFirewall-Client-BadRequest'])) {
                $_SESSION['CloudFirewall-Client-LastRequestTime'] = time();
                $_SESSION['CloudFirewall-Client-BadRequest'] = 0;
            } else {
                if(isset($_SESSION['CloudFirewall-Client-LastBadRequestTime'])) {
                    if($_SESSION['CloudFirewall-Client-LastBadRequestTime']+$badRequestReset <= time()) {
                        unset($_SESSION['CloudFirewall-Client-LastBadRequestTime']);
                        $_SESSION['CloudFirewall-Client-BadRequest'] = 0;
                    }
                }
                if($_SESSION['CloudFirewall-Client-BadRequest'] >= $badRequestChance) {
                    if($ban) {
                        $this->createAccessRule($this->getIP(), 'block');
                    }
                    $this->abort(403, 'Flood Detected');
                }
                if($_SESSION['CloudFirewall-Client-LastRequestTime'] >= time()) {
                    $_SESSION['CloudFirewall-Client-LastBadRequestTime'] = time()+$requestPerSecond;
                    $_SESSION['CloudFirewall-Client-BadRequest'] = $_SESSION['CloudFirewall-Client-BadRequest']+1;
                }
            }
            $_SESSION['CloudFirewall-Client-LastRequestTime'] = time();
        }
    }

    /**
     * Enable debug mode.
     *
     * @return none.
     */
    public function debug(bool $debug = false) {
        $this->debug = $debug;
    }

    /**
     * Get script execution time in seconds.
     *
     * @return float Script executed in X seconds.
     */
    public function benchmark() {
        return $this->benchmarkEnd($this->benchmarkStart);
    }

    private function abort(int $status, string $message = null) {
        $statusses = ['404', '403', '500'];
        if(in_array($status, $statusses)) {
            header('HTTP/1.0 '.$status.' Forbidden');
            die('<!DOCTYPE html><html lang="en"><head> <meta charset="UTF-8"> <meta name="viewport" content="width=device-width, initial-scale=1.0"> <title>'.$status.'</title> <style type="text/css">*{transition: all .6s}html{height: 100%}body{font-family: Lato, sans-serif; color: #888; margin: 0}#main{display: table; width: 100%; height: 100vh; text-align: center}.fof{display: table-cell; vertical-align: middle}h1{font-size: 50px; display: inline-block; padding-right: 12px; animation: type .5s alternate infinite}h3{font-size: 30px; padding-right: 12px; animation: type .5s alternate infinite}@keyframes type{from{box-shadow: inset -3px 0 0 #888}to{box-shadow: inset -3px 0 0 transparent}}</style></head><body><div id="main"> <div class="fof"> <h1>'.$status.'</h1>'.($message ? '<h3>'.$message.'</h3>' : '').' </div></div></body></html>');
        } else {
            return false;
        }
    }

    private function xssCheck(string $value, string $method, string $displayName, bool $ban) {
		$replace = array("<3" => ":heart:");
		foreach ($replace as $key => $value_rep) {
			$value = str_replace($key, $value_rep, $value);
		}
		$badWords = $this->getVulnTypeData('XSS');
		foreach ($badWords as $badWord) {
			if (strpos(strtolower($value), strtolower($badWord)) !== false) {
                if($ban) {
                    $this->createAccessRule($this->getIP(), 'block');
                }
                $this->abort(403, 'XSS Injection Detected');
			}
		}
	}

    private function sqlCheck(string $value, string $method, string $displayName, bool $ban) {
		$replace = array("can't" => "can not", "don't" => "do not");
		foreach ($replace as $key => $value_rep) {
			$value = str_replace($key, $value_rep, $value);
		}
		$badWords = $this->getVulnTypeData('SQL');
		foreach ($badWords as $badWord) {
			if (strpos(strtolower($value), strtolower($badWord)) !== false) {
                if($ban) {
                    $this->createAccessRule($this->getIP(), 'block');
                }
                $this->abort(403, 'SQL Injection Detected');
            }
		}
    }

    private function htmlCheck(string $value, string $method, string $displayName, bool $ban) {
		if ($this->is_html(strtolower($value)) !== false) {
            if($ban) {
                $this->createAccessRule($this->getIP(), 'block');
            }
            $this->abort(403, 'XSS Injection Detected');
		}
    }
    
    protected function is_html(string $string) {
		return ($string != strip_tags($string) ? true : false);
	}

    protected function getIP() {
        return ($_SERVER['HTTP_CF_CONNECTING_IP'] ? $_SERVER['HTTP_CF_CONNECTING_IP'] : $_SERVER['REMOTE_ADDR']);
    }

    private function arrayFlatten(array $array) {
	    $flatten = array();
	    array_walk_recursive($array, function($value) use(&$flatten) {
	        $flatten[] = $value;
	    });
	    return $flatten;
	}

    protected function getVulnTypeData(string $type) {
        if($type && in_array($type, array('SQL', 'XSS'))) {
            $vuln['SQL'] = array(
                "'",
                '´',
                'SELECT FROM',
                'SELECT * FROM',
                'ONION',
                'union',
                'UNION',
                'TRUNCATE TABLE',
                'INSERT INTO',
                'UDPATE users SET',
                'WHERE username',
                'DROP TABLE',
                '0x50',
                'mid((select',
                'union(((((((',
                'concat(0x',
                'concat(',
                'OR boolean',
                'or HAVING',
                "OR '1",
                '0x3c62723e3c62723e3c62723e',
                '0x3c696d67207372633d22',
                '+#1q%0AuNiOn all#qa%0A#%0AsEleCt',
                'unhex(hex(Concat(',
                'Table_schema,0x3e,',
                '0x00',
                '0x08',
                '0x09',
                '0x0a',
                '0x0d',
                '0x1a',
                '0x22',
                '0x25',
                '0x27',
                '0x5c',
                '0x5f'
            );
            $vuln['XSS'] = array('<img',
                'img>',
                'style=',
                '<table',
                '<td',
                '<tr',
                '<b',
                '<font',
                '<br',
                '</br',
                '<a ',
                'href',
                '<h',
                '<image',
                'document.cookie',
                'onerror()',
                'script>',
                '<script',
                'alert(',
                'window.',
                'String.fromCharCode(',
                'javascript:',
                'onmouseover="',
                '<BODY onload',
                '<style',
                'svg onload',
                'onclick='
            );
            return $vuln[$type];
        } else {
            return false;
        }
    }

    protected function connect(string $url, string $request, array $fields) {
        $this->curl = curl_init();
        curl_setopt($this->curl, CURLOPT_URL, $url);
        curl_setopt($this->curl, CURLOPT_RETURNTRANSFER, 1);
        curl_setopt($this->curl, CURLOPT_FOLLOWLOCATION, 1);
        curl_setopt($this->curl, CURLOPT_SSL_VERIFYPEER, false);
        if($request == 'POST') {
            curl_setopt($this->curl, CURLOPT_POST, 1);
        }else{
            curl_setopt($this->curl, CURLOPT_CUSTOMREQUEST, $request);
        }
        curl_setopt($this->curl, CURLOPT_POSTFIELDS, json_encode($fields));
        curl_setopt($this->curl, CURLOPT_HTTPHEADER, array('X-Auth-Email: '.$this->email, 'X-Auth-Key: '.$this->key, 'Content-Type: application/json'));
        return curl_exec($this->curl);
    }

    protected function checkSecurityLevel(string $value) {
        return (in_array($value, array('essentially_off', 'low', 'medium', 'high', 'under_attack'))) ? true : false;
    }
    protected function checkAccessRule(string $value) {
        return (in_array($value, array('block', 'challenge', 'whitelist', 'js_challenge'))) ? true : false;
    }

    protected function checkIPv4(string $value) {
        return (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) ? true : false;
    }

    protected function checkIPv6(string $value) {
        return (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) ? true : false;
    }

    protected function checkIP(string $value) {
        return (filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) || filter_var($value, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) ? true : false;
    }

    private function benchmarkStart() {
        $r = explode(' ', microtime());
        $r = $r[1] + $r[0];
        return $r;
    }

    private function benchmarkEnd($startTime) {
        $r = explode(' ', microtime());
        $r = $r[1] + $r[0];
        $r = round($r - $startTime, 4);
        return $r;
    }

}
?>