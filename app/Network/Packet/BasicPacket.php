<?php


namespace Konfigurator\SystemService\Common\Network\Packet;


use Konfigurator\Network\Packet\AbstractPacket;

class BasicPacket extends AbstractPacket
{
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