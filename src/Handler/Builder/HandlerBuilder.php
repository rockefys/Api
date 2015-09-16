<?php

/*
 * This file is part of the Api package
 *
 * (c) FiveLab
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code
 */

namespace FiveLab\Component\Api\Handler\Builder;

use FiveLab\Component\Api\Exception\AlreadyBuildedException;
use FiveLab\Component\Api\Handler\BaseHandler;
use FiveLab\Component\Api\Handler\Doc\ActionExtractor;
use FiveLab\Component\Api\Handler\Doc\Extractor;
use FiveLab\Component\Api\Handler\Parameter\MethodParameterResolverAndExtractor;
use FiveLab\Component\Api\Handler\Parameter\ParameterExtractorInterface;
use FiveLab\Component\Api\Handler\Parameter\ParameterResolverInterface;
use FiveLab\Component\Api\SMD\ActionRegistry;
use FiveLab\Component\Api\SMD\CallableResolver\CallableResolverInterface;
use FiveLab\Component\Api\SMD\CallableResolver\ChainResolver;
use FiveLab\Component\Api\SMD\CallableResolver\CallableResolver;
use FiveLab\Component\Api\SMD\Loader\ChainLoader;
use FiveLab\Component\Api\SMD\Loader\CallableLoader;
use FiveLab\Component\Api\SMD\Loader\LoaderInterface;
use FiveLab\Component\Error\ErrorFactoryInterface;
use FiveLab\Component\Error\Errors;
use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Base handler builder
 *
 * @author Vitaliy Zhuk <v.zhuk@fivelab.org>
 */
class HandlerBuilder implements HandlerBuilderInterface
{
    /**
     * @var \FiveLab\Component\Api\Handler\HandlerInterface
     */
    private $handler;

    /**
     * @var \FiveLab\Component\Api\Handler\Doc\ExtractorInterface
     */
    private $docExtractor;

    /**
     * @var \FiveLab\Component\Error\ErrorFactoryInterface[]
     */
    protected $errorFactories = [];

    /**
     * @var \FiveLab\Component\Error\Errors
     */
    protected $errors;

    /**
     * @var array|CallableResolverInterface[]
     */
    protected $callableResolvers = [];

    /**
     * @var ChainResolver
     */
    protected $callableResolver;

    /**
     * @var array|LoaderInterface[]
     */
    protected $actionLoaders = [];

    /**
     * @var ChainLoader
     */
    protected $actionLoader;

    /**
     * @var ActionRegistry
     */
    protected $actionRegistry;

    /**
     * @var EventDispatcherInterface
     */
    protected $eventDispatcher;

    /**
     * @var ParameterResolverInterface
     */
    protected $parameterResolver;

    /**
     * @var ParameterExtractorInterface
     */
    protected $parameterExtractor;

    /**
     * @var LoggerInterface
     */
    protected $logger;

    /**
     * Add error factory
     *
     * @param ErrorFactoryInterface $errorFactory
     *
     * @return HandlerBuilder
     */
    public function addErrorFactory(ErrorFactoryInterface $errorFactory)
    {
        $this->errorFactories[spl_object_hash($errorFactory)] = $errorFactory;

        return $this;
    }

    /**
     * Add closure handle
     *
     * @return CallableLoader
     *
     * @throws AlreadyBuildedException
     */
    public function addCallableHandle()
    {
        if ($this->handler) {
            throw new AlreadyBuildedException('The handler already builded.');
        }

        $loader = new CallableLoader();
        $resolver = new CallableResolver();

        $this->addCallableResolver($resolver);
        $this->addActionLoader($loader);

        return $loader;
    }

    /**
     * Add callable resolver
     *
     * @param CallableResolverInterface $callableResolver
     *
     * @return HandlerBuilder
     *
     * @throws AlreadyBuildedException
     */
    public function addCallableResolver(CallableResolverInterface $callableResolver)
    {
        if ($this->handler) {
            throw new AlreadyBuildedException('The handler already builded.');
        }

        $this->callableResolvers[spl_object_hash($callableResolver)] = $callableResolver;

        return $this;
    }

