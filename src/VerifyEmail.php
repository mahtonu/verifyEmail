<?php

declare(strict_types=1);

namespace hbattat;

use DOMDocument;
use DOMXPath;

/**
 *  Verifies email address by attempting to connect and check with the mail server of that account
 *
 *  Author: Sam Battat hbattat@msn.com
 *          http://github.com/hbattat
 *
 *  License: This code is released under the MIT Open Source License. (Feel free to do whatever)
 *
 *  Requires PHP 8.1+ (tested on PHP 8.5)
 *
 * @package VerifyEmail
 * @author  Husam (Sam) Battat <hbattat@msn.com>
 * This is a test message for packagist
 */

class VerifyEmail
{
  public ?string $email = null;
  public ?string $verifier_email = null;
  public int $port = 25;
  private string|false $mx = false;
  /** @var resource|false */
  private $connect = false;
  private array $errors = [];
  private array $debug = [];
  private array $debug_raw = [];

  private string $_yahoo_signup_page_url = 'https://login.yahoo.com/account/create?specId=yidReg&lang=en-US&src=&done=https%3A%2F%2Fwww.yahoo.com&display=login';
  private string $_yahoo_signup_ajax_url = 'https://login.yahoo.com/account/module/create?validateField=yid';
  private array $_yahoo_domains = ['yahoo.com'];
  private string $_hotmail_signin_page_url = 'https://login.live.com/';
  private string $_hotmail_username_check_url = 'https://login.live.com/GetCredentialType.srf?wa=wsignin1.0';
  private array $_hotmail_domains = ['hotmail.com', 'live.com', 'outlook.com', 'msn.com'];
  private string|false $page_content = false;
  private array $page_headers = [];

  public function __construct(?string $email = null, ?string $verifier_email = null, int $port = 25)
  {
    if (!is_null($email) && !is_null($verifier_email)) {
      $this->debug[] = 'Initialized with Email: ' . $email . ', Verifier Email: ' . $verifier_email . ', Port: ' . $port;
      $this->set_email($email);
      $this->set_verifier_email($verifier_email);
    } else {
      $this->debug[] = 'Initialized with no email or verifier email values';
    }
    $this->set_port($port);
  }


  public function set_verifier_email(string $email): void
  {
    $this->verifier_email = $email;
    $this->debug[] = 'Verifier Email was set to ' . $email;
  }

  public function get_verifier_email(): ?string
  {
    return $this->verifier_email;
  }


  public function set_email(string $email): void
  {
    $this->email = $email;
    $this->debug[] = 'Email was set to ' . $email;
  }

  public function get_email(): ?string
  {
    return $this->email;
  }

  public function set_port(int $port): void
  {
    $this->port = $port;
    $this->debug[] = 'Port was set to ' . $port;
  }

  public function get_port(): int
  {
    return $this->port;
  }

  public function get_errors(): array
  {
    return ['errors' => $this->errors];
  }

  public function get_debug(bool $raw = false): array
  {
    if ($raw) {
      return $this->debug_raw;
    } else {
      return $this->debug;
    }
  }

