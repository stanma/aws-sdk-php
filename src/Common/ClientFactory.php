<?php
namespace Aws\Common;

use InvalidArgumentException as IAE;
use Aws\Common\Api\FilesystemApiProvider;
use Aws\Common\Api\Service;
use Aws\Common\Api\Validator;
use Aws\Common\Credentials\Credentials;
use Aws\Common\Credentials\CredentialsInterface;
use Aws\Common\Credentials\NullCredentials;
use Aws\Common\Credentials\Provider;
use Aws\Common\Retry\ThrottlingFilter;
use Aws\Common\Signature\SignatureInterface;
use Aws\Common\Signature\SignatureV2;
use Aws\Common\Signature\SignatureV3Https;
use Aws\Common\Signature\SignatureV4;
use Aws\Common\Subscriber\Signature;
use Aws\Common\Subscriber\Validation;
use Aws\Sdk;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Command\Event\ProcessEvent;
use GuzzleHttp\Subscriber\Log\SimpleLogger;
use GuzzleHttp\Subscriber\Retry\RetrySubscriber;
use GuzzleHttp\Command\Subscriber\Debug;

/**
 * @internal Default factory class used to create clients.
 */
class ClientFactory
{
    private $validArguments = [
        'key' => ['type' => 'deprecated'],
        'ssl.certificate_authority' => ['type' => 'deprecated'],
        'curl.options' => ['type' => 'deprecated'],
        'scheme' => [
            'type'     => 'value',
            'default'  => 'https',
            'required' => true
        ],
        'region' => [
            'type'     => 'value',
            'required' => true,
            'default'  => true
        ],
        'service' => ['type' => 'value', 'required' => true],
        'endpoint' => ['type' => 'value'],
        'version' => ['type' => 'value', 'required' => true],
        'defaults' => ['type' => 'value'],
        'endpoint_provider' => ['type' => 'pre', 'required' => true],
        'api_provider' => ['type' => 'pre', 'required' => true],
        'class_name' => ['type' => 'pre', 'default' => true],
        'profile' => ['type' => 'pre'],
        'credentials' => ['type' => 'pre', 'default' => true],
        'signature' => ['type' => 'pre', 'default' => false],
        'client' => ['type' => 'pre', 'default' => true],
        'ringphp_handler' => ['type' => 'pre'],
        'retries' => ['type' => 'post', 'default' => true],
        'validate' => ['type' => 'post', 'default' => true],
        'debug' => ['type' => 'post'],
        'client_defaults' => ['type' => 'post'],
    ];

    /**
     * Constructs a new factory object used for building services.
     *
     * @param array $args
     *
     * @return \Aws\Common\AwsClientInterface
     * @throws \InvalidArgumentException
     * @see Aws\Sdk::getClient() for a list of available options.
     */
    public function create(array $args = [])
    {
        $post = [];
        $this->addDefaultArgs($args);

        foreach ($this->validArguments as $key => $a) {
            if (!array_key_exists($key, $args)) {
                if (isset($a['default'])) {
                    // Merge defaults in when not present.
                    $args[$key] = $a['default'];
                } elseif (!empty($a['required'])) {
                    throw new IAE("{$key} is a required client setting");
                } else {
                    continue;
                }
            }
            if ($a['type'] === 'pre') {
                $this->{"handle_{$key}"}($args[$key], $args);
            } elseif ($a['type'] === 'post') {
                $post[$key] = $args[$key];
            } elseif ($a['type'] === 'deprecated') {
                $meth = 'deprecated_' . str_replace('.', '_', $key);
                $this->{$meth}($args[$key], $args);
            }
        }

        // Create the client and then handle deferred and post-create logic
        $client = $this->createClient($args);
        foreach ($post as $key => $value) {
            $this->{"handle_{$key}"}($value, $args, $client);
        }

        $this->postCreate($client, $args);

        return $client;
    }

    /**
     * Creates a client for the given arguments.
     *
     * This method can be overridden in subclasses as needed.
     *
     * @param array $args Arguments to provide to the client.
     *
     * @return AwsClientInterface
     */
    protected function createClient(array $args)
    {
        return new $args['client_class']($args);
    }

    /**
     * Apply default option arguments.
     *
     * @param array $args Arguments passed by reference
     */
    protected function addDefaultArgs(&$args)
    {
        if (!isset($args['client'])) {
            $clientArgs = [];
            if (isset($args['ringphp_handler'])) {
                $clientArgs['handler'] = $args['ringphp_handler'];
                unset($args['ringphp_handler']);
            }
            $args['client'] = new Client($clientArgs);
        }

        if (!isset($args['api_provider'])) {
            $args['api_provider'] = new FilesystemApiProvider(
                __DIR__ . '/Resources/api'
            );
        }

        if (!isset($args['endpoint_provider'])) {
            $args['endpoint_provider'] = RulesEndpointProvider::fromDefaults();
        }
    }

