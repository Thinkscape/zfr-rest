<?php
/*
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS
 * "AS IS" AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT
 * LIMITED TO, THE IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR
 * A PARTICULAR PURPOSE ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT
 * OWNER OR CONTRIBUTORS BE LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL,
 * SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT
 * LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES; LOSS OF USE,
 * DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND ON ANY
 * THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE
 * OF THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This software consists of voluntary contributions made by many individuals
 * and is licensed under the MIT license.
 */

namespace ZfrRest\Mvc\Controller;

use Zend\Http\Request as HttpRequest;
use Zend\Mvc\Controller\AbstractController;
use Zend\Mvc\Exception;
use Zend\Mvc\MvcEvent;
use Zend\Paginator\Paginator;
use Zend\ServiceManager\Exception\ExceptionInterface as ServiceManagerExceptionInterface;
use Zend\Stdlib\RequestInterface;
use Zend\Stdlib\ResponseInterface;
use ZfrRest\Http\Exception\Client;
use ZfrRest\Http\Exception\Server;
use ZfrRest\Mvc\Exception\RuntimeException;
use ZfrRest\Resource\Resource;
use ZfrRest\Resource\ResourceInterface;

/**
 * Abstract RESTful controller. It is responsible for dispatching a HTTP request to a function, or throwing an
 * exception if the method is not implemented.
 *
 * By default, AbstractRestfulController handles the "big four" methods (GET, DELETE, PUT and POST). You can add
 * your own verbs or changing existing ones by overriding methods like handle*Method (eg.: handleGetMethod,
 * handlePostMethod, handlePatchMethod...). Please note that your handlers must always return the result of
 * the action!
 *
 * @license MIT
 * @author  Michaël Gallego <mic.gallego@gmail.com>
 */
abstract class AbstractRestfulController extends AbstractController
{
    /**
     * {@inheritDoc}
     */
    public function dispatch(RequestInterface $request, ResponseInterface $response = null)
    {
        if (!$request instanceof HttpRequest) {
            throw new Exception\InvalidArgumentException('Expected an HTTP request');
        }

        return parent::dispatch($request, $response);
    }

    /**
     * Execute the request. Try to match the HTTP verb to an action
     *
     * @param  MvcEvent $e
     * @return mixed
     * @throws Client\NotFoundException If the resource cannot be found
     * @throws Client\MethodNotAllowedException If the method to handle the request is not implemented
     */
    public function onDispatch(MvcEvent $e)
    {
        $method  = strtolower($this->getRequest()->getMethod());
        $handler = 'handle' . ucfirst($method) . 'Method';

        if (!method_exists($this, $method) || !method_exists($this, $handler)) {
            throw new Client\MethodNotAllowedException();
        }

        /** @var \ZfrRest\Resource\ResourceInterface $resource */
        $resource = $e->getRouteMatch()->getParam('resource', null);

        // We should always have a resource, otherwise throw an 404 exception
        if (null === $resource) {
            throw new Client\NotFoundException();
        }

        $return = $this->$handler($resource);

        $e->setResult($return);

        return $return;
    }

    /**
     * GET method is used to retrieve (or read) a representation of a resource. Get method is idempotant, which means
     * that making multiple identical requests ends up having the same result as a single request. Get requests should
     * not modify any resources
     *
     * @param  ResourceInterface $resource
     * @return mixed
     */
    protected function handleGetMethod(ResourceInterface $resource)
    {
        return $this->get($resource->getData(), $resource->getMetadata());
    }

    /**
     * DELETE method is used to delete a representation of a resource
     *
     * @param  ResourceInterface $resource
     * @return mixed
     */
    protected function handleDeleteMethod(ResourceInterface $resource)
    {
        return $this->delete($resource->getData(), $resource->getMetadata());
    }

    /**
     * POST method is used to create a new resource. On successful creation, POST method should return a HTTP status
     * 201, with a Location header containing the URL of the newly created resource. We are doing several things for the
     * user automatically:
     *      - we validate post data with the input filter defined in metadata
     *      - we hydrate valid data
     *      - we pass the object to the post method of the controller
     *
     * As you can see, the post method have three arguments: the object that is inserted, the resource metadata and
     * the resource itself (which is the Collection where the object is added)
     *
     * Note that if you have set "auto_validate" and/or "auto_hydrate" to false in ZfrRest config, those steps won't
     * do nothing
     *
     * @param  ResourceInterface $resource
     * @throws Client\BadRequestException if validation fails
     * @return mixed
     */
    protected function handlePostMethod(ResourceInterface $resource)
    {
        $metadata       = $resource->getMetadata();
        $className      = $metadata->getClassName();
        $singleResource = new Resource(new $className, $metadata);

        $data = $this->validateData($metadata->getInputFilterName(), $this->decodeBody());
        $data = $this->hydrateData($metadata->getHydratorName(), $data, $singleResource);

        $this->post($data, $metadata, $resource);

        // Set the Location header with the URL to the newly created resource
        if (is_object($data)) {
            // TODO: use Router for that
            $identifierValue = reset($metadata->getClassMetadata()->getIdentifierValues($data));
            $url             = '/' . trim($this->request->getUri()->getPath(), '/') . '/' . $identifierValue;

            $this->response->getHeaders()->addHeaderLine('Location', $url);
        }

        $this->response->setStatusCode(201);

        return $this->response;
    }

