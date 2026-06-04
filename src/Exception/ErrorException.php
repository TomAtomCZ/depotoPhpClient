<?php

namespace Depoto\Exception;

use Depoto\Client;
use Psr\Http\Message\RequestInterface;
use Psr\Http\Message\ResponseInterface;
use Throwable;

class ErrorException extends Exception
{
    protected RequestInterface $request;
    protected ResponseInterface $response;
    protected array $errors = [];

    public function __construct(RequestInterface $request, ResponseInterface $response, int $code = 0, ?Throwable $previous = null)
    {
        $this->request = $request;
        $this->response = $response;

        $res = json_decode((string)$response->getBody(), true);
        if ($res) {
            if (is_array($res)) {
                $res = Client::decode($res);
            }

            if (isset($res['error'])) {
                $this->errors[] = $res['error'];
            }
            if (isset($res['errors'])) {
                if (is_array($res['errors'])) {
                    foreach ($res['errors'] as $error) {
                        if (is_array($error) && isset($error['message'])) {
                            $error = $error['message'];
                        }
                        $this->errors[] = $error;
                    }
                } else {
                    $this->errors[] = $res['errors'];
                }
            }
            if (isset($res['data'])) {
                foreach ($res['data'] as $data) {
                    if (!empty($data['errors']) && is_array($data['errors'])) {
                        foreach ($data['errors'] as $error) {
                            if (is_array($error) && isset($error['message'])) {
                                $error = $error['message'];
                            }
                            $this->errors[] = $error;
                        }
                    }
                }
            }
        } else {
            $this->errors[] = $code . ' ' . $response->getReasonPhrase();
        }

        parent::__construct(implode("\n", $this->errors), $code, $previous);
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