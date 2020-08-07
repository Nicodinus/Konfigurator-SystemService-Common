<?php


namespace Konfigurator\SystemService\Common\Network\Packet\Actions\Authorize;


use Konfigurator\Network\Session\SessionInterface;
use Konfigurator\SystemService\Common\Network\Packet\ActionPacket;
use Konfigurator\SystemService\Common\Network\Session\Auth\AccessLevelEnum;

abstract class ResponsePacket extends ActionPacket
{
    /**
     * ResponsePacket constructor.
     * @param SessionInterface $session
     * @param bool $isRemote
     */
    public function __construct(SessionInterface $session, bool $isRemote = false)
    {
        parent::__construct($session, $isRemote);
    }

    /**
     * @return string
     */
    public static function getAction(): string
    {
        return "authorize.response";
    }

    /**
     * @return AccessLevelEnum|null
     */
    public static function accessRequired(): ?AccessLevelEnum
    {
        return null;
    }

    /**
     * @return array
     */
    public function getFieldProps(): array
    {
        return [
            'authItem' => [
                'type' => 'object',
                'rules' => ['nullable'],
                'unserialize' => function ($value, self $self) {
                    if (empty($value)) {
                        return null;
                    }
                    return $self->getSession()->getAuthGuard()->getProvider()->retrieveByCredentials($value);
                },
            ],
        ];
    }
}