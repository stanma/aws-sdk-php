<?php
namespace Aws\CloudSearchDomain;

use Aws\Common\ClientFactory;
use GuzzleHttp\Url;

/**
 * @internal
 */
class CloudSearchDomainFactory extends ClientFactory
{
    /**
     * {@inheritdoc}
     *
     * CloudSearchDomain does not require a region, but does need an endpoint.
     */
    protected function addDefaultArgs(&$args)
    {
        // An endpoint is required.
        if (!isset($args['endpoint'])) {
            throw new \InvalidArgumentException('You must provide the endpoint '
                . 'for the CloudSearch domain.');
        }

        if (!isset($args['region'])) {
            // Determine the region from the provided endpoint.
            // (e.g. http://search-blah.{region}.cloudsearch.amazonaws.com)
            list(,$args['region']) = explode('.', Url::fromString($args['endpoint']));
        }

        parent::addDefaultArgs($args);
    }
}
