<?php

namespace Depoto\Exception;

use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;

class AuthenticationException extends Exception
{
    protected RequestInterface $request;
    protected ResponseInterface $response;
    protected array $errors = [];

    public function __construct(RequestInterface $request, ResponseInterface $response, int $code = 0, ?\Throwable $previous = null)
    {
        $this->request = $request;
        $this->response = $response;

        $res = json_decode((string)$response->getBody(), true);
        if (isset($res['error_description'])) {
            $this->errors[] = $res['error_description'];
        } elseif (isset($res['error'])) {
            $this->errors[] = $res['error'];
        } elseif (isset($res['message'])) {
            $this->errors[] = $res['message'];
        }

        parent::__construct(json_encode($this->errors), $code, $previous);
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

    public function getErrors(): array
    {
        return $this->errors;
    }
}