  public function verify(): bool
  {
    $this->debug[] = 'Verify function was called.';

    $is_valid = false;

    //check if this is a yahoo email
    $domain = $this->get_domain($this->email);
    // if(in_array(strtolower($domain), $this->_yahoo_domains)) {
    //   $is_valid = $this->validate_yahoo();
    // }
    // else if(in_array(strtolower($domain), $this->_hotmail_domains)){
    //   $is_valid = $this->validate_hotmail();
    // }
    //otherwise check the normal way
    //else {
    //find mx
    $this->debug[] = 'Finding MX record...';
    $this->find_mx();

    if (!$this->mx) {
      $this->debug[] = 'No MX record was found.';
      $this->add_error('100', '', 'No suitable MX records found.');
      return $is_valid;
    } else {
      $this->debug[] = 'Found MX: ' . $this->mx;
    }


    $this->debug[] = 'Connecting to the server...';
    $this->connect_mx();

    if (!$this->connect) {
      $this->debug[] = 'Connection to server failed.';
      $this->add_error('110', '', 'Could not connect to the server.');
      return $is_valid;
    } else {
      $this->debug[] = 'Connection to server was successful.';
    }


    $this->debug[] = 'Starting verification...';
    $out = $this->read_line();
    if (preg_match("/^220/i", $out)) {
      $this->debug[] = 'Got a 220 response. Sending HELO...';
      $this->write_line("HELO " . $this->get_domain($this->verifier_email) . "\r\n");
      $out = $this->read_line();
      $this->debug_raw['helo'] = $out;
      $this->debug[] = 'Response: ' . $out;
      if (!preg_match("/^2[0-9]{2}/", $out)) {
        $this->add_response_error($out);
        $this->debug[] = 'Not found! Email is invalid.';
        $this->close_connection();
        return false;
      }

      $this->debug[] = 'Sending MAIL FROM...';
      if (!$this->write_line("MAIL FROM: <" . $this->verifier_email . ">\r\n")) {
        // Handle the broken pipe error here
        $errorMessage = error_get_last()['message'] ?? 'Could not write to the server (broken pipe).';
        $this->debug_raw['mail_from'] = $errorMessage;
        $this->debug[] = 'Response: ' . $errorMessage;
        $this->close_connection();
        return false;
      }
      $from = $this->read_line();
      $this->debug_raw['mail_from'] = $from;
      $this->debug[] = 'Response: ' . $from;

      $this->debug[] = 'Sending RCPT TO...';
      $this->write_line("RCPT TO: <" . $this->email . ">\r\n");
      $to = $this->read_line();
      $this->debug_raw['rcpt_to'] = $to;
      $this->debug[] = 'Response: ' . $to;

      $this->debug[] = 'Sending QUIT...';
      $quit = $this->write_line("QUIT\r\n");
      $this->debug_raw['quit'] = $quit;
      $this->close_connection();

      $this->debug[] = 'Looking for 250 response...';
      if (!preg_match("/^2[0-9]{2}/", $from) || !preg_match("/^2[0-9]{2}/", $to)) {
        if (!preg_match("/^2[0-9]{2}/", $from)) {
          $this->add_response_error($from);
        }
        if (!preg_match("/^2[0-9]{2}/", $to)) {
          $this->add_response_error($to);
        }
        $this->debug[] = 'Not found! Email is invalid.';
        $is_valid = false;
      } else {
        $this->debug[] = 'Found! Email is valid.';
        $is_valid = true;
      }
    } else {
      $this->add_response_error($out);
      $this->debug[] = 'Encountered an unknown response code.';
      $this->close_connection();
    }
    //}

    return $is_valid;
  }

  private function get_domain(?string $email): string
  {
    $email_arr = explode('@', (string) $email);
    return end($email_arr);
  }