    /**
     * Add action loader
     *
     * @param LoaderInterface $loader
     *
     * @return HandlerBuilder
     *
     * @throws AlreadyBuildedException
     */
    public function addActionLoader(LoaderInterface $loader)
    {
        if ($this->handler) {
            throw new AlreadyBuildedException('The handler already builded.');
        }

        $this->actionLoaders[spl_object_hash($loader)] = $loader;

        return $this;
    }

    /**
     * Set event dispatcher
     *
     * @param EventDispatcherInterface $eventDispatcher
     *
     * @return HandlerBuilder
     *
     * @throws AlreadyBuildedException
     */
    public function setEventDispatcher(EventDispatcherInterface $eventDispatcher)
    {
        if ($this->handler) {
            throw new AlreadyBuildedException('The handler already builded.');
        }

        $this->eventDispatcher = $eventDispatcher;

        return $this;
    }

    /**
     * Set parameter resolver
     *
     * @param ParameterResolverInterface $resolver
     *
     * @return HandlerBuilder
     *
     * @throws AlreadyBuildedException
     */
    public function setParameterResolver(ParameterResolverInterface $resolver)
    {
        if ($this->handler) {
            throw new AlreadyBuildedException('The handler already builded.');
        }

        $this->parameterResolver = $resolver;

        return $this;
    }

    /**
     * Set parameter extractor
     *
     * @param ParameterExtractorInterface $extractor
     *
     * @return HandlerBuilder
     *
     * @throws AlreadyBuildedException
     */
    public function setParameterExtractor(ParameterExtractorInterface $extractor)
    {
        if ($this->handler) {
            throw new AlreadyBuildedException('The handler already builded.');
        }

        $this->parameterExtractor = $extractor;

        return $this;
    }

    /**
     * Set logger
     *
     * @param LoggerInterface $logger
     *
     * @return HandlerBuilder
     *
     * @throws AlreadyBuildedException
     */
    public function setLogger(LoggerInterface $logger)
    {
        if ($this->handler) {
            throw new AlreadyBuildedException('The handler already builded.');
        }

        $this->logger = $logger;

        return $this;
    }

    /**
     * {@inheritDoc}
     */
    public function buildHandler()
    {
        if ($this->handler) {
            return $this->handler;
        }

        // Create action loader and action manager
        $this->actionLoader = $this->createActionLoader();
        $this->actionRegistry = $this->createActionRegistry();

        // Create callable resolver
        $this->callableResolver = $this->createCallableResolver();

        // Create errors system
        $this->errors = $this->createErrors();

        if (!$this->eventDispatcher) {
            $this->eventDispatcher = new EventDispatcher();
        }

        if (!$this->parameterResolver) {
            $this->parameterResolver = $this->createParameterResolver();
        }

        if (!$this->parameterExtractor && $this->parameterResolver instanceof ParameterExtractorInterface) {
            $this->parameterExtractor = $this->parameterResolver;
        }

        // Create handler
        $handler = new BaseHandler(
            $this->actionRegistry,
            $this->callableResolver,
            $this->parameterResolver,
            $this->eventDispatcher,
            $this->errors
        );

        return $handler;
    }

    /**
     * {@inheritDoc}
     */
    public function buildDocExtractor()
    {
        if ($this->docExtractor) {
            return $this->docExtractor;
        }

        $actionExtractor = new ActionExtractor($this->callableResolver, $this->parameterExtractor);
        $this->docExtractor = new Extractor($actionExtractor);

        return $this->docExtractor;
    }

    /**
     * Create parameter resolver
     *
     * @return MethodParameterResolverAndExtractor
     */
    protected function createParameterResolver()
    {
        return new MethodParameterResolverAndExtractor();
    }

    /**
     * Create action loader
     *
     * @return ChainLoader
     */
    protected function createActionLoader()
    {
        return new ChainLoader($this->actionLoaders);
    }

    /**
     * Create action manager
     *
     * @return ActionRegistry
     */
    protected function createActionRegistry()
    {
        return new ActionRegistry($this->actionLoader);
    }

    /**
     * Create callable resolver
     *
     * @return ChainResolver
     */
    protected function createCallableResolver()
    {
        return new ChainResolver($this->callableResolvers);
    }

    /**
     * Create error
     *
     * @return Errors
     */
    protected function createErrors()
    {
        return new Errors($this->errorFactories);
    }
}