    /**
     * Applies the appropriate retry subscriber.
     *
     * This may be extended in subclasses.
     *
     * @param int|bool           $value  User-provided value (must be validated)
     * @param array              $args   Provided arguments reference
     * @param AwsClientInterface $client Client to modify
     * @throws \InvalidArgumentException if the value provided is invalid.
     */
    private function handle_retries(
        $value,
        array &$args,
        AwsClientInterface $client
    ) {
        if (!$value) {
            return;
        }

        $conf = $this->getRetryOptions($args);

        if (is_int($value)) {
            // Overwrite the max, if a retry value was provided.
            $conf['max'] = $value;
        } elseif ($value !== true) {
            // If retry value was not an int or bool, throw an exception.
            throw new IAE('retries must be a boolean or an integer');
        }

        // Add retry logger
        if (isset($args['retry_logger'])) {
            $conf['delay'] = RetrySubscriber::createLoggingDelay(
                $conf['delay'],
                ($args['retry_logger'] === 'debug')
                    ? new SimpleLogger()
                    : $args['retry_logger']
            );
        }

        $retry = new RetrySubscriber($conf);
        $client->getHttpClient()->getEmitter()->attach($retry);
    }

    /**
     * Gets the options for use with the RetrySubscriber.
     *
     * This method can be overwritten by service-specific factories to easily
     * change the options to suit the service's needs.
     *
     * @param array $args Factory args
     *
     * @return array
     */
    protected function getRetryOptions(array $args)
    {
        return [
            'max' => 3,
            'delay' => ['GuzzleHttp\Subscriber\Retry\RetrySubscriber', 'exponentialDelay'],
            'filter' => RetrySubscriber::createChainFilter([
                new ThrottlingFilter($args['error_parser']),
                RetrySubscriber::createStatusFilter(),
                RetrySubscriber::createConnectFilter()
            ])
        ];
    }

    /**
     * Applies validation to a client
     */
    protected function handle_validate(
        $value,
        array &$args,
        AwsClientInterface $client
    ) {
        if ($value !== true) {
            return;
        }

        $client->getEmitter()->attach(new Validation($args['api'], new Validator()));
    }

    protected function handle_debug(
        $value,
        array &$args,
        AwsClientInterface $client
    ) {
        if ($value === false) {
            return;
        }

        $client->getEmitter()->attach(new Debug(
            $value === true ? [] : $value
        ));
    }

    /**
     * Validates the provided "retries" key and returns a number.
     *
     * @param mixed $value Value to validate and coerce
     *
     * @return bool|int Returns false to disable, or a number of retries.
     * @throws \InvalidArgumentException if the setting is invalid.
     */
    protected function validateRetries($value)
    {
        if (!$value) {
            return false;
        } elseif (!is_integer($value)) {
            throw new IAE('retries must be a boolean or an integer');
        }

        return $value;
    }

    private function handle_class_name($value, array &$args)
    {
        if ($value === true) {
            $args['client_class'] = 'Aws\Common\AwsClient';
            $args['exception_class'] = 'Aws\Common\Exception\AwsException';
        } else {
            // An explicitly provided class_name must be found.
            $args['client_class'] = "Aws\\{$value}\\{$value}Client";
            if (!class_exists($args['client_class'])) {
                throw new \RuntimeException("Client not found for $value");
            }
            $args['exception_class']  = "Aws\\{$args['class_name']}\\Exception\\{$args['class_name']}Exception";
            if (!class_exists($args['exception_class'] )) {
                throw new \RuntimeException("Exception class not found $value");
            }
        }
    }

    private function handle_profile($value, array &$args)
    {
        $args['credentials'] = Provider::ini($args['profile']);
    }

    private function handle_credentials($value, array &$args)
    {
        if ($value instanceof CredentialsInterface) {
            return;
        } elseif (is_callable($value)) {
            $args['credentials'] = Provider::resolve($value);
        } elseif ($value === true) {
            $args['credentials'] = Provider::resolve(Provider::defaultProvider());
        } elseif (is_array($value) && isset($value['key']) && isset($value['secret'])) {
            $args['credentials'] = new Credentials(
                $value['key'],
                $value['secret'],
                isset($value['token']) ? $value['token'] : null,
                isset($value['expires']) ? $value['expires'] : null
            );
        } elseif ($value === false) {
            $args['credentials'] = new NullCredentials();
        } else {
            throw new IAE('Credentials must be an instance of '
                . 'Aws\Common\Credentials\CredentialsInterface, an associative '
                . 'array that contains "key", "secret", and an optional "token" '
                . 'key-value pairs, a credentials provider function, or false.');
        }
    }

    private function handle_client($value, array &$args)
    {
        if (!($value instanceof ClientInterface)) {
            throw new IAE('client must be an instance of GuzzleHttp\ClientInterface');
        }

        // Make sure the user agent is prefixed by the SDK version
        $args['client']->setDefaultOption(
            'headers/User-Agent',
            'aws-sdk-php/' . Sdk::VERSION . ' ' . Client::getDefaultUserAgent()
        );
    }

