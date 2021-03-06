<?php

namespace LendingWorks\RetailFinance\Controller\Payment;

use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use LendingWorks\RetailFinance\Helper\Data;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\ResponseInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Controller\ResultInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Sales\Model\OrderRepository;

class CreateOrder extends Action
{

  /**
   * @var ScopeConfigInterface
   */
  protected $scopeConfig;

  /**
   * @var Client
   */
  protected $guzzleClient;

  /**
   * @var OrderRepository
   */
  protected $orderRepository;

  /**
   * CreateOrder constructor.
   *
   * @param Context              $context
   * @param ScopeConfigInterface $scopeConfig
   */
  public function __construct(
    Context $context,
    ScopeConfigInterface $scopeConfig,
    OrderRepository $orderRepository
  )
  {
    parent::__construct($context);
    $this->scopeConfig = $scopeConfig;
    $this->orderRepository = $orderRepository;
  }
  /**
   * Execute action based on request and return result
   *
   * @return ResultInterface|ResponseInterface
   * @throws \Magento\Framework\Exception\NotFoundException
   */
  public function execute()
  {
    if ($this->getRequest()->getMethod() != 'POST') {
      return $this->result(405, 'Method not allowed - use POST');
    }

    $orderID = $this->getRequest()->getPostValue()['orderID'];
    $amount = $this->getRequest()->getPostValue()['amount'];

    try {
      $order = $this->orderRepository->get($orderID);
    } catch (NoSuchEntityException $e) {
      return $this->result(404, "Order {$orderID} not found");
    }

    $products = [];
    foreach ($order->getItems() as $item) {
      $products[] = [
        'cost' => $item->getBaseCost(),
        'quantity' => $item->getQtyOrdered(),
        'description' => $item->getDescription()
      ];
    }

    $data = [
      'amount' => $amount,
      'products' => $products
    ];

    $postData = json_encode($data, JSON_PRESERVE_ZERO_FRACTION | JSON_PRETTY_PRINT);

    $hash = md5($postData);

    if (!empty($_SESSION[Data::ORDER_SESSION_KEY])
      && !empty($_SESSION[Data::ORDER_SESSION_KEY][$hash])) {

      return $this->result(200, 'Order token succesfully loaded');
    }

    if (json_last_error() !== JSON_ERROR_NONE) {
      return $this->result(400, 'Unable to encode message body'. json_last_error_msg());
    }

    $apiURL = Data::getBaseURLForEnvironment($this->getRFPaymentConfig('target_server')). '/orders';
    $apiKey = $this->getRFPaymentConfig(Data::OVERRIDE_API_KEY_KEY);
    $headers = [
      'Content-type' => 'application/json',
      'Authorization' => 'RetailApiKey ' . $apiKey
    ];
    $request = new Request('POST', $apiURL, $headers, $postData);

    $response = $this->queryAPI($request);
    if ($response instanceof ResultInterface) {
      return $response;
    }

    if ($response->getStatusCode() !== 200) {
      $message ='Could not create order, non-200 HTTP code returned: '
      . $response->getStatusCode() . ' - ' . $response->getBody();
      return $this->result($response->getStatusCode(), $message);
    }

    $result = json_decode($response->getBody(), true);
    if ($result === null) {
      return $this->result(400, 'Unable to decode API response');
    }

    if (!array_key_exists('token', $result)) {
      return $this->result(400, 'Invalid API response, received: ' . $result);
    }
    $_SESSION[Data::ORDER_SESSION_KEY] = [
      $hash => $result['token']
    ];

    return $this->result(200, 'Order successfully created');
  }

  /**
   * Helper for config values
   * @param string $key
   *
   * @return mixed
   */
  private function getRFPaymentConfig($key)
  {
    if (!empty(Data::getOverrides()) && isset(Data::getOverrides()[$key])) {
      return Data::getOverrides()[$key];
    }
    return $this->scopeConfig->getValue('payment/' . Data::PAYMENT_CODE . '/' . $key);
  }


  /**
   * @return Client
   */
  private function getClient()
  {
    if (!$this->guzzleClient) {
      $this->guzzleClient = new Client();
    }
    return $this->guzzleClient;
  }

  /**
   * @param int $statusCode
   *
   * @return ResultInterface
   */
  private function result($statusCode, $message)
  {
    if ($statusCode === 200) {
      $this->messageManager->addSuccessMessage($message);
    } else {
      $this->messageManager->addErrorMessage($message);
    }
    $return = $this->resultFactory->create(ResultFactory::TYPE_JSON)->setHttpResponseCode($statusCode);
    $return->setData(['message' => __($message)]);

    return $return;
  }

  /**
   * @param Request $request
   *
   * @return Response|ResultInterface
   *
   * @throws GuzzleException
   */
  private function queryAPI(Request $request)
  {
    try {
      return $this->getClient()->send($request);
    } catch (GuzzleException $e) {
      if (isset(Data::getOverrides()[Data::OVERRIDE_MOCK_API_RESPONSE_KEY])) {
        return new Response(200, [], '{"token": "mock_turtle_soup"}');
      }
      return $this->result(400, $e->getMessage());
    }
  }
}