  private function find_mx(): void
  {
    $domain = $this->get_domain($this->email);
    $mx_ip = false;
    // Trim [ and ] from beginning and end of domain string, respectively
    $domain = ltrim($domain, '[');
    $domain = rtrim($domain, ']');

    if (str_starts_with($domain, 'IPv6:')) {
      $domain = substr($domain, strlen('IPv6:'));
    }

    $mxhosts = [];
    $mxweight = [];
    if (filter_var($domain, FILTER_VALIDATE_IP)) {
      $mx_ip = $domain;
    } elseif ($domain !== '') {
      getmxrr($domain, $mxhosts, $mxweight);
    }

    if (!empty($mxhosts)) {
      $mx_ip = $mxhosts[array_search(min($mxweight), $mxweight)];
    } else {
      $record_a = [];
      if (filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
        $record_a = dns_get_record($domain, DNS_A);
      } elseif (filter_var($domain, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        $record_a = dns_get_record($domain, DNS_AAAA);
      }

      if (!empty($record_a)) {
        $mx_ip = $record_a[0]['ip'] ?? $record_a[0]['ipv6'] ?? $mx_ip;
      }
    }

    $this->mx = $mx_ip;
  }


  private function connect_mx(): void
  {
    //connect
    $this->connect = @fsockopen($this->mx, $this->port);
  }

  private function read_line(): string
  {
    $line = $this->connect ? fgets($this->connect) : false;
    return $line === false ? '' : $line;
  }

  private function write_line(string $data): int|false
  {
    return $this->connect ? @fwrite($this->connect, $data) : false;
  }

  private function close_connection(): void
  {
    if (is_resource($this->connect)) {
      fclose($this->connect);
    }
    $this->connect = false;
  }

  private function add_response_error(string $response): void
  {
    preg_match('!\d+!', $response, $matches);
    // Extract reply code that starts with a single digit and has the format of X.X.X or X.X.XX
    preg_match('/\b\d{1}\.\d{1}\.\d{1,2}\b/', $response, $sMatches);
    $code = $matches[0] ?? '';
    $reply_code = $sMatches[0] ?? '';
    $this->add_error($code, $reply_code, $response !== '' ? $response : 'No response from the server.');
  }

  private function add_error(string $code, string $reply_code, string $msg): void
  {
    $this->errors[] = ['code' => $code, 'reply_code' => $reply_code, 'message' => $msg];
  }

  private function clear_errors(): void
  {
    $this->errors = [];
  }

  private function validate_yahoo(): bool
  {
    $this->debug[] = 'Validating a yahoo email address...';
    $this->debug[] = 'Getting the sign up page content...';
    $this->fetch_page('yahoo');

    $cookies = $this->get_cookies();
    $fields = $this->get_fields();

    $this->debug[] = 'Adding the email to fields...';
    $fields['yid'] = str_replace('@yahoo.com', '', strtolower((string) $this->email));

    $this->debug[] = 'Ready to submit the POST request to validate the email.';

    $response = $this->request_validation('yahoo', $cookies, $fields);
    $response = json_decode((string) $response, true);
    if (empty($response['errors'])) {
      return true;
    }

    $this->debug[] = 'Parsing the response...';
    $response_errors = is_array($response['errors']) ? $response['errors'] : [];

    $this->debug[] = 'Searching errors for exisiting username error...';
    foreach ($response_errors as $err) {
      if (($err['name'] ?? null) == 'yid' && ($err['error'] ?? null) == 'IDENTIFIER_EXISTS') {
        $this->debug[] = 'Found an error about exisiting email.';
        return true;
      }
    }
    return false;
  }

  private function validate_hotmail(): bool
  {
    $this->debug[] = 'Validating a hotmail email address...';
    $this->debug[] = 'Getting the sign up page content...';
    $this->fetch_page('hotmail');

    $cookies = $this->get_cookies();

    $this->debug[] = 'Sending another request to get the needed cookies for validation...';
    $this->fetch_page('hotmail', implode(' ', $cookies));
    $cookies = $this->get_cookies();

    $this->debug[] = 'Preparing fields...';
    $fields = $this->prep_hotmail_fields($cookies);

    $this->debug[] = 'Ready to submit the POST request to validate the email.';
    $response = $this->request_validation('hotmail', $cookies, $fields);

    $this->debug[] = 'Searching username error...';
    $json_response = json_decode((string) $response, true);
    $this->debug[] = print_r($json_response, true);

    if (!empty($json_response['IfExistsResult'])) {
      return true;
    }
    return false;
  }

  private function fetch_page(string $service, string $cookies = ''): void
  {
    $context = null;
    if ($cookies) {
      $opts = [
        'http' => [
          'method' => "GET",
          'header' => "Accept-language: en\r\n" .
            "Cookie: " . $cookies . "\r\n"
        ]
      ];
      $context = stream_context_create($opts);
    }

    $this->page_content = false;
    if ($service == 'yahoo') {
      $this->page_content = @file_get_contents($this->_yahoo_signup_page_url, false, $context);
    } else if ($service == 'hotmail') {
      $this->page_content = @file_get_contents($this->_hotmail_signin_page_url, false, $context);
    }

    if ($this->page_content === false) {
      $this->debug[] = 'Could not read the sign up page.';
      $this->add_error('200', '', 'Cannot not load the sign up page.');
      $this->page_headers = [];
    } else {
      $this->debug[] = 'Sign up page content stored.';
      $this->debug[] = 'Getting headers...';
      $this->page_headers = http_get_last_response_headers() ?? [];
      $this->debug[] = 'Sign up page headers stored.';
    }
  }

  private function get_cookies(): array
  {
    $this->debug[] = 'Attempting to get the cookies from the sign up page...';
    if ($this->page_content !== false) {
      $this->debug[] = 'Extracting cookies from headers...';
      $cookies = [];
      foreach ($this->page_headers as $hdr) {
        if (preg_match('/^Set-Cookie:\s*(.*?;).*?$/i', $hdr, $matches)) {
          $cookies[] = $matches[1];
        }
      }

      if (count($cookies) > 0) {
        $this->debug[] = 'Cookies found: ' . implode(' ', $cookies);
        return $cookies;
      } else {
        $this->debug[] = 'Could not find any cookies.';
      }
    }
    return [];
  }

  private function get_fields(): array
  {
    $fields = [];
    if ($this->page_content === false || $this->page_content === '') {
      $this->debug[] = 'Something is worng with the page HTML.';
      $this->add_error('210', '', 'Could not load the dom HTML.');
      return $fields;
    }

    $dom = new DOMDocument();
    if (@$dom->loadHTML($this->page_content)) {
      $this->debug[] = 'Parsing the page for input fields...';
      $xp = new DOMXPath($dom);
      $nodes = $xp->query('//input');
      foreach ($nodes as $node) {
        $fields[$node->getAttribute('name')] = $node->getAttribute('value');
      }

      $this->debug[] = 'Extracted fields.';
    } else {
      $this->debug[] = 'Something is worng with the page HTML.';
      $this->add_error('210', '', 'Could not load the dom HTML.');
    }
    return $fields;
  }

  private function request_validation(string $service, array $cookies, array $fields): string|false
  {
    $result = false;
    if ($service == 'yahoo') {
      $headers = [];
      $headers[] = 'Origin: https://login.yahoo.com';
      $headers[] = 'X-Requested-With: XMLHttpRequest';
      $headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_11_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/54.0.2840.71 Safari/537.36';
      $headers[] = 'content-type: application/x-www-form-urlencoded; charset=UTF-8';
      $headers[] = 'Accept: */*';
      $headers[] = 'Referer: https://login.yahoo.com/account/create?specId=yidReg&lang=en-US&src=&done=https%3A%2F%2Fwww.yahoo.com&display=login';
      $headers[] = 'Accept-Encoding: gzip, deflate, br';
      $headers[] = 'Accept-Language: en-US,en;q=0.8,ar;q=0.6';

      $cookies_str = implode(' ', $cookies);
      $headers[] = 'Cookie: ' . $cookies_str;


      $postdata = http_build_query($fields);

      $opts = [
        'http' =>
        [
          'method'  => 'POST',
          'header'  => $headers,
          'content' => $postdata
        ]
      ];

      $context  = stream_context_create($opts);
      $result = @file_get_contents($this->_yahoo_signup_ajax_url, false, $context);
    } else if ($service == 'hotmail') {
      $headers = [];
      $headers[] = 'Origin: https://login.live.com';
      $headers[] = 'hpgid: 33';
      $headers[] = 'User-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_12_6) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/61.0.3163.100 Safari/537.36';
      $headers[] = 'Content-type: application/json; charset=UTF-8';
      $headers[] = 'Accept: application/json';
      $headers[] = 'Referer: https://login.live.com';
      $headers[] = 'Accept-Encoding: gzip, deflate, br';
      $headers[] = 'Accept-Language: en-US,en;q=0.8,ar;q=0.6';

      $cookies_str = implode(' ', $cookies);
      $headers[] = 'Cookie: ' . $cookies_str;

      $postdata = json_encode($fields);

      $opts = [
        'http' =>
        [
          'method'  => 'POST',
          'header'  => $headers,
          'content' => $postdata
        ]
      ];
      $this->debug[] = print_r($opts, true);
      $context  = stream_context_create($opts);
      $result = @file_get_contents($this->_hotmail_username_check_url, false, $context);
    }
    return $result;
  }

  private function prep_hotmail_fields(array $cookies): array
  {
    $fields = [];
    foreach ($cookies as $cookie) {
      $parts = explode('=', $cookie, 2);
      if (count($parts) === 2 && $parts[0] == 'uaid') {
        $fields['uaid'] = $parts[1];
        break;
      }
    }
    $fields['username'] = strtolower((string) $this->email);
    return $fields;
  }
}
