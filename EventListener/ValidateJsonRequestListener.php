<?php

namespace Mrsuh\JsonValidationBundle\EventListener;

use Mrsuh\JsonValidationBundle\Annotation\ValidateJsonRequest;
use Mrsuh\JsonValidationBundle\Exception\JsonValidationRequestException;
use Mrsuh\JsonValidationBundle\JsonValidator\JsonValidator;
use Symfony\Component\HttpKernel\Event\ControllerEvent;

class ValidateJsonRequestListener
{
    use AnnotationReader;

    protected JsonValidator $jsonValidator;

    public function __construct(JsonValidator $jsonValidator)
    {
        $this->jsonValidator = $jsonValidator;
    }

    /**
     * @param ControllerEvent|FilterControllerEvent $event
     */
    public function onKernelController($event): void
    {
        $request = $event->getRequest();

        $annotation = self::getAnnotation($request, ValidateJsonRequest::class);
        if ($annotation === null) {
            return;
        }

        $httpMethods = array_map(function (string $method): string {
            return strtoupper($method);
        }, $annotation->getMethods());

        if (!empty($httpMethods) && !in_array($request->getMethod(), $httpMethods)) {
            return;
        }

        $content = $request->getContent();

        if ($annotation->getEmptyIsValid() && empty($content)) {
            return;
        }

        $objectData = $this->jsonValidator->validate(
            $content,
            $annotation->getPath()
        );

        if (!empty($this->jsonValidator->getErrors())) {
            throw new JsonValidationRequestException($request, $annotation->getPath(), $this->jsonValidator->getErrors());
        }

        if ($this->getAsArray($event->getController())) {
            $request->attributes->set('validJson', json_decode($content, true));
        } else {
            $request->attributes->set('validJson', $objectData);
        }
    }

    /**
     * Decide whether the validated JSON should be decoded as an array
     *
     * This is based upon the type hint for the $validJson argument
     *
     * @return bool
     * @see Sensio\Bundle\FrameworkExtraBundle\EventListener\ParamConverterListener::onKernelController
     */
    protected function getAsArray($controller): bool
    {
        $r = null;

        if (is_array($controller)) {
            $r = new \ReflectionMethod($controller[0], $controller[1]);
        } elseif (is_object($controller) && is_callable($controller, '__invoke')) {
            $r = new \ReflectionMethod($controller, '__invoke');
        } else {
            $r = new \ReflectionFunction($controller);
        }

        foreach ($r->getParameters() as $param) {
            if ($param->getName() !== 'validJson') {
                continue;
            }

            return $param->getType() && $param->getType()->getName() === 'array';
        }

        return false;
    }
}
