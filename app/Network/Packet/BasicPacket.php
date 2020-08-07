<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Konfigurator\Network\Packet\AbstractPacket;

class BasicPacket extends AbstractPacket
{
    /**
     * @return mixed
     */
    public static function getId()
    {
        return null;
    }

    /**
     * @param mixed $data
     * @return static
     */
    public function setData($data)
    {
        $this->data = $data;
        return $this;
    }
}