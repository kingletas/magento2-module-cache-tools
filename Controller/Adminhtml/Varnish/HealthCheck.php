<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Controller\Adminhtml\Varnish;

use Commerce\CacheTools\Model\Fastly\VarnishHealthCheck;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * GET — report how the edge is serving a URL. Read-only, so GET is correct.
 */
class HealthCheck extends Action implements HttpGetActionInterface
{
    public const string ADMIN_RESOURCE = 'Commerce_CacheTools::varnish_health';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly VarnishHealthCheck $healthCheck
    ) {
        parent::__construct($context);
    }

    public function execute(): Json
    {
        $result = $this->resultJsonFactory->create();
        $url = trim((string) $this->getRequest()->getParam('url', ''));

        if ($url === '') {
            return $result->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('Choose or enter a URL to check.'),
            ]);
        }

        $health = $this->healthCheck->check($url);

        return $result->setData([
            'success' => $health->reachable,
            'result' => $health->toArray(),
            'message' => $this->describe($health->toArray()),
        ]);
    }

    /**
     * @param array<string, mixed> $health
     */
    private function describe(array $health): string
    {
        if (!$health['reachable']) {
            return (string) __('%1 could not be reached.', $health['url']);
        }

        return (string) __(
            '%1 returned HTTP %2, cache %3%4%5.',
            $health['url'],
            $health['http_status'],
            $health['cache_state'],
            $health['age'] !== null ? (string) __(', age %1s', $health['age']) : '',
            $health['served_by'] !== null ? (string) __(', served by %1', $health['served_by']) : ''
        );
    }
}
