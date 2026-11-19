<?php

$finder = (new PhpCsFixer\Finder())
    ->in(__DIR__)
    ->exclude(['var'])
    ->append([\dirname(__DIR__).'/bin/lib/replay-site'])
;

return (new PhpCsFixer\Config())
    ->setRules(['@Symfony' => true])
    ->setFinder($finder)
;
