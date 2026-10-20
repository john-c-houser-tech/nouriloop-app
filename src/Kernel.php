<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;
use Symfony\Component\Mailer\EventListener\MessageListener;

class Kernel extends BaseKernel implements CompilerPassInterface
{
    use MicroKernelTrait;

    /**
     * The global Reply-To (MAILER_REPLY_TO, `mailer.yaml`) is a default, not an addition:
     * Symfony's rule for that header is "add", so a message that sets its own Reply-To (the
     * feedback and business-site contact forms) went out replying to noreply@ as well.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->hasDefinition('mailer.message_listener')) {
            $container->getDefinition('mailer.message_listener')
                ->addMethodCall('addHeaderRule', ['reply-to', MessageListener::HEADER_SET_IF_EMPTY]);
        }
    }
}
