<?php
/**
 * @package   Commerce_CacheTools
 * @copyright Copyright (c) the Commerce modules authors
 * @license   OSL-3.0 https://opensource.org/licenses/OSL-3.0
 */

declare(strict_types=1);

namespace Commerce\CacheTools\Controller\Adminhtml\Varnish;

use Commerce\CacheTools\Model\Fastly\Purger;
use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;

/**
 * POST — purge a URL from the edge, from Cache Management.
 */
class Flush extends Action implements HttpPostActionInterface
{
    public const string ADMIN_RESOURCE = 'Commerce_CacheTools::varnish_flush';

    public function __construct(
        Context $context,
        private readonly JsonFactory $resultJsonFactory,
        private readonly Purger $purger
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
                'message' => __('Choose or enter a URL to purge.'),
            ]);
        }

        $purgeResult = $this->purger->purgeUrl($url);

        return $result->setHttpResponseCode($purgeResult->isSuccess ? 200 : 422)->setData([
            'success' => $purgeResult->isSuccess,
            'message' => (string) $purgeResult->message,
        ]);
    }
}
