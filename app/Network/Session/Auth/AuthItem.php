<?php


namespace Konfigurator\SystemService\Common\Network\Session\Auth;


use Konfigurator\Network\Session\Auth\AuthItemInterface;
use Konfigurator\Network\Session\SessionInterface;

class AuthItem implements AuthItemInterface
{
    /** @var int|string */
    protected $id;

    /** @var array */
    protected array $credentials;

    /** @var AccessLevelEnum */
    protected AccessLevelEnum $accessLevel;

    /** @var SessionInterface|null */
    protected ?SessionInterface $session;


    /**
     * AuthItem constructor.
     * @param string|null $username
     * @param string|null $key
     * @param AccessLevelEnum|null $level
     */
    public function __construct(?string $username = null, ?string $key = null, ?AccessLevelEnum $level = null)
    {
        $this->id = null;
        $this->credentials = [
            'username' => $username,
            'key' => $key,
        ];
        $this->accessLevel = $level ?? AccessLevelEnum::GUEST();
        $this->session = null;
    }

    /**
     * @param int|string $id
     * @return static
     */
    public function withId($id)
    {
        $this->id = $id;
        return $this;
    }

    /**
     * @return int|string
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * @return array
     */
    public function getCredentials(): array
    {
        return $this->credentials;
    }

    /**
     * @return AccessLevelEnum
     */
    public function getAccessLevel(): AccessLevelEnum
    {
        return $this->accessLevel;
    }

    /**
     * @param string|null $value
     * @return static
     */
    public function setUsername(?string $value = null)
    {
        $this->credentials['username'] = $value;
        return $this;
    }

    /**
     * @param AccessLevelEnum|string $level
     * @return static
     */
    public function setAccessLevel($level = null)
    {
        if (is_null($level)) {
            $this->accessLevel = AccessLevelEnum::GUEST();
        } else if (is_string($level) && AccessLevelEnum::isValid($level)) {
            $level = AccessLevelEnum::search($level);
            $this->accessLevel = AccessLevelEnum::$level();
        } else if ($level instanceof AccessLevelEnum) {
            $this->accessLevel = $level;
        }

        return $this;
    }

    /**
     * @param SessionInterface $session
     * @return static
     */
    public function withSession($session)
    {
        $this->session = $session;
        return $this;
    }

    /**
     * @return SessionInterface|null
     */
    public function getSession(): ?SessionInterface
    {
        return $this->session;
    }

    /**
     * @return static
     */
    public function clearSession()
    {
        $this->session = null;
        return $this;
    }

    /**
     * @return array
     */
    public function toArray(): array
    {
        return [
            'id' => $this->getId(),
            'username' => $this->credentials['username'],
            'accessLevel' => $this->accessLevel->getValue(),
        ];
    }
}