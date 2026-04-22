<?php
namespace APIHub\Client;

use Security\Test\Configuration;
use Signer\Manager\ApiException;
use Signer\Manager\Interceptor\MiddlewareEvents;
use Signer\Manager\Interceptor\KeyHandler;

class PruebaDeSeguridadApiTest extends \PHPUnit_Framework_TestCase
{
    public function setUp()
    {
        $password = getenv('KEY_PASSWORD');
        $this->keypair = 'path/to/keypair.p12';
        $this->cert = 'path/to/certificate.pem';
        $this->url = 'this_url';

        $this->signer = new \Signer\Manager\Interceptor\KeyHandler($this->keypair, $this->cert, $password);
        $events = new MiddlewareEvents($this->signer);
        $handler = \GuzzleHttp\HandlerStack::create();
        $handler->push($events->add_signature_header('x-signature'));
        $handler->push($events->verify_signature_header('x-signature'));

        $client = new \GuzzleHttp\Client(['handler' => $handler]);
        $config = new Configuration();
        $config->setHost($this->url);

        $this->apiInstance = new \Security\Test\Api\PruebaDeSeguridadApi($client,$config);
    }

    public function testSecurityTest()
    {
        $x_api_key = "XXXXXXXXXXXXXXXXXXXXXXXXXXXXXX";
        $body = "Esto es un mensaje de prueba";

        try {
            $result = $this->apiInstance->securityTest($x_api_key, $body);
            $this->signer->close();
            print_r($result);
        } catch (Exception $e) {
            echo 'Exception when calling PruebaDeSeguridadApi->securityTest: ', $e->getMessage(), PHP_EOL;
        }
    }
}
