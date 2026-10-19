<?php
namespace ActiveSessions\Service\Controller;

use Psr\Container\ContainerInterface;
use Laminas\ServiceManager\Factory\FactoryInterface;
use ActiveSessions\Controller\Admin\IndexController;

class IndexControllerFactory implements FactoryInterface
{
    public function __invoke(ContainerInterface $services, $requestedName, ?array $options = null)
    {
        return new IndexController($services);
    }
}