    private function handle_client_defaults($value, array &$args)
    {
        if (!is_array($value)) {
            throw new IAE('client_defaults must be an array');
        }

        foreach ($value as $k => $v) {
            $args['client']->setDefaultOption($k, $v);
        }
    }

    private function handle_ringphp_handler($value, array &$args)
    {
        throw new IAE('You cannot provide both a client option and a ringphp_handler option.');
    }

    private function handle_api_provider($value, array &$args)
    {
        if (!is_callable($value)) {
            throw new IAE('api_provider must be callable');
        }

        $api = new Service($value, $args['service'], $args['version']);
        $args['api'] = $api;
        $args['error_parser'] = Service::createErrorParser($api->getProtocol());
        $args['serializer'] = Service::createSerializer($api, $args['endpoint']);
    }

    private function handle_endpoint_provider($value, array &$args)
    {
        if (!is_callable($value)) {
            throw new IAE('endpoint_provider must be a callable that returns an endpoint array.');
        }

        if (!isset($args['endpoint'])) {
            $result = call_user_func($value, [
                'service' => $args['service'],
                'region'  => $args['region'],
                'scheme'  => $args['scheme']
            ]);

            $args['endpoint'] = $result['endpoint'];

            if (isset($result['signatureVersion'])) {
                $args['signature'] = $result['signatureVersion'];
            }
        }
    }

    private function handle_signature($value, array &$args)
    {
        $region = isset($args['region']) ? $args['region'] : 'us-east-1';
        $version = $value ?: $args['api']->getMetadata('signatureVersion');

        if (is_string($version)) {
            $args['signature'] = $this->createSignature(
                $version,
                $args['api']->getSigningName(),
                $region
            );
        } elseif (!($version instanceof SignatureInterface)) {
            throw new IAE('Invalid signature option.');
        }
    }

    /**
     * Creates a signature object based on the service description.
     *
     * @param string $version     Signature version name
     * @param string $signingName Signing name of the service (for V4)
     * @param string $region      Region used for the service (for V4)
     *
     * @return SignatureInterface
     * @throws \InvalidArgumentException if the signature cannot be created
     */
    protected function createSignature($version, $signingName, $region)
    {
        switch ($version) {
            case 'v4':
                return new SignatureV4($signingName, $region);
            case 'v2':
                return new SignatureV2();
            case 'v3https':
                return new SignatureV3Https();
        }

        throw new IAE('Unable to create the signature.');
    }

    protected function postCreate(AwsClientInterface $client, array $args)
    {
        // Apply the protocol of the service description to the client.
        $this->applyParser($client);
        // Attach a signer to the client.
        $credentials = $client->getCredentials();

        // Null credentials don't sign requests.
        if (!($credentials instanceof NullCredentials)) {
            $client->getHttpClient()->getEmitter()->attach(
                new Signature($credentials, $client->getSignature())
            );
        }
    }

    /**
     * Creates and attaches parsers given client based on the protocol of the
     * description.
     *
     * @param AwsClientInterface $client AWS client to update
     *
     * @throws \UnexpectedValueException if the protocol doesn't exist
     */
    protected function applyParser(AwsClientInterface $client)
    {
        $parser = Service::createParser($client->getApi());

        $client->getEmitter()->on(
            'process',
            function (ProcessEvent $e) use ($parser) {
                // Guard against exceptions and injected results.
                if ($e->getException() || $e->getResult()) {
                    return;
                }

                // Ensure a response exists in order to parse.
                $response = $e->getResponse();
                if (!$response) {
                    throw new \RuntimeException('No response was received.');
                }

                $e->setResult($parser($e->getCommand(), $response));
            }
        );
    }

    private function deprecated_key($value, array &$args)
    {
        trigger_error('You provided key, secret, or token in a top-level '
            . 'configuration value. In v3, credentials should be provided '
            . 'in an associative array under the "credentials" key (i.e., '
            . "['credentials' => ['key' => 'abc', 'secret' => '123']]).");
        $args['credentials'] = [
            'key'    => $args['key'],
            'secret' => $args['secret'],
            'token'  => isset($args['token']) ? $args['token'] : null
        ];
        unset($args['key'], $args['secret'], $args['token']);
    }

    private function deprecated_ssl_certificate_authority($value, array &$args)
    {
        trigger_error('ssl.certificate_authority should be provided using '
            . "\$config['client_defaults']['verify']' (i.e., S3Client::factory(['client_defaults' => ['verify' => true]]). ");
        $args['client_defaults']['verify'] = $value;
        unset($args['ssl.certificate_authority']);
    }

    private function deprecated_curl_options($value, array &$args)
    {
        trigger_error("curl.options should be provided using \$config['client_defaults']['config']['curl']' "
            . "(i.e., S3Client::factory(['client_defaults' => ['config' => ['curl' => []]]]). ");
        $args['client_defaults']['config']['curl'] = $value;
        unset($args['curl.options']);
    }
}
