<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['drupal', 'var'])
    ->append(glob(__DIR__.'/bin/smoke-*'))
;

return (new PhpCsFixer\Config())
    ->setRules(['@Symfony' => true])
    ->setFinder($finder)
;
