<?php

$config = new class() extends PrestaShop\CodingStandards\CsFixer\Config {
    public function getRules(): array
    {
        return array_merge(parent::getRules(), [
            'trailing_comma_in_multiline' => [
                'elements' => ['arguments', 'arrays'],
            ],
        ]);
    }
};

/** @var \Symfony\Component\Finder\Finder $finder */
$finder = $config->setUsingCache(true)->getFinder();
$finder->in(__DIR__)->exclude('vendor');

return $config;
