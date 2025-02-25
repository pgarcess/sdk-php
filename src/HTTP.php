<?php

/**
 * @copyright Copyright (c) 2020 Afterpay Limited Group
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *     http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace Afterpay\SDK;

use Afterpay\SDK\Exception\InvalidArgumentException;
use Afterpay\SDK\Exception\ParsingException;

class HTTP
{
    /**
     * @var bool $logObfuscationEnabled
     */
    private static $logObfuscationEnabled = true;

    /**
     * @var string $merchantId
     */
    private static $merchantId;

    /**
     * @var string $secretKey
     */
    private static $secretKey;

    /**
     * @var string $countryCode
     */
    private static $countryCode;

    /**
     * @var string $apiEnvironment
     */
    private static $apiEnvironment;

    /**
     * @return bool
     */
    public static function getLogObfuscationEnabled()
    {
        return self::$logObfuscationEnabled;
    }

    /**
     * @return string
     */
    public static function getMerchantId()
    {
        return self::$merchantId;
    }

    /**
     * @param string $merchantId
     */
    public static function setMerchantId($merchantId)
    {
        self::$merchantId = $merchantId;
    }

    /**
     * @return string
     */
    public static function getSecretKey()
    {
        return self::$secretKey;
    }

    /**
     * @param string $secretKey
     */
    public static function setSecretKey($secretKey)
    {
        self::$secretKey = $secretKey;
    }

    /**
     * @return string
     */
    public static function getCountryCode()
    {
        return self::$countryCode;
    }

    /**
     * @param string $countryCode
     */
    public static function setCountryCode($countryCode)
    {
        self::$countryCode = $countryCode;
    }

    /**
     * @return string
     */
    public static function getApiEnvironment()
    {
        return self::$apiEnvironment;
    }

    /**
     * @param string $apiEnvironment
     * @throws \Afterpay\SDK\Exception\InvalidArgumentException
     */
    public static function setApiEnvironment($apiEnvironment)
    {
        /**
         * @todo Reuse the enumi rules in the Config class instead defining duplicate code here
         *       and in \Afterpay\SDK\MerchantAccount.
         */
        if (! is_string($apiEnvironment)) {
            throw new InvalidArgumentException("Expected string; " . gettype($apiEnvironment) . ' given');
        } elseif (! preg_match('/^sandbox|production$/i', $apiEnvironment)) {
            throw new InvalidArgumentException("Expected 'sandbox' or 'production'; '{$apiEnvironment}' given");
        }

        self::$apiEnvironment = $apiEnvironment;
    }

    /**
     * @var string $http_version
     */
    protected $http_version;

    /**
     * @var string $content_type
     */
    protected $content_type;

    /**
     * @var string $raw_headers
     */
    protected $raw_headers;

    /**
     * @var array $parsed_headers
     */
    protected $parsed_headers;

    /**
     * @var string $raw_body
     */
    protected $raw_body;

    /**
     * @var mixed $parsed_body
     */
    protected $parsed_body;

    /**
     * @param string $str
     * @return string
     */
    protected function maybeObfuscate($str)
    {
        if (self::getLogObfuscationEnabled()) {
            # Merchant API Credentials
            $str = preg_replace_callback('/(Authorization: Basic )(.{3})(.*)([^\s]{3})/i', function ($matches) {
                return
                    $matches[1]
                    . $matches[2]
                    . str_repeat('*', strlen($matches[3]))
                    . $matches[4];
            }, $str);
            $str = preg_replace_callback('/(User-Agent:.*Merchant\/)([0-9a-zA-Z]+)(.*)/i', function ($matches) {
                return
                    $matches[1]
                    . str_repeat('*', strlen($matches[2]))
                    . $matches[3];
            }, $str);

            # Consumer / Contact attributes
            $str = preg_replace_callback('/(")(phoneNumber|givenNames|surname|email|name|line[12]|area[12]|region|postcode)(":")([^"]+)(")/i', function ($matches) {
                return
                    $matches[1]
                    . $matches[2]
                    . $matches[3]
                    . preg_replace('/[^\s]/', '*', $matches[4])
                    . $matches[5];
            }, $str);
        }

        return $str;
    }

    public function __construct()
    {
    }

    /**
     * @return string
     */
    public function getContentType()
    {
        return $this->content_type;
    }

    /**
     * Get the simplified Content-Type value minus any additional detail.
     *
     * For example:
     *     - "text/html; charset=UTF-8"         --> "text/html"
     *     - "text/plain;charset=iso-8859-1"    --> "text/plain"
     *
     * @return string
     */
    public function getContentTypeSimplified()
    {
        return preg_replace('/;.*$/', '', $this->getContentType());
    }

    /**
     * @param string $content_type
     * @return \Afterpay\SDK\HTTP
     * @throws \Afterpay\SDK\Exception\InvalidArgumentException
     */
    public function setContentType($content_type)
    {
        if ($content_type === false || is_null($content_type)) {
            # The API sometimes incorrectly omits the Content-Type header.
            # If this happens, we will try to guess the content type from the body.
            # This occurs inside self::parseRawBody.

            $content_type = null;
        } elseif (! is_string($content_type)) {
            throw new InvalidArgumentException('Expected string; ' . gettype($content_type) . ' given');
        }

        $this->content_type = $content_type;

        return $this;
    }

    /**
     * @return string
     */
    public function getRawHeaders()
    {
        return $this->raw_headers;
    }

    /**
     * @param string $raw_headers
     * @return \Afterpay\SDK\HTTP
     * @throws \Afterpay\SDK\Exception\InvalidArgumentException
     */
    public function setRawHeaders($raw_headers)
    {
        if ($raw_headers === false) {
            # Probably a network error.

            $raw_headers = null;
        } elseif (! is_string($raw_headers)) {
            throw new InvalidArgumentException('Expected string; ' . gettype($raw_headers) . ' given');
        }

        $this->raw_headers = $raw_headers;

        $this->parseRawHeaders();

        return $this;
    }

    /**
     * @return array
     */
    public function getParsedHeaders()
    {
        return $this->parsed_headers;
    }

    /**
     * @return string
     */
    public function getRawBody()
    {
        return $this->raw_body;
    }

    /**
     * @param string $raw_body
     * @return \Afterpay\SDK\HTTP
     * @throws \Afterpay\SDK\Exception\InvalidArgumentException
     */
    public function setRawBody($raw_body)
    {
        if (! is_string($raw_body)) {
            throw new InvalidArgumentException('Expected string; ' . gettype($raw_body) . ' given');
        }

        $this->raw_body = $raw_body;

        $this->parseRawBody();

        return $this;
    }

    /**
     * @return mixed
     */
    public function getParsedBody()
    {
        return $this->parsed_body;
    }

    /**
     *
     */
    public function getRaw()
    {
        $str = $this->raw_headers;

        if (! is_null($this->raw_body)) {
            $str .= $this->raw_body;
        }

        return $str;
    }

    public function isJson()
    {
        if (preg_match('/^application\/json/i', $this->content_type)) {
            return true;
        }

        return false;
    }

    /**
     *
     */
    public function parseRawHeaders()
    {
        $headers_arr = explode("\n", $this->raw_headers);
        $matches = [];

        if (preg_match('/\bHTTP\/([0-9.]+)\b/i', array_shift($headers_arr), $matches)) {
            $this->http_version = $matches[ 1 ];
        } else {
            $this->http_version = 'Unknown';
        }

        $this->parsed_headers = [];

        foreach ($headers_arr as $header) {
            $first_colon = strpos($header, ':');
            if ($first_colon !== false) {
                $key = substr($header, 0, $first_colon);
                $value = substr($header, $first_colon + 1);

                $this->parsed_headers[ strtolower(trim($key)) ] = trim($value);
            }
        }
    }

    /**
     * @throws \Afterpay\SDK\Exception\ParsingException
     */
    public function parseRawBody()
    {
        if ($this->raw_body) {
            if ($this->isJson() || is_null($this->getContentType())) {
                $this->parsed_body = json_decode($this->raw_body);

                if ($this->isJson() && is_null($this->parsed_body)) {
                    throw new ParsingException(json_last_error_msg(), json_last_error());
                }
            }
        }
    }
}