    /**
     * PUT method is used to update an existing resource. We are doing several things for the user automatically:
     *      - we validate post data with the input filter defined in metadata
     *      - we hydrate valid data to update existing resource
     *      - we pass the object to the put method of the controller
     *
     * Note that if you have set "auto_validate" and/or "auto_hydrate" to false in ZfrRest config, those steps won't
     * do nothing
     *
     * @param ResourceInterface $resource
     * @throws Client\BadRequestException if validation fails
     * @return mixed
     */
    protected function handlePutMethod(ResourceInterface $resource)
    {
        $metadata = $resource->getMetadata();

        $data = $this->validateData($metadata->getInputFilterName(), $this->decodeBody());
        $data = $this->hydrateData($metadata->getHydratorName(), $data, $resource);

        return $this->put($data, $metadata);
    }

    /**
     * Automatically create an InputFilter object, and validate data against it.
     *
     * @param  string $inputFilterClass
     * @param  array  $data
     * @throws Server\InternalServerErrorException
     * @throws Client\BadRequestException
     * @return array
     */
    protected function validateData($inputFilterClass, array $data)
    {
        /** @var $moduleOptions \ZfrRest\Options\ModuleOptions */
        $moduleOptions        = $this->serviceLocator->get('ZfrRest\Options\ModuleOptions');
        $controllerBehaviours = $moduleOptions->getControllerBehaviours();

        if (!$controllerBehaviours->getAutoValidate()) {
            return $data;
        }

        try {
            $inputFilterManager = $this->serviceLocator->get('InputFilterManager');
            $inputFilter        = $inputFilterManager->get($inputFilterClass);
        } catch (ServiceManagerExceptionInterface $e) {
            throw new Server\InternalServerErrorException(sprintf(
                'An invalid input filter class name was given when validating data ("%s" given)',
                null === $inputFilterClass ? 'null' : $inputFilterClass
            ));
        }

        $inputFilter->setData($data);
        if (!$inputFilter->isValid()) {
            throw new Client\BadRequestException('', $inputFilter->getMessages());
        }

        // Return validated and filtered values
        return $inputFilter->getValues();
    }

    /**
     * Automatically create a Hydrator object, and hydrate object. If ZfrRest was configured to not hydrate
     * automatically, then this method only returns untouched data as array
     *
     * @param  string            $hydratorClass
     * @param  array             $data
     * @param  ResourceInterface $resource
     * @throws Server\InternalServerErrorException
     * @return array|object
     */
    public function hydrateData($hydratorClass, array $data, ResourceInterface $resource)
    {
        /** @var $moduleOptions \ZfrRest\Options\ModuleOptions */
        $moduleOptions        = $this->serviceLocator->get('ZfrRest\Options\ModuleOptions');
        $controllerBehaviours = $moduleOptions->getControllerBehaviours();

        if (!$controllerBehaviours->getAutoHydrate()) {
            return $data;
        }

        try {
            $hydratorManager = $this->serviceLocator->get('HydratorManager');
            $hydrator        = $hydratorManager->get($hydratorClass);
        } catch (ServiceManagerExceptionInterface $e) {
            throw new Server\InternalServerErrorException(sprintf(
                'An invalid hydrator class name was given when hydrating data ("%s" given)',
                null === $hydratorClass ? 'null' : $hydratorClass
            ));
        }

        return $hydrator->hydrate($data, $resource->getData());
    }

    /**
     * Parse the body content according to the Content-Type value
     *
     * @return array
     */
    protected function decodeBody()
    {
        return $this->decode($this->request->getContent()) ?: array();
    }

    /**
     * Decode a content according to the Content-Type value
     *
     * @param  mixed $content
     * @return array
     */
    protected function decode($content)
    {
        /** @var $decoderPluginManager \ZfrRest\Serializer\DecoderPluginManager */
        $decoderPluginManager = $this->serviceLocator->get('ZfrRest\Serializer\DecoderPluginManager');

        $header = $this->request->getHeader('Content-Type', null);
        if (null === $header) {
            return null;
        }

        $mimeType = $header->getFieldValue();

        return $decoderPluginManager->get($mimeType)->decode($content, '');
    }
}
