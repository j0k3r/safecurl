<?php

namespace fin1te\SafeCurl;

class SafeCurl
{
    /**
     * cURL Handle.
     *
     * @var resource
     */
    private $curlHandle;

    /**
     * SafeCurl Options.
     *
     * @var SafeCurl\Options
     */
    private $options;

    /**
     * Returns new instance of SafeCurl\SafeCurl.
     *
     * @param $curlHandle resource         A valid cURL handle
     * @param $options    SafeCurl\Options optional
     */
    public function __construct($curlHandle, Options $options = null)
    {
        $this->setCurlHandle($curlHandle);

        if ($options === null) {
            $options = new Options();
        }

        $this->setOptions($options);
        $this->init();
    }

    /**
     * Returns cURL handle.
     *
     * @return resource
     */
    public function getCurlHandle()
    {
        return $this->curlHandle;
    }

    /**
     * Sets cURL handle.
     *
     * @param $curlHandle resource
     */
    public function setCurlHandle($curlHandle)
    {
        if (!is_resource($curlHandle) || get_resource_type($curlHandle) != 'curl') {
            //Need a valid cURL resource, throw exception
            throw new Exception('SafeCurl expects a valid cURL resource - "'.gettype($curlHandle).'" provided.');
        }

        $this->curlHandle = $curlHandle;
    }

    /**
     * Gets Options.
     *
     * @return SafeCurl\Options
     */
    public function getOptions()
    {
        return $this->options;
    }

    /**
     * Sets Options.
     *
     * @param $options SafeCurl\Options
     */
    public function setOptions(Options $options)
    {
        $this->options = $options;
    }

    /**
     * Sets up cURL ready for executing.
     */
    protected function init()
    {
        //To start with, disable FOLLOWLOCATION since we'll handle it
        curl_setopt($this->curlHandle, CURLOPT_FOLLOWLOCATION, false);

        //Always return the transfer
        curl_setopt($this->curlHandle, CURLOPT_RETURNTRANSFER, true);

        //Force IPv4, since this class isn't yet comptible with IPv6
        $curlVersion = curl_version();

        if ($curlVersion['features'] & CURLOPT_IPRESOLVE) {
            curl_setopt($this->curlHandle, CURLOPT_IPRESOLVE, CURL_IPRESOLVE_V4);
        }
    }

    /**
     * Exectutes a cURL request, whilst checking that the
     * URL abides by our whitelists/blacklists.
     *
     * @param $url        string
     *
     * @return bool
     */
    public function execute($url)
    {
        //Backup the existing URL
        $originalUrl = $url;

        $redirected = false;
        $redirectCount = 0;
        $redirectLimit = $this->getOptions()->getFollowLocationLimit();

        do {
            //Validate the URL
            $url = Url::validateUrl($url, $this->getOptions());

            if ($this->getOptions()->getPinDns()) {
                //Send a Host header
                curl_setopt($this->curlHandle, CURLOPT_HTTPHEADER, array('Host: '.$url['host']));
                //The "fake" URL
                curl_setopt($this->curlHandle, CURLOPT_URL, $url['url']);
                //We also have to disable SSL cert verfication, which is not great
                //Might be possible to manually check the certificate ourselves?
                curl_setopt($this->curlHandle, CURLOPT_SSL_VERIFYPEER, false);
            } else {
                curl_setopt($this->curlHandle, CURLOPT_URL, $url['url']);
            }

            // in case of `CURLINFO_REDIRECT_URL` isn't defined
            curl_setopt($this->curlHandle, CURLOPT_HEADER, true);

            //Execute the cURL request
            $response = curl_exec($this->curlHandle);

            //Check for any errors
            if (curl_errno($this->curlHandle)) {
                throw new Exception('cURL Error: '.curl_error($this->curlHandle));
            }

            //Check for an HTTP redirect
            if ($this->getOptions()->getFollowLocation()) {
                $statusCode = curl_getinfo($this->curlHandle, CURLINFO_HTTP_CODE);
                switch ($statusCode) {
                    case 301:
                    case 302:
                    case 303:
                    case 307:
                    case 308:
                        //Redirect received, so rinse and repeat
                        if ($redirectLimit == 0 || ++$redirectCount < $redirectLimit) {
                            // `CURLINFO_REDIRECT_URL` was introduced in 5.3.7 & it doesn't exist in HHVM
                            // use a custom solution is that both case
                            if (defined('CURLINFO_REDIRECT_URL')) {
                                $url = curl_getinfo($this->curlHandle, CURLINFO_REDIRECT_URL);
                            } else {
                                preg_match('/Location:(.*?)\n/i', $response, $matches);
                                $url = trim(array_pop($matches));
                            }

                            $redirected = true;
                        } else {
                            throw new Exception('Redirect limit "'.$redirectLimit.'" hit');
                        }
                        break;
                    default:
                        $redirected = false;
                }
            }
        } while ($redirected);

        return $response;
    }
}
