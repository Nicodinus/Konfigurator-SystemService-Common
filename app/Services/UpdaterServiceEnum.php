<?php


namespace Konfigurator\SystemService\Common\Services;


use Konfigurator\Common\Enums\StateEnum;

/**
 * Class UpdateServiceEnum
 * @package Konfigurator\SystemService\Network
 * @method static static VERSION_MATCHED()
 * @method static static VERSION_MISMATCHED()
 */
class UpdaterServiceEnum extends StateEnum
{
    private const VERSION_MATCHED = 'version_matched';
    private const VERSION_MISMATCHED = 'version_mismatched';
}