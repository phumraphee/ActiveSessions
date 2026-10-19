<?php
namespace ActiveSessions;

return [
    'controllers' => [
        'factories' => [
            Controller\Admin\IndexController::class => Service\Controller\IndexControllerFactory::class,
        ],
    ],
    'view_manager' => [
        'template_path_stack' => [dirname(__DIR__) . '/view'],
    ],
    'router' => [
        'routes' => [
            'admin' => [
                'child_routes' => [
                    'active-sessions' => [
                        'type' => \Laminas\Router\Http\Segment::class,
                        'options' => [
                            /* __NAMESPACE__ must be this module's own and the controller named
                               in full under it: the parent 'admin' route sets
                               Omeka\Controller\Admin, and without both every request here is
                               a 404. */
                            'route' => '/active-sessions[/:action]',
                            'constraints' => ['action' => '[a-z-]+'],
                            'defaults' => [
                                '__NAMESPACE__' => 'ActiveSessions\Controller\Admin',
                                'controller' => Controller\Admin\IndexController::class,
                                'action' => 'index',
                            ],
                        ],
                    ],
                ],
            ],
        ],
    ],
    'navigation' => [
        'AdminModule' => [
            [
                'label' => 'Signed-in sessions', // @translate
                'route' => 'admin/active-sessions',
                'resource' => Controller\Admin\IndexController::class,
                'privilege' => 'index',
                'class' => 'o-icon- fa-user-clock',
            ],
        ],
    ],
];
