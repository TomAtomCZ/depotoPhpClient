<?php

namespace Depoto\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class ServerException extends Exception
{
    protected RequestInterface $request;
    protected ResponseInterface $response;
    protected array $errors = [];

    public function __construct(RequestInterface $request, ResponseInterface $response)
    {
        $this->request = $request;
        $this->response = $response;

        $res = json_decode((string)$response->getBody(), true);
        if (isset($res['error'])) {
            $this->errors[] = $res['error'];
        }
        if (isset($res['errors'])) {
            $this->errors[] = $res['errors'];
        }
        if (isset($res['message'])) {
            $this->errors[] = $res['message'];
        }

        /**
         * @todo
         * set $code
         */
        parent::__construct(json_encode($this->errors));
    }

    /**
     * @return RequestInterface
     */
    public function getRequest(): RequestInterface
    {
        return $this->request;
    }

    /**
     * @return ResponseInterface
     */
    public function getResponse(): ResponseInterface
    {
        return $this->response;
    }
}