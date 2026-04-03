<?php
namespace App\Core;

abstract class Command {
    protected Container $container;

    public function __construct(Container $container) {
        $this->container = $container;
    }

    abstract public function execute(array $args): void;
}